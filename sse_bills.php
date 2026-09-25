<?php
// sse_bills.php — Server-Sent Events for live bill inserts AND edits.
//
// Watermarks:
//   - CreateDate      → detects new bills
//   - LastUpdateDate  → detects edits (Alameen writes this on save)
//
// Probe is two MAX() aggregates (one index seek when CreateDate is indexed;
// a table scan otherwise, which we've measured at ~9 ms). The full join is
// only executed when a watermark actually moved.

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

while (ob_get_level() > 0) ob_end_clean();
ob_implicit_flush(true);

// Do NOT ignore_user_abort here. Combined with an infinite loop it is how
// orphaned PHP processes (each holding a SQL connection) accumulate when a
// client disappears behind a buffering proxy. Let PHP tear the script down.
ignore_user_abort(false);
set_time_limit(0);

require_once 'config.php';
require_once 'auth.php';
requireLogin(true);   // also closes the session so it is not locked for hours

// ─── Stream lifetime ─────────────────────────────────────────────
// Hard cap on how long one stream lives. EventSource reconnects on its own,
// so this costs nothing and guarantees a worker + SQL connection is always
// eventually released, even if abort detection fails.
const MAX_STREAM_SECONDS = 1800;   // 30 minutes
const FETCH_LIMIT        = 500;    // rows per batch
const POLL_INTERVAL_MS   = 2000;
const HEARTBEAT_SECONDS  = 15;
$startedAt = time();

// ─── Read params ─────────────────────────────────────────────────
$lastCreate = isset($_GET['lastCreate']) ? $_GET['lastCreate'] : null;
$lastUpdate = isset($_GET['lastUpdate']) ? $_GET['lastUpdate'] : null;

$fCustomer = (isset($_GET['customer']) && $_GET['customer'] !== '') ? $_GET['customer'] : null;
$fSalesman = (isset($_GET['salesman']) && $_GET['salesman'] !== '') ? $_GET['salesman'] : null;
$fDateFrom = (isset($_GET['dateFrom']) && $_GET['dateFrom'] !== '') ? $_GET['dateFrom'] : null;
$fDateTo   = (isset($_GET['dateTo'])   && $_GET['dateTo']   !== '') ? $_GET['dateTo']   : null;
$fSearch   = (isset($_GET['search'])   && $_GET['search']   !== '') ? trim($_GET['search']) : null;

// ─── Filter fragment ─────────────────────────────────────────────
$filterSql    = [];
$filterParams = [];
if ($fCustomer !== null) { $filterSql[] = "b.Cust_Name = :fCustomer";  $filterParams[':fCustomer'] = $fCustomer; }
if ($fSalesman !== null) { $filterSql[] = "b.StoreGUID = :fSalesman";  $filterParams[':fSalesman'] = $fSalesman; }
if ($fDateFrom !== null) { $filterSql[] = "b.Date >= :fDateFrom";      $filterParams[':fDateFrom'] = $fDateFrom . ' 00:00:00'; }
if ($fDateTo   !== null) { $filterSql[] = "b.Date < DATEADD(day, 1, :fDateTo)"; $filterParams[':fDateTo'] = $fDateTo . ' 00:00:00'; }
if ($fSearch !== null) {
    // Two distinct placeholders: SQLSRV native prepares do not allow
    // reusing the same named parameter in the same statement.
    $filterSql[] = "(b.Number LIKE :fSearch1 OR b.Cust_Name LIKE :fSearch2)";
    $filterParams[':fSearch1'] = '%' . $fSearch . '%';
    $filterParams[':fSearch2'] = '%' . $fSearch . '%';
}
$filterWhere = count($filterSql) ? (' AND ' . implode(' AND ', $filterSql)) : '';

// ─── Normalize incoming watermarks ───────────────────────────────
// sqlWatermark() (lib.php) converts the browser's ISO-8601 into the DB
// server's local wall-clock form. The previous version formatted the string
// without converting the timezone, so a UTC watermark from the browser was
// compared against local-time DB values and was wrong by the UTC offset.
$nowSql = (new DateTime())->format(SQL_FMT);
$lastCreateSql = sqlWatermark($lastCreate, $nowSql);
$lastUpdateSql = sqlWatermark($lastUpdate, $nowSql);

// ─── Connect ─────────────────────────────────────────────────────
try {
    $pdo = getDBConnection();
} catch (Throwable $e) {
    error_log('[AmeenBill SSE] ' . $e->getMessage());
    echo "event: error\ndata: " . json_encode(['error' => 'database unavailable']) . "\n\n";
    flush();
    exit;
}

// ─── Probe: both watermarks in one round-trip ────────────────────
$probeStmt = $pdo->prepare(
    "SELECT
        MAX(CreateDate)     AS maxCreate,
        MAX(LastUpdateDate) AS maxUpdate
     FROM bu000"
);

// ─── Fetch: bills matching either watermark ──────────────────────
$fetchStmt = $pdo->prepare(
    "SELECT TOP " . (FETCH_LIMIT + 1) . "
        b.GUID,
        b.Number,
        b.Cust_Name,
        b.Date,
        b.PayType,
        b.Total,
        b.TotalDisc,
        b.TotalExtra,
        b.CurrencyVal,
        cur.Name AS CurrencyName,
        s.Name   AS StoreName,
        cc.Name  AS CostCenterName,
        b.CreateDate,
        b.LastUpdateDate
     FROM bu000 b
     LEFT JOIN my000 cur ON b.CurrencyGUID = cur.GUID
     LEFT JOIN st000 s   ON b.StoreGUID   = s.GUID
     LEFT JOIN co000 cc  ON b.CostGUID    = cc.GUID
     WHERE (b.CreateDate > :lastCreate OR b.LastUpdateDate > :lastUpdate)"
     . $filterWhere . "
     ORDER BY b.CreateDate ASC"
);

echo ": connected\n\n";
flush();

$lastHeartbeat = time();

while (true) {
    if (connection_aborted()) break;
    if ((time() - $startedAt) >= MAX_STREAM_SECONDS) {
        // Tell the client why, then exit cleanly. EventSource reconnects.
        echo ": stream recycled\n\n";
        flush();
        break;
    }

    try {
        // 1. Cheap probe
                $probeStmt->execute();
        $row = $probeStmt->fetch(PDO::FETCH_ASSOC);

        $maxCreate = ($row && $row['maxCreate'])
            ? (new DateTime($row['maxCreate']))->format('Y-m-d H:i:s.v')
            : SQL_SENTINEL;
        $maxUpdate = ($row && $row['maxUpdate'])
            ? (new DateTime($row['maxUpdate']))->format('Y-m-d H:i:s.v')
            : SQL_SENTINEL;

        $hasNew    = strcmp($maxCreate, $lastCreateSql) > 0;
        $hasUpdate = strcmp($maxUpdate, $lastUpdateSql) > 0
                     && strcmp($maxUpdate, SQL_SENTINEL) > 0;

        // 2. Full fetch only when a watermark moved
        if ($hasNew || $hasUpdate) {
            $params = array_merge([
                ':lastCreate' => $lastCreateSql,
                ':lastUpdate' => $lastUpdateSql,
            ], $filterParams);

            $fetchStmt->execute($params);
            $bills = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);

            // We asked for FETCH_LIMIT + 1 rows. If we got them all, the
            // batch was truncated and more rows are waiting — in that case we
            // must NOT jump the watermark past the global max below.
            $truncated = count($bills) > FETCH_LIMIT;
            if ($truncated) {
                array_pop($bills);
            }

            if (count($bills) > 0) {
                $newCreateMax = $lastCreateSql;
                $newUpdateMax = $lastUpdateSql;

                foreach ($bills as &$b) {
                    // Normalize raw SQL-format timestamps FIRST, preserving ms.
                    // sqlStamp() returns null on NULL/malformed values instead
                    // of throwing — one bad row must not kill the stream.
                    $rawCreate = sqlStamp($b['CreateDate'] ?? null);
                    $rawUpdate = sqlStamp($b['LastUpdateDate'] ?? null);

                    // Advance watermarks on the SQL-format strings (ms-safe)
                    if ($rawCreate && strcmp($rawCreate, $newCreateMax) > 0) {
                        $newCreateMax = $rawCreate;
                    }
                    if ($rawUpdate
                        && strcmp($rawUpdate, SQL_SENTINEL) > 0
                        && strcmp($rawUpdate, $newUpdateMax) > 0) {
                        $newUpdateMax = $rawUpdate;
                    }

                    // Now convert to ISO for the client
                    $b['Date']           = isoStamp($b['Date'] ?? null);
                    $b['CreateDate']     = isoStamp($rawCreate);
                    $b['LastUpdateDate'] = isoStamp($rawUpdate, true);
                }
                unset($b);

                echo "event: bills\n";
                echo 'data: ' . json_encode(
                    $bills,
                    JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                ) . "\n\n";
                flush();

                // Advance watermarks in SQL format (ms-safe)
                $lastCreateSql = $newCreateMax;
                $lastUpdateSql = $newUpdateMax;
                $lastHeartbeat = time();

                // When a filter is active, bills that do NOT match still move
                // the global max. Previously the watermark only advanced to
                // the newest MATCHING row, so every probe kept firing the full
                // join for as long as a newer non-matching bill existed — the
                // "cheap probe" optimisation switched itself off exactly when
                // filters were in use. Skip the jump if the batch was cut off.
                if (!$truncated) {
                    if (strcmp($maxCreate, $lastCreateSql) > 0) {
                        $lastCreateSql = $maxCreate;
                    }
                    if (strcmp($maxUpdate, SQL_SENTINEL) > 0
                        && strcmp($maxUpdate, $lastUpdateSql) > 0) {
                        $lastUpdateSql = $maxUpdate;
                    }
                }
            } else {
                // Filter matched nothing; jump past the global max so we don't loop
                $lastCreateSql = $maxCreate;
                if (strcmp($maxUpdate, SQL_SENTINEL) > 0) {
                    $lastUpdateSql = $maxUpdate;
                }
            }
        }

        // 3. Heartbeat (also how a dropped connection gets detected)
        if (time() - $lastHeartbeat >= HEARTBEAT_SECONDS) {
            echo ": heartbeat\n\n";
            flush();
            $lastHeartbeat = time();
        }
    } catch (Throwable $e) {
        error_log('[AmeenBill SSE] ' . $e->getMessage());
        echo "event: error\ndata: " . json_encode(['error' => 'stream error']) . "\n\n";
        flush();
        break;
    }

    // 4. Abort-aware sleep
    for ($i = 0; $i < POLL_INTERVAL_MS / 100; $i++) {
        usleep(100000);
        if (connection_aborted()) break 2;
    }
}