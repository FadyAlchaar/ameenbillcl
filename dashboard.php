<?php
// index.php - live sales dashboard with a draggable splitter between the
// bills list and the bill details.
require_once 'config.php';
require_once 'auth.php';

// Every branch below is behind the login gate. JSON branches answer 401
// instead of redirecting so the client can show a sensible message.
$isApiRequest = isset($_GET['filters']) || isset($_GET['ajax'])
             || isset($_GET['details']) || isset($_GET['serverTime']);
requireLogin($isApiRequest);

// Server clock, so the browser never seeds a watermark from its own clock.
if (isset($_GET['serverTime'])) {
    header('Content-Type: application/json');
    echo json_encode(['now' => (new DateTime())->format(ISO_FMT)]);
    exit;
}

// AJAX handler to populate filter dropdowns (Customer, Salesman)
if (isset($_GET['filters']) && $_GET['filters'] == 1) {
    header('Content-Type: application/json');

    // These two DISTINCT queries scan bu000, so cache the result briefly.
    // The dropdown contents barely change minute to minute.
    $cacheFile = __DIR__ . '/cache/filters.json';
    $cacheTtl  = 300; // 5 minutes
    if (is_readable($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
        echo file_get_contents($cacheFile);
        exit;
    }

    try {
        $pdo = getDBConnection();

        $custStmt = $pdo->query("
            SELECT DISTINCT Cust_Name
            FROM bu000
            WHERE Cust_Name IS NOT NULL AND LTRIM(RTRIM(Cust_Name)) <> ''
            ORDER BY Cust_Name
        ");
        $customers = $custStmt->fetchAll(PDO::FETCH_COLUMN);

        $salesStmt = $pdo->query("
            SELECT DISTINCT
                ds.stGuid AS GUID,
                ds.Name AS DisplayName
            FROM bu000 b
            JOIN DistDeviceST000 ds ON b.StoreGUID = ds.stGuid
            WHERE ds.Name IS NOT NULL AND LTRIM(RTRIM(ds.Name)) <> ''
            ORDER BY DisplayName
        ");
        $salesmen = $salesStmt->fetchAll(PDO::FETCH_ASSOC);

        $payload = json_encode(
            ['customers' => $customers, 'salesmen' => $salesmen],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if (!is_dir(dirname($cacheFile))) {
            @mkdir(dirname($cacheFile), 0770, true);
        }
        // Write atomically so a concurrent reader never sees a partial file.
        $tmp = $cacheFile . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $payload) !== false) {
            @rename($tmp, $cacheFile);
        }

        echo $payload;
    } catch (Throwable $e) {
        jsonFail($e, 500, 'Could not load filter options.');
    }
    exit;
}

// AJAX handler for bills list
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    header('Content-Type: application/json');
    $loadMore = isset($_GET['loadMore']) ? (int)$_GET['loadMore'] : 0;

    // ---- Filter inputs ----
    $fCustomer = isset($_GET['customer']) && $_GET['customer'] !== '' ? $_GET['customer'] : null;
    $fSalesman = isset($_GET['salesman']) && $_GET['salesman'] !== '' ? $_GET['salesman'] : null;
    $fDateFrom = isset($_GET['dateFrom']) && $_GET['dateFrom'] !== '' ? $_GET['dateFrom'] : null;
    $fDateTo   = isset($_GET['dateTo']) && $_GET['dateTo'] !== '' ? $_GET['dateTo'] : null;

        // ---- Delta watermarks (optional) ----
    $sinceCreate = isset($_GET['sinceCreate']) && $_GET['sinceCreate'] !== '' ? $_GET['sinceCreate'] : null;
    $sinceUpdate = isset($_GET['sinceUpdate']) && $_GET['sinceUpdate'] !== '' ? $_GET['sinceUpdate'] : null;

    // ---- Free-text search (bill number OR customer name) ----
    $search = isset($_GET['search']) && $_GET['search'] !== '' ? trim($_GET['search']) : null;

    // Build filter fragments
    $filterSql = [];
    $filterParams = [];
    if ($fCustomer !== null) {
        $filterSql[] = "b.Cust_Name = :fCustomer";
        $filterParams[':fCustomer'] = $fCustomer;
    }
    if ($fSalesman !== null) {
        $filterSql[] = "b.StoreGUID = :fSalesman";
        $filterParams[':fSalesman'] = $fSalesman;
    }
    if ($fDateFrom !== null) {
        $filterSql[] = "b.Date >= :fDateFrom";
        $filterParams[':fDateFrom'] = $fDateFrom . ' 00:00:00';
    }
        if ($fDateTo !== null) {
        $filterSql[] = "b.Date < DATEADD(day, 1, :fDateTo)";
        $filterParams[':fDateTo'] = $fDateTo . ' 00:00:00';
    }
    if ($search !== null) {
        // Two distinct placeholders: SQLSRV native prepares do not allow
        // reusing the same named parameter in the same statement.
        $filterSql[] = "(b.Number LIKE :search1 OR b.Cust_Name LIKE :search2)";
        $filterParams[':search1'] = '%' . $search . '%';
        $filterParams[':search2'] = '%' . $search . '%';
    }

    $selectCols = "
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
        s.Name AS StoreName,
        cc.Name AS CostCenterName,
        b.CreateDate,
        b.LastUpdateDate
    ";

    try {
        $pdo = getDBConnection();

        // ── Delta mode: bills created OR edited since the given watermarks ──
        if ($sinceCreate !== null || $sinceUpdate !== null) {
            // sqlWatermark() converts the browser's ISO-8601 (UTC) into the
            // DB server's local wall-clock form. Comparing the two directly
            // was off by the UTC offset.
            $sinceCreateSql = sqlWatermark($sinceCreate, SQL_SENTINEL);
            $sinceUpdateSql = sqlWatermark($sinceUpdate, SQL_SENTINEL);

            $where = ["(b.CreateDate > :sinceCreate OR b.LastUpdateDate > :sinceUpdate)"];
            $where = array_merge($where, $filterSql);
            $whereSql = implode(' AND ', $where);

            $sql = "SELECT TOP 500 $selectCols
                    FROM bu000 b
                    LEFT JOIN my000 cur ON b.CurrencyGUID = cur.GUID
                    LEFT JOIN st000 s   ON b.StoreGUID   = s.GUID
                    LEFT JOIN co000 cc  ON b.CostGUID    = cc.GUID
                    WHERE $whereSql
                    ORDER BY b.CreateDate ASC";
            $stmt = $pdo->prepare($sql);
            $params = array_merge([
                ':sinceCreate' => $sinceCreateSql,
                ':sinceUpdate' => $sinceUpdateSql,
            ], $filterParams);
            $stmt->execute($params);

            $bills = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // normalizeBillDates() returns null for NULL/malformed values
            // instead of throwing — one bad row must not fail the response.
            foreach ($bills as &$bill) {
                normalizeBillDates($bill);
            }
            unset($bill);
            echo json_encode($bills, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        // ── Reconcile: return just the GUIDs currently in the window, so
        //    the client can drop cards for bills that were deleted/voided.
        //    The comment "deletes by reconciliation" promised this but no
        //    implementation existed, so voided bills stayed on screen.
        if (isset($_GET['reconcile']) && $_GET['reconcile'] == 1) {
            $want = isset($_GET['count']) ? (int)$_GET['count'] : 200;
            $want = max(1, min($want + 5, 5000));   // small margin + hard cap

            $whereSql = count($filterSql) ? ('WHERE ' . implode(' AND ', $filterSql)) : '';
            $sql = "SELECT TOP (:want) b.GUID, b.CreateDate
                    FROM bu000 b
                    $whereSql
                    ORDER BY b.CreateDate DESC";
            $stmt = $pdo->prepare($sql);
            foreach ($filterParams as $key => $val) {
                $stmt->bindValue($key, $val);
            }
            $stmt->bindValue(':want', $want, PDO::PARAM_INT);
            $stmt->execute();

            $guids  = [];
            $oldest = null;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $guids[] = $row['GUID'];
                $oldest  = $row['CreateDate'];   // last row = oldest (DESC)
            }

            echo json_encode([
                'guids'  => $guids,
                // Cards older than this are outside the window we just read,
                // so the client must not treat their absence as a delete.
                'oldest' => isoStamp($oldest),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ── Initial load OR Load More ─────────────────────────────
        $limit  = 200;
        $offset = $loadMore * $limit;
        $whereSql = count($filterSql) ? ('WHERE ' . implode(' AND ', $filterSql)) : '';

        $sql = "SELECT $selectCols
                FROM bu000 b
                LEFT JOIN my000 cur ON b.CurrencyGUID = cur.GUID
                LEFT JOIN st000 s ON b.StoreGUID = s.GUID
                LEFT JOIN co000 cc ON b.CostGUID = cc.GUID
                $whereSql
                ORDER BY b.CreateDate DESC
                OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY";
        $stmt = $pdo->prepare($sql);
        foreach ($filterParams as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->execute();

        $bills = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($bills as &$bill) {
            normalizeBillDates($bill);
        }
        unset($bill);
        echo json_encode($bills, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        jsonFail($e, 500, 'Could not load bills.');
    }
    exit;
}

// AJAX handler for bill details
if (isset($_GET['details']) && $_GET['details'] == 1 && isset($_GET['guid'])) {
    header('Content-Type: application/json');
    $guid = $_GET['guid'];

    // Basic UUID validation to avoid malformed input reaching SQL
    if (!preg_match('/^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/', $guid)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid GUID']);
        exit;
    }

    try {
        $pdo = getDBConnection();

        // Reusable factor expression: convert stored base-unit Qty -> displayed Qty
        $dispQty = "CASE d.Unity
                        WHEN 2 THEN d.Qty / NULLIF(m.Unit2Fact, 0)
                        WHEN 3 THEN d.Qty / NULLIF(m.Unit3Fact, 0)
                        ELSE d.Qty
                    END";
        $dispUnit = "CASE d.Unity
                        WHEN 2 THEN m.Unit2
                        WHEN 3 THEN m.Unit3
                        ELSE m.Unity
                     END";

                             // Bonus quantity is stored in base units, same as Qty. Divide by
        // the same factor to display it in the bill's unit (e.g. 1 base
        // piece of a 12-per-box item shows as 0.08 box).
        $dispBonus = "CASE d.Unity
                        WHEN 2 THEN d.BonusQnt / NULLIF(m.Unit2Fact, 0)
                        WHEN 3 THEN d.BonusQnt / NULLIF(m.Unit3Fact, 0)
                        ELSE d.BonusQnt
                      END";

        $sql = "SELECT
                    m.Name AS ItemName,
                    d.Price AS UnitPrice,
                    d.Extra AS Extra,
                    d.Discount AS DiscountValue,
                    d.CurrencyVal,
                    $dispQty AS Qty,
                    $dispBonus AS BonusQty,
                    $dispUnit AS Unit,
                    ($dispQty) * d.Price AS Total,
                    ($dispQty) * d.Price - d.Discount + d.Extra AS Net
                FROM bi000 d
                LEFT JOIN mt000 m ON d.MatGUID = m.GUID
                WHERE d.ParentGUID = :guid
                ORDER BY d.GUID";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':guid' => $guid]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Header totals so the UI can show the authoritative figures
        $hdrSql = "SELECT
                       b.GUID,
                       b.Number,
                       b.Cust_Name,
                       b.CustGUID,
                       b.Date,
                       b.PayType,
                       b.Total,
                       b.TotalDisc,
                       b.TotalExtra,
                       b.ItemsDisc,
                       b.BonusDisc,
                       b.VAT,
                       b.CurrencyVal,
                       b.CreateDate,
                       cur.Name AS CurrencyName,
                       s.Name   AS StoreName,
                       cc.Name  AS CostCenterName
                   FROM bu000 b
                   LEFT JOIN my000 cur ON b.CurrencyGUID = cur.GUID
                   LEFT JOIN st000  s   ON b.StoreGUID   = s.GUID
                   LEFT JOIN co000  cc  ON b.CostGUID    = cc.GUID
                   WHERE b.GUID = :guid";
        $hdrStmt = $pdo->prepare($hdrSql);
        $hdrStmt->execute([':guid' => $guid]);
        $header = $hdrStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($header) {
            $header['Date']       = isoStamp($header['Date'] ?? null);
            $header['CreateDate'] = isoStamp($header['CreateDate'] ?? null);
        }

        echo json_encode(
            ['items' => $items, 'header' => $header],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    } catch (Throwable $e) {
        jsonFail($e, 500, 'Could not load bill details.');
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>QuantuSphere Web - تحديث مباشر</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;900&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap" rel="stylesheet">
    <!-- Local icon font — Tabler Icons (MIT) -->
    <link rel="stylesheet" href="assets/icons/tabler/tabler-icons.min.css">
    <script>
        // Runs before the stylesheet paints, so a saved theme choice applies
        // immediately instead of flashing the default and then switching.
        (function () {
            try {
                var saved = localStorage.getItem('dashboardTheme');
                if (saved === 'light') saved = 'warm';   // legacy value from the 2-theme version
                if (saved === 'warm' || saved === 'bright' || saved === 'dark' || saved === 'classic') {
                    document.documentElement.setAttribute('data-theme', saved);
                }
            } catch (e) { /* localStorage unavailable — fall back to system preference */ }
        })();
    </script>
    <style>
        * { box-sizing: border-box; }
        :root {
            /* Ledger palette: ink navy + aged brass on warm parchment, in
               place of the old indigo/SaaS-card theme. */
            --primary: #B8863C;         /* brass — money, live status, emphasis */
            --primary-dark: #96692C;
            --primary-light: rgba(184,134,60,0.10);
            --accent: #2F4C3B;          /* bottle green — secondary/positive */
            --success: #2F4C3B;
            --danger: #A23B2E;          /* muted brick — discounts, errors, deletes */
            --bg: #F6F1E4;              /* warm parchment */
            --surface: #FBF8F0;         /* slightly lighter panel surface */
            --text: #1D2A35;            /* deep ink navy */
            --text-muted: #5B6672;
            --border: #C9C0AC;          /* warm rule-grey, not cool grey */
            --radius: 6px;              /* ledger lines, not rounded SaaS cards */
            --shadow-sm: none;
            --shadow-md: 0 2px 8px rgba(29,42,53,0.08);
            --font-num: 'Fraunces', Georgia, serif;

            /* The masthead bars stay a fixed dark ink band in both themes —
               deliberately NOT swapped by the dark-mode blocks below, so the
               header doesn't invert into a light bar on a dark page. */
            --header-bg: #1D2A35;
            --header-fg: #F6F1E4;

            color-scheme: light;
        }

        /* Three themes total: "warm" (the :root defaults above — parchment
           ledger), "bright" (crisp white/cool-grey, same brass accent), and
           "dark". With no explicit choice saved, follow the OS preference
           (light → warm, dark → dark); "bright" is opt-in only, since there
           is no OS-level equivalent to detect it from. */
        @media (prefers-color-scheme: dark) {
            :root:not([data-theme]) {
                --primary: #D9A855;
                --primary-dark: #B8863C;
                --primary-light: rgba(217,168,85,0.16);
                --accent: #4C7B60;
                --success: #4C7B60;
                --danger: #C96354;
                --bg: #12181F;
                --surface: #1B232C;
                --text: #EDE6D6;
                --text-muted: #8C97A3;
                --border: #2B3542;
                --shadow-sm: none;
                --shadow-md: 0 2px 10px rgba(0,0,0,0.4);
                color-scheme: dark;
            }
        }
        :root[data-theme="dark"] {
            --primary: #D9A855;
            --primary-dark: #B8863C;
            --primary-light: rgba(217,168,85,0.16);
            --accent: #4C7B60;
            --success: #4C7B60;
            --danger: #C96354;
            --bg: #12181F;
            --surface: #1B232C;
            --text: #EDE6D6;
            --text-muted: #8C97A3;
            --border: #2B3542;
            --shadow-sm: none;
            --shadow-md: 0 2px 10px rgba(0,0,0,0.4);
            color-scheme: dark;
        }
        :root[data-theme="bright"] {
            /* Same ink and brass as the warm theme — only the surfaces move
               from parchment/tan to white/cool-grey, which is what actually
               reads as "brighter" in daylight. A whisper of shadow replaces
               the flat ledger look with a slightly crisper, more defined one. */
            --primary: #B8863C;
            --primary-dark: #96692C;
            --primary-light: rgba(184,134,60,0.08);
            --accent: #2F4C3B;
            --success: #2F4C3B;
            --danger: #A23B2E;
            --bg: #FFFFFF;
            --surface: #FFFFFF;
            --text: #1D2A35;
            --text-muted: #5B6672;
            --border: #E2E5E9;
            --shadow-sm: 0 1px 3px rgba(20,32,43,0.06);
            --shadow-md: 0 4px 14px rgba(20,32,43,0.08);
            color-scheme: light;
        }
        :root[data-theme="classic"] {
            /* The original theme this dashboard shipped with, kept available
               for customers already used to it. Token values only — the
               structural differences (gradients, filled pill badges, dashed
               vs solid rules, etc.) are handled by the scoped overrides
               further down, since those aren't things a token swap alone
               can reproduce. */
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --primary-light: #eef2ff;
            --accent: #06b6d4;
            --success: #10b981;
            --danger: #ef4444;
            --bg: #f4f5fa;
            --surface: #ffffff;
            --text: #1e1b2e;
            --text-muted: #6b7280;
            --border: #e5e7eb;
            --radius: 14px;
            --shadow-sm: 0 1px 2px rgba(16,24,40,0.06), 0 1px 3px rgba(16,24,40,0.08);
            --shadow-md: 0 4px 10px rgba(16,24,40,0.06), 0 2px 4px rgba(16,24,40,0.06);
            color-scheme: light;
        }

        body, .bills-container, .details-container, .bill-card, .filters-bar,
        th, td, .ds-chip, input {
            transition: background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease;
        }
        html, body {
            height: 100%;
        }
        body {
            font-family: 'Tajawal', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--bg);
            margin: 0;
            padding: 16px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #C9C0AC; border-radius: 8px; }
        ::-webkit-scrollbar-thumb:hover { background: #B8863C; }
        .dashboard {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            height: 100%;
            width: 100%;
        }
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
            flex-shrink: 0;
            flex-wrap: wrap;
        }
        h1 {
            color: var(--text);
            font-size: 1.4rem;
            font-weight: 900;
            margin: 0;
            text-align: right;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        /* Topbar search box */
        .search-wrap {
            position: relative;
            flex: 1 1 220px;
            max-width: 380px;
            min-width: 140px;
        }
        .search-input {
            width: 100%;
            padding: 7px 14px 7px 34px;   /* LTR padding: left reserves room for the clear button */
            border: 1px solid var(--border);
            border-radius: 999px;
            background: var(--surface);
            color: var(--text);
            font-family: inherit;
            font-size: 0.8rem;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .search-input::placeholder { color: var(--text-muted); opacity: 1; }
        .search-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-light);
        }
        .search-clear {
            position: absolute;
            left: 8px;              /* the "end" in RTL layout */
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 0.95rem;
            font-family: inherit;
            cursor: pointer;
            padding: 2px 8px;
            line-height: 1;
            border-radius: 999px;
            transition: color 0.15s, background 0.15s;
        }
        .search-clear:hover {
            color: var(--danger);
            background: rgba(162,59,46,0.08);
        }

        .live-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--surface);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--text-muted);
        }
        .live-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--primary);   /* default: SSE-connected look before JS reports a state */
            animation: livePulse 1.8s ease-in-out infinite;
        }
        @keyframes livePulse {
            0%, 100% { opacity: 1; }
            50%      { opacity: 0.35; }
        }
        .live-dot.state-live     { background: var(--primary); animation: livePulse 1.8s ease-in-out infinite; }
        .live-dot.state-fallback { background: var(--text-muted); animation: livePulse 1.8s ease-in-out infinite; }
        .live-dot.state-down     { background: var(--danger); animation: none; }
        .topbar-start {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        /* The h1 sits inside .topbar-start now; neutralize any default
           margins the standalone version relied on. */
        .topbar-start h1 { margin: 0; }


        .topbar-right {
            display: flex;
            align-items: center;
            gap: 8px;
        }
                /* ---------- Hamburger menu ---------- */
        .menu-wrap {
            position: relative;
        }
        .menu-toggle-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text);
            font-size: 1.05rem;
            font-family: inherit;
            cursor: pointer;
            transition: all 0.15s;
        }
        .menu-toggle-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        .menu-toggle-btn.open {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }
        .menu-dropdown {
            display: none;
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            /* width: max-content shrinks the panel to the widest child.
               No min-width, so a short menu is a short panel — the old
               260px floor forced unnecessary whitespace. */
            width: max-content;
            max-width: calc(100vw - 24px);
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: 0 10px 30px rgba(29,42,53,0.15);
            padding: 6px;
            z-index: 300;
        }
        /* Ensure the menu items don't stretch past their text — without
           this, a wide section title would still push the whole panel. */
        .menu-dropdown .menu-item,
        .menu-dropdown .menu-section-title {
            white-space: nowrap;
        }

        .menu-dropdown.open {
            display: block;
            animation: menuFade 0.12s ease-out;
        }
        @keyframes menuFade {
            from { opacity: 0; transform: translateY(-4px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .menu-section {
            padding: 4px 0;
        }
        .menu-section + .menu-section {
            border-top: 1px solid var(--border);
            margin-top: 4px;
            padding-top: 8px;
        }
        .menu-section-title {
            font-size: 0.68rem;
            color: var(--text-muted);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            padding: 4px 12px 6px;
        }
        .menu-item {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            padding: 9px 12px;
            background: none;
            border: none;
            border-radius: 8px;
            color: var(--text);
            font-family: inherit;
            font-size: 0.85rem;
            font-weight: 600;
            text-align: right;
            text-decoration: none;
            cursor: pointer;
            transition: background 0.12s;
        }
        .menu-item:hover {
            background: var(--primary-light);
        }
        .menu-item-icon {
            font-size: 1rem;
            width: 20px;
            text-align: center;
            flex-shrink: 0;
        }
        .menu-item-danger {
            color: var(--danger);
        }
        .menu-item-danger:hover {
            background: rgba(162,59,46,0.08);
        }
        @media (max-width: 600px) {
            .menu-dropdown {
                min-width: 240px;
            }
        }

        .filters-toggle-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--surface);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--primary);
            font-family: inherit;
            cursor: pointer;
        }
        .filters-toggle-btn.active {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }
        .filters-toggle-badge {
            background: var(--primary);
            color: white;
            font-size: 0.65rem;
            font-weight: 800;
            border-radius: 999px;
            padding: 1px 6px;
            display: none;
        }
        .filters-toggle-btn.active .filters-toggle-badge { background: rgba(255,255,255,0.25); }
        /* Filter bar */
        .filters-bar {
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border);
            padding: 12px 16px;
            margin-bottom: 14px;
            display: none;              /* hidden by default; .filters-open shows it */
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            flex-shrink: 0;
        }
        .filters-bar.filters-open {
            display: flex;
            animation: filtersSlideDown 0.18s ease-out;
        }
        @keyframes filtersSlideDown {
            from { opacity: 0; transform: translateY(-6px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .filters-bar label {
            font-size: 0.75rem;
            color: var(--text-muted);
            font-weight: 700;
            margin-left: 2px;
        }
        .filters-bar input[type="date"],
        .ss-input {
            padding: 7px 10px;
            border: 1px solid var(--border);
            border-radius: 9px;
            font-size: 0.8rem;
            font-family: inherit;
            background: var(--bg);
            color: var(--text);
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .filters-bar input[type="date"]:focus,
        .ss-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-light);
        }
        .filter-group {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .filter-divider {
            width: 1px;
            align-self: stretch;
            background: var(--border);
            margin: 2px 0;
        }
        /* Searchable dropdown */
        .ss-wrap {
            position: relative;
            width: 170px;
        }
        .ss-input {
            width: 100%;
            cursor: pointer;
        }
        .ss-input::placeholder { color: var(--text-muted); opacity: 1; }
        .ss-panel {
            display: none;
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            left: 0;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            box-shadow: var(--shadow-md);
            max-height: 260px;
            overflow-y: auto;
            z-index: 50;
            padding: 4px;
        }
        .ss-wrap.open .ss-panel { display: block; }
        .ss-option {
            padding: 8px 10px;
            border-radius: 7px;
            font-size: 0.8rem;
            cursor: pointer;
            text-align: right;
            color: var(--text);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .ss-option:hover { background: var(--primary-light); }
        .ss-option.selected { background: var(--primary); color: white; font-weight: 700; }
        .ss-option-all { color: var(--text-muted); font-weight: 600; border-bottom: 1px solid var(--border); border-radius: 0; margin-bottom: 3px; padding-bottom: 8px; }
        .ss-option-all.selected { background: var(--primary); color: white; border-radius: 7px; }
        .ss-empty { padding: 10px; text-align: center; font-size: 0.75rem; color: var(--text-muted); }
        .preset-btn {
            padding: 7px 14px;
            border: 1px solid var(--border);
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
            background: var(--bg);
            cursor: pointer;
            color: var(--text-muted);
            font-family: inherit;
            transition: all 0.15s;
        }
        .preset-btn:hover { border-color: var(--primary); color: var(--primary); }
        .preset-btn.active {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }
        .filters-bar .clear-btn {
            padding: 7px 14px;
            border-radius: var(--radius);
            font-size: 0.75rem;
            background: none;
            color: var(--danger);
            border: 1px solid var(--danger);
            cursor: pointer;
            font-weight: 700;
            font-family: inherit;
            transition: all 0.15s;
            margin-right: auto;
        }
        .filters-bar .clear-btn:hover { background: rgba(162,59,46,0.08); }
        /* Splitter container */
        .split-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }
        /* Bills section (upper) */
        .bills-container {
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            min-height: 100px;
        }
        .bills-header {
            background: var(--header-bg);
            color: var(--header-fg);
            padding: 12px 18px;
            font-weight: 700;
            font-size: 0.95rem;
            text-align: right;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2px solid var(--primary);
        }
        .export-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.25);
            color: var(--header-fg);
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
            transition: background 0.15s;
        }
        .export-btn:hover { background: rgba(255,255,255,0.18); }
        .export-btn:disabled { opacity: 0.4; cursor: not-allowed; }
        @media (max-width: 500px) {
            .export-btn-label { display: none; }
            .export-btn { padding: 4px 7px; }
        }
        .bill-count-pill {
            background: rgba(255,255,255,0.2);
            padding: 2px 10px;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 700;
        }
        #bills {
            overflow-y: auto;
            flex: 1;
        }
        .bill-card {
            background: var(--surface);
            border-right: 3px solid transparent;   /* only lit for the active row */
            padding: 10px 16px;
            cursor: pointer;
            transition: background 0.15s;
            border-bottom: 1px solid var(--border);
            text-align: right;
        }
        .bill-card:hover { background: var(--primary-light); }
        .bill-card.active { background: var(--primary-light); border-right-color: var(--primary); }
        .bill-card.new-flash {
            animation: newBillFlash 2.2s ease-out;
        }
        @keyframes newBillFlash {
            0%   { background: rgba(184,134,60,0.22); }
            100% { background: var(--surface); }
        }
        .bill-title {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            gap: 8px 12px;
            margin-bottom: 5px;
            justify-content: flex-start;
        }
        .bill-number {
            color: var(--text-muted);
            font-size: 0.75rem;
            font-weight: 700;
            font-family: var(--font-num);
            font-variant-numeric: tabular-nums;
        }
        .bill-customer { font-weight: 700; font-size: 0.9rem; color: var(--text); }
        .bill-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            font-size: 0.7rem;
            color: var(--text-muted);
            justify-content: flex-start;
        }
        .bill-meta span {
            padding: 0;
        }
        .bill-meta span:not(:last-child)::after {
            content: '\00B7';
            margin: 0 6px;
            color: var(--border);
        }
        .load-more-btn {
            text-align: center;
            padding: 11px;
            background: var(--bg);
            cursor: pointer;
            color: var(--primary-dark);
            font-weight: 700;
            font-size: 0.85rem;
            border-top: 1px solid var(--border);
            flex-shrink: 0;
            transition: background 0.15s;
        }
        .load-more-btn:hover {
            background: var(--primary-light);
        }

        /* Splitter handle */
        .splitter {
            background: var(--border);
            height: 6px;
            margin: 10px 0;
            cursor: row-resize;
            border-radius: 999px;
            transition: background 0.2s;
            flex-shrink: 0;
            position: relative;
        }
        .splitter::after {
            content: '';
            position: absolute;
            top: -6px; bottom: -6px; left: 0; right: 0;
        }
        .splitter:hover, .splitter.active {
            background: var(--primary);
        }

        /* Details section (lower) */
        .details-container {
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            min-height: 100px;
            flex: 1;
        }
        .details-header {
            background: var(--header-bg);
            color: var(--header-fg);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 18px;
            font-weight: 700;
            font-size: 0.95rem;
            text-align: right;
            flex-shrink: 0;
            border-bottom: 2px solid var(--primary);
        }
        .table-wrapper {
            overflow-x: auto;
            flex: 1;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.75rem;
        }
        th {
            background: var(--bg);
            color: var(--text);
            font-weight: 700;
            padding: 10px 10px;
            border-bottom: 1px solid var(--border);
            text-align: right;
            white-space: nowrap;
            position: sticky;
            top: 0;
        }
        td {
            padding: 8px 10px;
            border-bottom: 1px solid var(--border);
            text-align: right;
            color: var(--text);
        }
        /* Money columns (unit price, total, discount, extra, net) — set in the
           ledger numeral face with tabular figures, like a printed column. */
        td:nth-child(n+5), tr.totals-row td:nth-child(n+5) {
            font-family: var(--font-num);
            font-variant-numeric: tabular-nums;
        }
        tr:hover td { background: var(--primary-light); }
        td:first-child { font-weight: 600; color: var(--text-muted); }
        .loading, .error { padding: 24px; text-align: center; font-size: 0.85rem; }
        .error { color: var(--danger); background: rgba(162,59,46,0.08); border-radius: var(--radius); margin: 10px; }

        /* Details summary bar */
        .details-summary {
            display: flex;
            flex-wrap: wrap;
            gap: 6px 8px;
            padding: 10px 14px;
            background: var(--bg);
            border-bottom: 1px dashed var(--border);   /* the invoice tear-line */
            position: sticky;
            top: 0;
            z-index: 2;
        }
        .ds-chip {
            background: none;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 4px 10px;
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--text);
            white-space: nowrap;
            font-family: var(--font-num);
            font-variant-numeric: tabular-nums;
        }
        .ds-num   { background: var(--text); color: var(--bg); border-color: var(--text); font-family: inherit; }
        .ds-disc  { color: var(--danger); border-color: var(--danger); }
        .ds-extra { color: var(--primary-dark); border-color: var(--primary); }
        .ds-grand { color: var(--primary-dark); border-color: var(--primary); font-weight: 700; font-size: 0.8rem; }
        .ds-rate  { color: var(--text-muted); }

        /* Totals row in details table */
        tr.totals-row td {
            background: var(--bg);
            font-weight: 700;
            color: var(--text);
            border-top: 1px dashed var(--border);   /* the invoice tear-line */
            border-bottom: none;
            position: sticky;
            bottom: 0;
        }
        tr.totals-row td:last-child {
            color: var(--primary-dark);
            font-size: 1.05rem;
        }

        /* Highlighted net amount on each bill card */
        .bill-net {
            margin-right: auto;         /* pushes it to the far-left in RTL */
            background: none;
            border: none;
            color: var(--primary-dark);
            font-family: var(--font-num);
            font-variant-numeric: tabular-nums;
            font-weight: 600;
            font-size: 1.05rem;
            padding: 0;
            white-space: nowrap;
        }
        .bill-meta .meta-disc  { color: var(--danger); }
        .bill-meta .meta-extra { color: var(--primary-dark); }

        /* "Updated" pill that flashes in the details header on refresh */
        .details-header { position: relative; }
        .update-pill {
            /* Anchored under the title (right side, in RTL) rather than at
               the far edge, which the print button now occupies. */
            position: absolute;
            right: 14px;
            top: calc(100% + 8px);
            z-index: 5;
            background: var(--header-bg);
            color: var(--header-fg);
            font-size: 0.7rem;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 999px;
            box-shadow: var(--shadow-md);
            opacity: 0;
            transform: translateY(-6px);
            transition: opacity 0.25s, transform 0.25s;
            pointer-events: none;
        }
        .update-pill.show {
            opacity: 1;
            transform: translateY(0);
        }

        /* Amber glow when a bill card's data was just updated */
        .bill-card.card-updated {
            animation: cardUpdated 1.5s ease-out;
        }

        /* Mismatch warning on the summary bar */
        .ds-warn {
            background: none;
            color: var(--danger);
            border-color: var(--danger);
        }
        .details-summary.has-mismatch {
            background: rgba(162,59,46,0.06);
            border-bottom-color: var(--danger);
        }

        /* Explanatory banner when header doesn't match items */
        .mismatch-banner {
            background: rgba(162,59,46,0.06);
            border: 1px solid var(--danger);
            color: var(--danger);
            padding: 10px 14px;
            margin: 10px 14px 0;
            border-radius: var(--radius);
            font-size: 0.78rem;
            line-height: 1.6;
            text-align: right;
        }
        .mismatch-banner .mismatch-hint {
            color: var(--text-muted);
            font-size: 0.72rem;
        }

        /* Sound / notification toggle button */
        .sound-toggle-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--surface);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--text-muted);
            font-family: inherit;
            cursor: pointer;
            transition: all 0.15s;
        }
        .sound-toggle-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        .sound-toggle-btn.enabled {
            background: rgba(47,76,59,0.08);
            border-color: var(--accent);
            color: var(--accent);
        }
        .sound-toggle-btn .sound-toggle-label {
            font-size: 0.72rem;
        }
        @media (max-width: 600px) {
            .sound-toggle-btn .sound-toggle-label { display: none; }
            .sound-toggle-btn { padding: 6px 9px; }
            .nav-label { display: none; }
        }

        /* Dark-mode toggle — same shape as the sound toggle, for consistency */
        .theme-toggle-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--surface);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--text-muted);
            font-family: inherit;
            cursor: pointer;
            transition: all 0.15s;
        }
        .theme-toggle-btn:hover { border-color: var(--primary); color: var(--primary); }

                /* Customer-file button — mirrors the print button's look so the two
           sit comfortably side by side in the dark details masthead. */
        .customer-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.25);
            color: var(--header-fg);
            padding: 5px 11px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.15s;
        }
        .customer-btn:hover { background: rgba(255,255,255,0.18); }
        .customer-btn.disabled {
            opacity: 0.35;
            cursor: not-allowed;
            pointer-events: none;
        }
        @media (max-width: 600px) {
            .customer-btn .customer-btn-label { display: none; }
        }

        /* Print button lives inside the details header, so it needs to read
           against the fixed dark masthead in both themes. */
        .print-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.25);
            color: var(--header-fg);
            padding: 5px 11px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
            transition: background 0.15s;
        }
        .print-btn:hover:not(:disabled) { background: rgba(255,255,255,0.16); }
        .print-btn:disabled {
            opacity: 0.35;
            cursor: not-allowed;
        }
        @media (max-width: 600px) {
            .print-btn span.print-btn-label { display: none; }
        }

        @keyframes cardUpdated {
            0%   { background: rgba(184,134,60,0.22); }
            100% { background: var(--surface); }
        }
        .bill-card.active.card-updated {
            animation: cardUpdatedActive 1.5s ease-out;
        }
        @keyframes cardUpdatedActive {
            0%   { background: rgba(184,134,60,0.22); }
            100% { background: var(--primary-light); }
        }
                /* ---------- Keyboard shortcuts help overlay ---------- */
        .kbd-overlay {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 999;
            background: rgba(29,42,53,0.55);
            backdrop-filter: blur(3px);
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .kbd-overlay.open { display: flex; animation: kbdFade 0.15s ease-out; }
        @keyframes kbdFade {
            from { opacity: 0; }
            to   { opacity: 1; }
        }
        .kbd-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 20px 24px;
            max-width: 420px;
            width: 100%;
            box-shadow: 0 10px 40px rgba(0,0,0,0.25);
            animation: kbdSlide 0.18s ease-out;
        }
        @keyframes kbdSlide {
            from { transform: translateY(-8px); opacity: 0; }
            to   { transform: translateY(0);    opacity: 1; }
        }
        .kbd-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            font-weight: 700;
            font-size: 1rem;
            color: var(--text);
        }
        .kbd-close {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 1.1rem;
            cursor: pointer;
            padding: 2px 8px;
            border-radius: 6px;
            font-family: inherit;
        }
        .kbd-close:hover { color: var(--danger); background: rgba(162,59,46,0.08); }
        .kbd-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }
        .kbd-table td {
            padding: 8px 6px;
            border-bottom: 1px solid var(--border);
            color: var(--text);
            text-align: right;
        }
        .kbd-table tr:last-child td { border-bottom: none; }
        .kbd-table td:first-child {
            width: 130px;
            text-align: left;
            white-space: nowrap;
        }
        kbd {
            display: inline-block;
            background: var(--bg);
            border: 1px solid var(--border);
            border-bottom-width: 2px;
            border-radius: 5px;
            padding: 2px 8px;
            font-family: var(--font-num);
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--text);
            min-width: 18px;
            text-align: center;
        }

                /* ---------- TV / kiosk mode (?tv=1) ---------- */
        /* Hides everything that isn't the bill list, then scales what's left
           for reading from across a room. Only the sound/theme toggles and a
           small exit link survive. */
        body.tv-mode {
            padding: 10px;
        }
        body.tv-mode .search-wrap,
        body.tv-mode .filters-bar,
        body.tv-mode .filters-toggle-btn,
        body.tv-mode .export-btn,
        body.tv-mode .details-container,
        body.tv-mode .splitter {
            display: none !important;
        }
        body.tv-mode .split-container { gap: 0; }
        body.tv-mode .bills-container {
            /* height is set inline by the splitter JS; override it so bills
               take the full viewport height */
            height: auto !important;
            flex: 1;
            min-height: 0;
            border-radius: 10px;
        }
        body.tv-mode .bills-header {
            padding: 18px 26px;
            font-size: 1.3rem;
        }
        body.tv-mode .bill-count-pill {
            font-size: 0.95rem;
            padding: 4px 14px;
        }
        body.tv-mode .bill-card {
            padding: 20px 30px;
        }
        body.tv-mode .bill-title {
            gap: 14px 22px;
            margin-bottom: 8px;
        }
        body.tv-mode .bill-number {
            font-size: 1.05rem;
            padding: 4px 12px;
        }
        body.tv-mode .bill-customer {
            font-size: 1.4rem;
        }
        body.tv-mode .bill-net {
            font-size: 1.65rem;
        }
        body.tv-mode .bill-meta {
            gap: 10px;
            font-size: 0.98rem;
        }
        body.tv-mode .bill-meta span:not(:last-child)::after {
            margin: 0 12px;
        }
        /* Classic theme uses filled pill badges — scale those too */
        [data-theme="classic"] body.tv-mode .bill-number { font-size: 0.95rem; padding: 4px 12px; }
        [data-theme="classic"] body.tv-mode .bill-meta span { padding: 5px 12px; font-size: 0.9rem; }
        [data-theme="classic"] body.tv-mode .bill-net { font-size: 1.3rem; padding: 5px 14px; }
        /* Hide the sound/theme label text on a TV — icons are enough */
        body.tv-mode .sound-toggle-btn .sound-toggle-label { display: none; }
        body.tv-mode .nav-label { display: none; }

        .tv-exit-btn { display: none; }
        body.tv-mode .tv-exit-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(162,59,46,0.10);
            border: 1px solid var(--danger);
            color: var(--danger);
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            text-decoration: none;
            transition: background 0.15s;
        }
        body.tv-mode .tv-exit-btn:hover {
            background: rgba(162,59,46,0.20);
        }
        [data-theme="classic"] body.tv-mode .tv-exit-btn {
            background: #fef2f2;
            border-color: #fecaca;
        }

        /* ---------- Responsive ---------- */
        @media (max-width: 900px) {
            body { padding: 10px; }
            h1 { font-size: 1.15rem; }
            .filters-bar { padding: 10px; gap: 8px; }
            .filters-bar .clear-btn { margin-right: 0; }
        }
        @media (max-width: 600px) {
            body { padding: 8px; }
            .topbar { flex-wrap: wrap; gap: 8px; }
            h1 { font-size: 1rem; }
            /* Search takes its own full-width row on phones */
            .search-wrap {
                order: 10;
                flex: 1 1 100%;
                max-width: none;
                min-width: 0;
            }
            .live-badge { font-size: 0.7rem; padding: 5px 10px; }
            .filters-toggle-btn { display: inline-flex; }
            .filters-bar {
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
            }
            @keyframes filtersSlideDown {
                from { opacity: 0; transform: translateY(-6px); }
                to   { opacity: 1; transform: translateY(0); }
            }
            .filter-group { flex-wrap: wrap; }
            .filter-divider { display: none; }
            .filters-bar input[type="date"] {
                flex: 1;
                min-width: 0;
            }
            .ss-wrap { width: 100%; flex: 1; min-width: 0; }
            .filters-bar .clear-btn { width: 100%; margin-right: 0; text-align: center; }
            .bills-header, .details-header { padding: 10px 12px; font-size: 0.85rem; }
            .bill-card { padding: 9px 12px; }
            .bill-customer { font-size: 0.85rem; }
            .bill-meta span { font-size: 0.68rem; }
            th, td { padding: 7px 4px; font-size: 0.7rem; }
        }
        @media (max-width: 400px) {
            .filter-group { flex-direction: column; align-items: stretch; }
            .filters-bar label { margin: 0; }
        }

        /* ---------- Classic (original) theme — structural overrides ----------
           Everything above this point is shared structure styled through
           CSS variables, which is enough for warm/bright/dark. The original
           theme also differed in layout details that aren't just colors —
           gradients instead of flat fills, filled pill badges instead of
           outlined tags, dashed rules turned solid, etc. Those live here,
           scoped to [data-theme="classic"] so they don't affect the others. */
        [data-theme="classic"] .bills-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            border-bottom: none;
        }
        [data-theme="classic"] .details-header {
            background: linear-gradient(135deg, #334155 0%, #1e293b 100%);
            color: white;
            border-bottom: none;
        }
        [data-theme="classic"] .live-dot.state-live {
            background: var(--success);
            animation: livePulseClassic 1.8s ease-out infinite;
        }
        [data-theme="classic"] .live-dot.state-fallback {
            background: #f59e0b;
            animation: livePulseClassic 1.8s ease-out infinite;
        }
        @keyframes livePulseClassic {
            0%   { box-shadow: 0 0 0 0 rgba(16,185,129,0.55); }
            70%  { box-shadow: 0 0 0 7px rgba(16,185,129,0); }
            100% { box-shadow: 0 0 0 0 rgba(16,185,129,0); }
        }
        [data-theme="classic"] .bill-number {
            background: var(--primary);
            color: white;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 999px;
            font-family: inherit;
        }
        [data-theme="classic"] .bill-meta span {
            background: #f3f4f9;
            padding: 3px 8px;
            border-radius: 999px;
        }
        [data-theme="classic"] .bill-meta span::after { content: none; }
        [data-theme="classic"] .bill-meta .meta-disc  { background: #fef2f2; color: var(--danger); }
        [data-theme="classic"] .bill-meta .meta-extra { background: #fffbeb; color: #b45309; }
        [data-theme="classic"] .bill-net {
            background: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
            border-radius: 999px;
            padding: 3px 10px;
            font-family: inherit;
            font-variant-numeric: normal;
            font-weight: 800;
            font-size: 0.82rem;
        }
        [data-theme="classic"] .bill-card { border-right-color: var(--primary); }
        [data-theme="classic"] .bill-card:hover { background: #f8f8fd; }
        [data-theme="classic"] .bill-card.active { border-right-color: var(--primary-dark); }
        [data-theme="classic"] .bill-card.new-flash { animation: newBillFlashClassic 2.2s ease-out; }
        @keyframes newBillFlashClassic {
            0%   { background: #d1fae5; }
            100% { background: var(--surface); }
        }
        [data-theme="classic"] .bill-card.card-updated { animation: cardUpdatedClassic 1.5s ease-out; }
        @keyframes cardUpdatedClassic {
            0%   { background: #fef3c7; }
            100% { background: var(--surface); }
        }
        [data-theme="classic"] .bill-card.active.card-updated { animation: cardUpdatedActiveClassic 1.5s ease-out; }
        @keyframes cardUpdatedActiveClassic {
            0%   { background: #fef3c7; }
            100% { background: var(--primary-light); }
        }
        [data-theme="classic"] .load-more-btn { background: #f8f8fd; color: var(--primary); }
        [data-theme="classic"] th { background: #f6f7fb; color: #0f172a; }
        [data-theme="classic"] tr:hover td { background: #fafaff; }
        [data-theme="classic"] .error { background: #fef2f2; }
        [data-theme="classic"] td:nth-child(n+5),
        [data-theme="classic"] tr.totals-row td:nth-child(n+5) {
            font-family: inherit;
            font-variant-numeric: normal;
        }
        [data-theme="classic"] tr.totals-row td {
            background: #f1f5f9;
            color: #0f172a;
            border-top: 2px solid var(--border);
            border-top-style: solid;
        }
        [data-theme="classic"] tr.totals-row td:last-child {
            color: #0f172a;
            font-size: inherit;
        }
        [data-theme="classic"] .details-summary { border-bottom-style: solid; }
        [data-theme="classic"] .ds-chip { border-radius: 999px; font-family: inherit; }
        [data-theme="classic"] .ds-num   { background: var(--primary); color: white; border-color: var(--primary); }
        [data-theme="classic"] .ds-disc  { background: #fef2f2; color: var(--danger); border-color: #fecaca; }
        [data-theme="classic"] .ds-extra { background: #fffbeb; color: #b45309; border-color: #fde68a; }
        [data-theme="classic"] .ds-grand { background: #ecfdf5; color: #047857; border-color: #a7f3d0; font-size: 0.72rem; }
        [data-theme="classic"] .ds-rate  { background: #eff6ff; color: #1e40af; border-color: #bfdbfe; }
        [data-theme="classic"] .ds-warn  { background: #fef3c7; color: #92400e; border-color: #fde68a; }
        [data-theme="classic"] .details-summary.has-mismatch { background: #fffbeb; border-bottom-color: #fde68a; }
        [data-theme="classic"] .mismatch-banner {
            background: #fffbeb;
            border-color: #fde68a;
            color: #92400e;
        }
        [data-theme="classic"] .mismatch-banner .mismatch-hint { color: #a16207; }
        [data-theme="classic"] .filters-bar .clear-btn {
            background: #fef2f2;
            border-color: #fecaca;
            border-radius: 999px;
        }
        [data-theme="classic"] .sound-toggle-btn.enabled {
            background: #ecfdf5;
            border-color: #a7f3d0;
            color: #047857;
        }
        [data-theme="classic"] ::-webkit-scrollbar-thumb { background: #c7cbe0; }
        [data-theme="classic"] ::-webkit-scrollbar-thumb:hover { background: #a5abcf; }

        /* ---------- Print ---------- */
        /* Hidden on screen; only shown for the printed invoice header. */
        .print-invoice-header { display: none; }

        @media print {
            /* Printing should always read on plain paper, regardless of
               whichever theme is active on screen. */
            :root, :root[data-theme="dark"], :root[data-theme="bright"] {
                --bg: #ffffff;
                --surface: #ffffff;
                --text: #1D2A35;
                --text-muted: #444444;
                --border: #999999;
                --primary-dark: #8A5F22;
            }
            /* Classic uses a different accent, so it needs its own reset —
               otherwise printing in that theme would tint the total in indigo. */
            :root[data-theme="classic"] {
                --bg: #ffffff;
                --surface: #ffffff;
                --text: #1e1b2e;
                --text-muted: #444444;
                --border: #999999;
                --primary-dark: #3730a3;
            }

            /* Only the bill details are a printable document — everything
               else is dashboard chrome. */
            body * { visibility: hidden; }
            .details-container, .details-container * { visibility: visible; }

            body {
                display: block;
                padding: 0;
                background: #ffffff;
            }
            .dashboard { height: auto; display: block; }
            .details-container {
                position: absolute;
                top: 0; left: 0; right: 0;
                border: none;
                box-shadow: none;
                border-radius: 0;
            }
            .details-header, .details-summary, .mismatch-banner,
            .sound-toggle-btn, .theme-toggle-btn, .print-btn, .update-pill {
                display: none !important;
            }
            .print-invoice-header {
                display: block;
                padding: 0 4px 14px;
                margin-bottom: 10px;
                border-bottom: 2px solid #000000;
            }
            .print-invoice-title {
                font-family: 'Fraunces', Georgia, serif;
                font-size: 20pt;
                font-weight: 700;
                margin-bottom: 10px;
            }
            .print-invoice-meta {
                width: 100%;
                border-collapse: collapse;
                font-size: 11pt;
            }
            .print-invoice-meta td {
                padding: 3px 4px;
                border: none;
            }

            .table-wrapper { overflow: visible; }
            table { font-size: 11pt; }
            th, td { position: static; padding: 6px 8px; }
            th {
                background: #ffffff !important;
                border-bottom: 1.5px solid #000000;
            }
            td { border-bottom: 1px solid var(--border); }
            tr { break-inside: avoid; }
            thead { display: table-header-group; }   /* repeat column headers per page */
            tr.totals-row td {
                background: #ffffff !important;
                border-top: 1.5px solid #000000;
                position: static;
            }
        }
    </style>
</head>
<body>
<div class="dashboard">
        <div class="topbar">
        <div class="topbar-start">
            <div class="menu-wrap" id="menuWrap">
                <button type="button" class="menu-toggle-btn" id="menuToggleBtn"
                        title="القائمة" aria-haspopup="true" aria-expanded="false">
                    ☰
                </button>
                <div class="menu-dropdown" id="menuDropdown" role="menu">
                    <div class="menu-section">
                        <div class="menu-section-title">التنقل</div>
                            <a href="index.php" class="menu-item"><span class="tile-icon"><i class="ti ti-home"></i></span><span>الرئيسية</span></a>
                            <a href="dashboard.php" class="menu-item"><span class="tile-icon"><i class="ti ti-layout-dashboard"></i></span><span>لوحة المتابعة</span></a>
                            <a href="stats.php" class="menu-item"><span class="tile-icon"><i class="ti ti-chart-bar"></i></span><span>الإحصائيات</span></a>
                            <a href="products.php" class="menu-item"><span class="tile-icon"><i class="ti ti-package"></i></span><span>الأصناف</span></a>
                            <a href="inventory.php" class="menu-item"><span class="tile-icon"><i class="ti ti-building-warehouse"></i></span><span>المخزون</span></a>
                            <a href="movements.php" class="menu-item"><span class="tile-icon"><i class="ti ti-file-text"></i></span><span>حركة المواد</span></a>
                            <a href="serial-movements.php" class="menu-item"><span class="tile-icon"><i class="ti ti-barcode"></i></span><span>حركة الأرقام التسلسلية</span></a>
                            <a href="customers.php" class="menu-item"><span class="tile-icon"><i class="ti ti-users"></i></span><span>الزبائن</span></a>
                            <a href="salesmen.php" class="menu-item"><span class="tile-icon"><i class="ti ti-user"></i></span><span>البائعون</span></a>
                            <a href="accounts.php" class="menu-item"><span class="tile-icon"><i class="ti ti-wallet"></i></span><span>الحسابات</span></a>
                            <a href="bills.php" class="menu-item"><span class="tile-icon"><i class="ti ti-receipt"></i></span><span>أنماط الفواتير</span></a>
                            <a href="cost-centers.php" class="menu-item"><span class="tile-icon"><i class="ti ti-briefcase"></i></span><span>مراكز التكلفة</span></a>
                    </div>
                    <div class="menu-section">
                        <div class="menu-section-title">التفضيلات</div>
                        <button type="button" class="menu-item" id="themeToggleBtn">
                            <span class="menu-item-icon" id="themeToggleIcon">📜</span>
                            <span>تبديل النمط</span>
                        </button>
                        <button type="button" class="menu-item" id="soundToggleBtn">
                            <span class="menu-item-icon" id="soundToggleIcon">🔔</span>
                            <span id="soundToggleLabel">صامت</span>
                        </button>
                    </div>
                    <div class="menu-section">
                        <a href="logout.php" class="menu-item menu-item-danger">
                            <span class="menu-item-icon">🚪</span>
                            <span>تسجيل الخروج</span>
                        </a>
                    </div>
                </div>
            </div>
            <h1><span class="tile-icon"><i class="ti ti-layout-dashboard"></i></span>QuantuSphere Web</h1>
        </div>
        <div class="search-wrap" id="searchWrap">
            <input type="text" id="searchInput" class="search-input"
                   placeholder="🔍 ابحث برقم الفاتورة أو اسم الزبون..."
                   autocomplete="off" spellcheck="false">
            <button type="button" class="search-clear" id="searchClear" title="مسح البحث" style="display:none;">✕</button>
        </div>
        <div class="topbar-right">
            <div class="live-badge"><span class="live-dot"></span> تحديث مباشر</div>
            <button type="button" class="filters-toggle-btn" id="filtersToggleBtn">
                ☰ الفلاتر <span class="filters-toggle-badge" id="filtersToggleBadge"></span>
            </button>
        </div>
    </div>

    <div class="filters-bar" id="filtersBar">
        <div class="filter-group">
            <label>الزبون</label>
            <div class="ss-wrap" id="customerWrap">
                <input type="text" class="ss-input" id="customerInput" placeholder="الكل" autocomplete="off">
                <div class="ss-panel" id="customerPanel"></div>
            </div>
        </div>
        <div class="filter-divider"></div>
        <div class="filter-group">
            <label>البائع</label>
            <div class="ss-wrap" id="salesmanWrap">
                <input type="text" class="ss-input" id="salesmanInput" placeholder="الكل" autocomplete="off">
                <div class="ss-panel" id="salesmanPanel"></div>
            </div>
        </div>
        <div class="filter-divider"></div>
        <div class="filter-group">
            <button type="button" class="preset-btn" data-preset="today">اليوم</button>
            <button type="button" class="preset-btn" data-preset="week">هذا الأسبوع</button>
            <button type="button" class="preset-btn" data-preset="month">هذا الشهر</button>
        </div>
        <div class="filter-divider"></div>
        <div class="filter-group">
            <label>من</label>
            <input type="date" id="filterDateFrom">
            <label>إلى</label>
            <input type="date" id="filterDateTo">
        </div>
        <button type="button" class="clear-btn" id="clearFiltersBtn">✖ مسح الفلاتر</button>
    </div>

    <div class="split-container">
        <!-- Bills section (top) -->
        <div class="bills-container" id="topSection">
            <div class="bills-header">
                <span>📄 الفواتير (اضغط لعرض التفاصيل)</span>
                <span style="display:flex;align-items:center;gap:8px;">
                    <button type="button" class="export-btn" id="exportCsvBtn" title="تصدير القائمة إلى CSV">
                        📥 <span class="export-btn-label">CSV</span>
                    </button>
                    <span class="bill-count-pill" id="billCountPill">0</span>
                </span>
            </div>
            <div id="bills">جاري التحميل...</div>
            <div id="loadMoreBtn" class="load-more-btn" style="display: none;">➕ تحميل المزيد</div>
        </div>
        <!-- Splitter handle -->
        <div id="splitter" class="splitter"></div>
        <!-- Details section (bottom) -->
        <div class="details-container" id="bottomSection">
            <div class="details-header">
                <span>🧾 تفاصيل الفاتورة</span>
                <span style="display:flex;gap:6px;align-items:center;">
                    <a href="#" class="customer-btn disabled" id="customerBtn" title="فتح ملف الزبون">
                        👤 <span class="customer-btn-label">الزبون</span>
                    </a>
                    <button type="button" class="print-btn" id="printBtn" title="طباعة الفاتورة" disabled>
                        🖨️ <span class="print-btn-label">طباعة</span>
                    </button>
                </span>
            </div>
            <div class="table-wrapper" id="detailsContainer">
                <div class="loading">اختر فاتورة لعرض الأصناف</div>
            </div>
        </div>
    </div>
</div>

<!-- Keyboard shortcuts cheat-sheet (?). Hidden until summoned. -->
<div class="kbd-overlay" id="kbdOverlay" role="dialog" aria-hidden="true">
    <div class="kbd-card">
        <div class="kbd-header">
            <span>⌨️ اختصارات لوحة المفاتيح</span>
            <button type="button" class="kbd-close" id="kbdClose" title="إغلاق (Esc)">✕</button>
        </div>
        <table class="kbd-table">
            <tbody>
                <tr><td><kbd>/</kbd></td><td>الانتقال إلى مربع البحث</td></tr>
                <tr><td><kbd>↑</kbd> <kbd>↓</kbd></td><td>التنقل بين الفواتير</td></tr>
                <tr><td><kbd>Enter</kbd></td><td>فتح الفاتورة المحددة</td></tr>
                <tr><td><kbd>Esc</kbd></td><td>إغلاق التفاصيل / مسح البحث</td></tr>
                <tr><td><kbd>Ctrl</kbd>+<kbd>P</kbd></td><td>طباعة الفاتورة المفتوحة</td></tr>
                <tr><td><kbd>?</kbd></td><td>عرض هذه القائمة</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
    // Price display mode, set in config.php.
    // 'base' = raw stored value (install's base currency)
    // 'bill' = converted to the bill's currency (matches the receipt)
    const UNIT_PRICE_MODE = '<?= defined('UNIT_PRICE_MODE') ? UNIT_PRICE_MODE : 'base' ?>';

    // Alameen bill PayType mapping.
    // Edit this table if you find more values in bu000 (e.g. 2 = مختلط).
    const PAY_TYPES = {
        '0': 'نقدي',
        '1': 'آجل'
    };

    function payTypeLabel(v) {
        if (v === null || v === undefined || v === '') return '-';
        const key = String(v).trim();
        return PAY_TYPES[key] || ('نوع ' + key);
    }
    // ---------- TV / kiosk mode ----------
    // Triggered by ?tv=1 in the URL. Bookmarkable, no toggle to remember.
    const TV_MODE = new URLSearchParams(window.location.search).get('tv') === '1';
    if (TV_MODE) {
        document.body.classList.add('tv-mode');
    }

    
    // ---------- Keyboard shortcuts ----------
    // Active in normal mode only. In TV/kiosk mode nobody is at the
    // keyboard, so the shortcuts stay out of the way.
    (function initKeyboardShortcuts() {
        if (TV_MODE) return;

        const overlay = document.getElementById('kbdOverlay');
        const closeBtn = document.getElementById('kbdClose');

        function openHelp()  { if (overlay) { overlay.classList.add('open');  overlay.setAttribute('aria-hidden', 'false'); } }
        function closeHelp() { if (overlay) { overlay.classList.remove('open'); overlay.setAttribute('aria-hidden', 'true');  } }
        function helpIsOpen() { return overlay && overlay.classList.contains('open'); }

        if (closeBtn) closeBtn.addEventListener('click', closeHelp);
        if (overlay)  overlay.addEventListener('click', (e) => { if (e.target === overlay) closeHelp(); });

        // Is the user currently typing into a form field?
        function inInput(el) {
            if (!el) return false;
            const tag = el.tagName;
            return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable;
        }

        // Find the card element that is either active or adjacent to it in
        // the DOM. Returns null when the list is empty.
        function currentCard() {
            if (activeCardEl && document.body.contains(activeCardEl)) return activeCardEl;
            return document.querySelector('.bill-card.active')
                || document.querySelector('.bill-card:first-child');
        }

        function moveSelection(dir) {
            const all = Array.from(document.querySelectorAll('.bill-card'));
            if (all.length === 0) return;

            const cur = currentCard();
            let idx = cur ? all.indexOf(cur) : -1;
            idx += dir;

            if (idx < 0) idx = 0;
            if (idx >= all.length) idx = all.length - 1;

            const target = all[idx];
            if (!target) return;

            // Open the details for the newly-selected bill. showDetails()
            // updates activeCardEl itself.
            const guid = target.dataset.guid;
            if (guid) {
                target.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                showDetails(guid, target);
            }
        }

        document.addEventListener('keydown', (e) => {
            // Help overlay takes priority — Esc closes it, everything else
            // is ignored while it's open (except the modal's own close button).
            if (helpIsOpen()) {
                if (e.key === 'Escape') { e.preventDefault(); closeHelp(); }
                return;
            }

            const typing = inInput(document.activeElement);

            // "?" — the help cheat-sheet. Shift+/ on most layouts.
            if (!typing && (e.key === '?' || (e.key === '/' && e.shiftKey))) {
                e.preventDefault();
                openHelp();
                return;
            }

            // "/" — focus search. Ignored while already typing.
            if (!typing && e.key === '/') {
                e.preventDefault();
                const search = document.getElementById('searchInput');
                if (search) search.focus();
                return;
            }

            // Esc — context aware: clear search if it has focus, otherwise
            // close the currently-open bill details.
            if (e.key === 'Escape') {
                const search = document.getElementById('searchInput');
                if (search && document.activeElement === search) {
                    e.preventDefault();
                    if (search.value) {
                        search.value = '';
                        search.dispatchEvent(new Event('input', { bubbles: true }));
                    } else {
                        search.blur();
                    }
                    return;
                }
                if (activeBillGuid) {
                    e.preventDefault();
                    activeBillGuid = null;
                    if (activeCardEl) { activeCardEl.classList.remove('active'); activeCardEl = null; }
                    setPrintEnabled(false);
                    document.getElementById('detailsContainer').innerHTML =
                        '<div class="loading">اختر فاتورة لعرض الأصناف</div>';
                }
                return;
            }

            // Arrow keys move the selection. Ignored while typing so the user
            // can cursor inside the search box normally.
            if (!typing && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
                e.preventDefault();
                moveSelection(e.key === 'ArrowDown' ? 1 : -1);
                return;
            }

            // Enter opens the currently-selected bill (in case it was only
            // highlighted via arrow keys before).
            if (!typing && e.key === 'Enter') {
                const cur = currentCard();
                if (cur && cur.dataset.guid) {
                    e.preventDefault();
                    cur.click();
                }
                return;
            }
        });
    })();
    
        // ---------- Hamburger menu ----------
    (function initTopMenu() {
        const wrap = document.getElementById('menuWrap');
        const btn  = document.getElementById('menuToggleBtn');
        const menu = document.getElementById('menuDropdown');
        if (!wrap || !btn || !menu) return;

        function open() {
            menu.classList.add('open');
            btn.classList.add('open');
            btn.setAttribute('aria-expanded', 'true');
        }
        function close() {
            menu.classList.remove('open');
            btn.classList.remove('open');
            btn.setAttribute('aria-expanded', 'false');
        }
        function isOpen() { return menu.classList.contains('open'); }

        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            isOpen() ? close() : open();
        });

        // Close on outside click. Uses mousedown so it beats the button's
        // click, which keeps the toggle from firing twice.
        document.addEventListener('mousedown', (e) => {
            if (!isOpen()) return;
            if (!wrap.contains(e.target)) close();
        });

        // Close on Escape
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && isOpen()) {
                close();
                btn.focus();
            }
        });

        // Preference toggles stay open so the user sees the state change;
        // navigation items close it, but the browser is already leaving.
    })();

    
    // ---------- Splitter logic (draggable) ----------
    const topSection = document.getElementById('topSection');
    const splitter = document.getElementById('splitter');
    const splitContainer = document.querySelector('.split-container');
    let isDragging = false;
    let startY = 0;
    let startTopHeight = 0;

    function minBottomSpace() {
        // On short/mobile viewports, reserve less room for the details panel
        return splitContainer.clientHeight < 500 ? 90 : 150;
    }
    function minTopHeight() {
        return splitContainer.clientHeight < 400 ? 60 : 80;
    }

    // Load saved height from localStorage
    const savedHeight = localStorage.getItem('dashboardTopHeight');
    const containerHeight = splitContainer.clientHeight;
    if (savedHeight && containerHeight > 0) {
        const clamped = Math.min(Math.max(parseFloat(savedHeight), minTopHeight()), containerHeight - minBottomSpace());
        topSection.style.height = clamped + 'px';
    } else {
        // Default: 50% of available space (minus splitter margin)
        topSection.style.height = Math.floor(containerHeight * 0.5) + 'px';
    }

    function onMouseMove(e) {
        if (!isDragging) return;
        const containerRect = splitContainer.getBoundingClientRect();
        const mouseY = e.clientY;
        let newTopHeight = mouseY - containerRect.top - (splitter.offsetHeight / 2);
        newTopHeight = Math.min(Math.max(newTopHeight, minTopHeight()), containerRect.height - minBottomSpace());
        topSection.style.height = newTopHeight + 'px';
        localStorage.setItem('dashboardTopHeight', newTopHeight);
    }

    function onMouseUp() {
        isDragging = false;
        document.body.style.userSelect = '';
        splitter.classList.remove('active');
        document.removeEventListener('mousemove', onMouseMove);
        document.removeEventListener('mouseup', onMouseUp);
    }

    splitter.addEventListener('mousedown', (e) => {
        isDragging = true;
        startY = e.clientY;
        startTopHeight = topSection.clientHeight;
        document.body.style.userSelect = 'none';
        splitter.classList.add('active');
        document.addEventListener('mousemove', onMouseMove);
        document.addEventListener('mouseup', onMouseUp);
        e.preventDefault();
    });

    // On window resize, re-apply saved percentage logic? We'll just keep absolute height.
    // But if the container shrinks too much, we reclamp on next drag.
    // Also allow touch events for tablets
    splitter.addEventListener('touchstart', (e) => {
        isDragging = true;
        startTopHeight = topSection.clientHeight;
        document.body.style.userSelect = 'none';
        splitter.classList.add('active');
        document.addEventListener('touchmove', onTouchMove, { passive: false });
        document.addEventListener('touchend', onTouchEnd);
        e.preventDefault();
    }, { passive: false });

    function onTouchMove(e) {
        if (!isDragging) return;
        e.preventDefault();
        const containerRect = splitContainer.getBoundingClientRect();
        const touchY = e.touches[0].clientY;
        let newTopHeight = touchY - containerRect.top - (splitter.offsetHeight / 2);
        newTopHeight = Math.min(Math.max(newTopHeight, minTopHeight()), containerRect.height - minBottomSpace());
        topSection.style.height = newTopHeight + 'px';
        localStorage.setItem('dashboardTopHeight', newTopHeight);
    }

    function onTouchEnd() {
        isDragging = false;
        document.body.style.userSelect = '';
        splitter.classList.remove('active');
        document.removeEventListener('touchmove', onTouchMove);
        document.removeEventListener('touchend', onTouchEnd);
    }

    // ---------- Filters ----------
        let activeFilters = { customer: '', salesman: '', dateFrom: '', dateTo: '', search: '' };

    function buildFilterQuery() {
        let qs = '';
        if (activeFilters.customer) qs += '&customer=' + encodeURIComponent(activeFilters.customer);
        if (activeFilters.salesman) qs += '&salesman=' + encodeURIComponent(activeFilters.salesman);
        if (activeFilters.dateFrom) qs += '&dateFrom=' + encodeURIComponent(activeFilters.dateFrom);
        if (activeFilters.dateTo) qs += '&dateTo=' + encodeURIComponent(activeFilters.dateTo);
        if (activeFilters.search) qs += '&search=' + encodeURIComponent(activeFilters.search);
        return qs;
    }

    
    // ---------- CSV export ----------
    // Exports exactly what is on screen: same filters, same order, same rows.
    // Runs entirely in the browser — no server endpoint involved.
    function csvEscape(v) {
        const s = String(v === null || v === undefined ? '' : v);
        // Wrap in quotes if it contains a comma, quote, newline, or leading '='
        // (the last one guards against Excel treating "=cmd|..." as a formula).
        if (/[",\n\r]/.test(s) || /^=/.test(s)) {
            return '"' + s.replace(/"/g, '""') + '"';
        }
        return s;
    }

    function exportBillsCSV() {
        const rows = Array.from(displayed.values());
        if (rows.length === 0) return;

        // Column order and headers
        const headers = [
            '#',
            'رقم الفاتورة',
            'التاريخ',
            'الوقت',
            'الزبون',
            'العملة',
            'طريقة الدفع',
            'الإجمالي',
            'الخصم',
            'إضافي',
            'الصافي',
            'الفرع',
            'مركز التكلفة',
            'سعر الصرف',
        ];

        const num = v => { const n = parseFloat(v); return isFinite(n) ? n : 0; };
        const lines = [headers.map(csvEscape).join(',')];

        rows.forEach((bill, i) => {
            const cv    = num(bill.CurrencyVal) || 1;
            const total = num(bill.Total)      / cv;
            const disc  = num(bill.TotalDisc)  / cv;
            const extra = num(bill.TotalExtra) / cv;
            const net   = total - disc + extra;

            // Rate column: only meaningful for non-base-currency bills.
            const rate  = (Math.abs(cv - 1) > 0.000001) ? (1 / cv) : '';

            const created = bill.CreateDate ? new Date(bill.CreateDate) : null;
            const dateStr = bill.Date ? new Date(bill.Date).toLocaleDateString('en-GB') : '';
            const timeStr = created ? created.toLocaleTimeString('en-GB', { hour12: false }) : '';

            lines.push([
                i + 1,
                bill.Number || '',
                dateStr,
                timeStr,
                bill.Cust_Name || '',
                bill.CurrencyName || '',
                payTypeLabel(bill.PayType),
                total.toFixed(2),
                disc.toFixed(2),
                extra.toFixed(2),
                net.toFixed(2),
                bill.StoreName || '',
                bill.CostCenterName || '',
                rate === '' ? '' : rate.toFixed(4),
            ].map(csvEscape).join(','));
        });

        // BOM is what makes Excel open Arabic correctly. Without it, Excel
        // guesses the encoding and mangles every non-ASCII character.
        const csv = '\uFEFF' + lines.join('\r\n');

        // Build a descriptive filename from the active filters
        const parts = ['bills'];
        if (activeFilters.search)   parts.push('search-' + activeFilters.search.replace(/[^\p{L}\p{N}_-]+/gu, '_'));
        if (activeFilters.dateFrom) parts.push('from-' + activeFilters.dateFrom);
        if (activeFilters.dateTo)   parts.push('to-'   + activeFilters.dateTo);
        const now = new Date();
        const stamp = now.getFullYear()
                    + String(now.getMonth() + 1).padStart(2, '0')
                    + String(now.getDate()).padStart(2, '0')
                    + '_'
                    + String(now.getHours()).padStart(2, '0')
                    + String(now.getMinutes()).padStart(2, '0');
        const filename = parts.join('_').slice(0, 80) + '_' + stamp + '.csv';

        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }

    document.getElementById('exportCsvBtn').addEventListener('click', exportBillsCSV);
    
    // ---------- Searchable dropdown component ----------
    function makeSearchSelect(wrapId, inputId, panelId) {
        const wrap = document.getElementById(wrapId);
        const input = document.getElementById(inputId);
        const panel = document.getElementById(panelId);
        let items = [];       // [{value, label}]
        let selectedValue = '';
        let selectedLabel = '';
        let onChangeCb = null;

        function render(filterText) {
            panel.innerHTML = '';

            const allOpt = document.createElement('div');
            allOpt.className = 'ss-option ss-option-all' + (selectedValue === '' ? ' selected' : '');
            allOpt.textContent = 'الكل';
            allOpt.addEventListener('mousedown', (e) => { e.preventDefault(); select('', ''); });
            panel.appendChild(allOpt);

            const ft = (filterText || '').trim().toLowerCase();
            const filtered = ft ? items.filter(it => it.label.toLowerCase().includes(ft)) : items;

            if (filtered.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'ss-empty';
                empty.textContent = 'لا توجد نتائج';
                panel.appendChild(empty);
            } else {
                filtered.forEach(it => {
                    const opt = document.createElement('div');
                    opt.className = 'ss-option' + (it.value === selectedValue ? ' selected' : '');
                    opt.textContent = it.label;
                    opt.addEventListener('mousedown', (e) => { e.preventDefault(); select(it.value, it.label); });
                    panel.appendChild(opt);
                });
            }
        }

        function select(value, label) {
            selectedValue = value;
            selectedLabel = label;
            input.value = label;
            wrap.classList.remove('open');
            if (onChangeCb) onChangeCb(value);
        }

        function openPanel() {
            wrap.classList.add('open');
            render('');
        }

        input.addEventListener('focus', openPanel);
        input.addEventListener('click', openPanel);
        input.addEventListener('input', () => {
            wrap.classList.add('open');
            render(input.value);
        });
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') { input.blur(); wrap.classList.remove('open'); }
        });
        input.addEventListener('blur', () => {
            // slight delay so a click on an option registers before the panel closes
            setTimeout(() => {
                wrap.classList.remove('open');
                if (input.value !== selectedLabel) input.value = selectedLabel;
            }, 150);
        });

        return {
            setItems(newItems) { items = newItems; },
            getValue() { return selectedValue; },
            onChange(cb) { onChangeCb = cb; },
            clear() { selectedValue = ''; selectedLabel = ''; input.value = ''; }
        };
    }

    const customerSelect = makeSearchSelect('customerWrap', 'customerInput', 'customerPanel');
    const salesmanSelect = makeSearchSelect('salesmanWrap', 'salesmanInput', 'salesmanPanel');
    customerSelect.onChange(onFiltersChanged);
    salesmanSelect.onChange(onFiltersChanged);

    async function loadFilterOptions() {
        try {
            let resp = await fetch('?filters=1');
            let data = await resp.json();
            if (data.error) { console.error(data.error); return; }

            customerSelect.setItems(data.customers.map(name => ({ value: name, label: name })));
            salesmanSelect.setItems(data.salesmen.map(s => ({ value: s.GUID, label: s.DisplayName })));
        } catch (e) {
            console.error('Failed to load filter options:', e);
        }
    }

    function fmtDateInput(d) {
        return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
    }

    function applyPreset(preset) {
        document.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
        const today = new Date();
        let from = new Date(today);
        let to = new Date(today);

        if (preset === 'today') {
            document.querySelector('[data-preset="today"]').classList.add('active');
        } else if (preset === 'week') {
            const day = (today.getDay() + 6) % 7; // Monday-start week
            from.setDate(today.getDate() - day);
            document.querySelector('[data-preset="week"]').classList.add('active');
        } else if (preset === 'month') {
            from = new Date(today.getFullYear(), today.getMonth(), 1);
            document.querySelector('[data-preset="month"]').classList.add('active');
        }

        document.getElementById('filterDateFrom').value = fmtDateInput(from);
        document.getElementById('filterDateTo').value = fmtDateInput(to);
        onFiltersChanged();
    }

    function onFiltersChanged() {
        activeFilters.customer = customerSelect.getValue();
        activeFilters.salesman = salesmanSelect.getValue();
        activeFilters.dateFrom = document.getElementById('filterDateFrom').value;
        activeFilters.dateTo   = document.getElementById('filterDateTo').value;

        updateFiltersToggleBadge();

        // Reset state and reload from scratch
        lastCreateDate = null;
        lastUpdateDate = null;
        displayed.clear();
        resetNotifySuppression();
        activeCardEl   = null;
        activeBillGuid = null;
        setPrintEnabled(false);
        setCustomerButton(null);
        loadMoreOffset = 1;
        hasMore = true;
        document.getElementById('bills').innerHTML = 'جاري التحميل...';

        // Stop whatever live mechanism is running
        stopSSE();
        stopPollingFallback();

        // Reload, then restart SSE with the new filter set
        (async () => {
            await loadBills(false);
            resetNotifySuppression();
            startSSE();
        })();
    }

    function updateFiltersToggleBadge() {
        const badge = document.getElementById('filtersToggleBadge');
        if (!badge) return;
        const count = Object.values(activeFilters).filter(v => v).length;
        if (count > 0) {
            badge.textContent = count;
            badge.style.display = 'inline-block';
        } else {
            badge.style.display = 'none';
        }
    }

    document.getElementById('filterDateFrom').addEventListener('change', () => {
        document.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
        onFiltersChanged();
    });
    document.getElementById('filterDateTo').addEventListener('change', () => {
        document.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
        onFiltersChanged();
    });
    document.querySelectorAll('.preset-btn').forEach(btn => {
        btn.addEventListener('click', () => applyPreset(btn.dataset.preset));
    });
    document.getElementById('clearFiltersBtn').addEventListener('click', () => {
        customerSelect.clear();
        salesmanSelect.clear();
        document.getElementById('filterDateFrom').value = '';
        document.getElementById('filterDateTo').value = '';
        document.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
        onFiltersChanged();
    });

        // Filters bar toggle — works on any screen size. State is remembered
    // per device: default open on desktop, closed on phones.
    const filtersToggleBtn = document.getElementById('filtersToggleBtn');
    const filtersBarEl     = document.getElementById('filtersBar');
    const FILTERS_VISIBLE_KEY = 'dashboardFiltersVisible';

    (function initFiltersToggle() {
        let saved = null;
        try { saved = localStorage.getItem(FILTERS_VISIBLE_KEY); } catch (e) {}

        let visible;
        if (saved === '1')      visible = true;
        else if (saved === '0') visible = false;
        else                    visible = window.innerWidth > 600;   // first visit

        if (visible) filtersBarEl.classList.add('filters-open');
        filtersToggleBtn.classList.toggle('active', visible);

        filtersToggleBtn.addEventListener('click', () => {
            const isOpen = filtersBarEl.classList.toggle('filters-open');
            filtersToggleBtn.classList.toggle('active', isOpen);
            try {
                localStorage.setItem(FILTERS_VISIBLE_KEY, isOpen ? '1' : '0');
            } catch (e) {}
        });
    })();

    // Close any open dropdown panel when clicking elsewhere on the page
    document.addEventListener('mousedown', (e) => {
        document.querySelectorAll('.ss-wrap.open').forEach(w => {
            if (!w.contains(e.target)) w.classList.remove('open');
        });
                // On phones, tapping outside the filters bar (and not on the toggle button) closes it
        if (window.innerWidth <= 600 &&
            filtersBarEl.classList.contains('filters-open') &&
            !filtersBarEl.contains(e.target) &&
            !filtersToggleBtn.contains(e.target)) {
            filtersBarEl.classList.remove('filters-open');
            filtersToggleBtn.classList.remove('active');
            try { localStorage.setItem(FILTERS_VISIBLE_KEY, '0'); } catch (e) {}
        }
    });

    loadFilterOptions();

    
    // ---------- Topbar search ----------
    (function initSearchBox() {
        const input = document.getElementById('searchInput');
        const clear = document.getElementById('searchClear');
        if (!input || !clear) return;

        let debounce = null;

        function showClear(show) {
            clear.style.display = show ? 'block' : 'none';
        }

        function commit(value) {
            const v = (value || '').trim();
            if (v === activeFilters.search) return;
            activeFilters.search = v;
            showClear(v.length > 0);
            onFiltersChanged();
        }

        input.addEventListener('input', () => {
            clearTimeout(debounce);
            // Show the clear button as soon as the user types anything
            showClear(input.value.length > 0);
            debounce = setTimeout(() => commit(input.value), 350);
        });

        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                clearTimeout(debounce);
                commit(input.value);
                input.blur();
            } else if (e.key === 'Escape') {
                e.preventDefault();
                input.value = '';
                clearTimeout(debounce);
                commit('');
            }
        });

        clear.addEventListener('click', () => {
            input.value = '';
            clearTimeout(debounce);
            commit('');
            input.focus();
        });
    })();
    
    // ---------- SSE (live updates) ----------
    let sseConnection    = null;
    let sseErrorCount    = 0;
    let pollFallbackTimer = null;
    const SSE_MAX_ERRORS = 5;

    function stopSSE() {
        if (sseConnection) {
            sseConnection.close();
            sseConnection = null;
        }
    }

    function startSSE() {
        stopSSE();
        // NOTE: sseErrorCount is deliberately NOT reset here. Resetting it on
        // every retry meant the counter never reached SSE_MAX_ERRORS and the
        // polling fallback could never trigger. It is reset in 'open' below,
        // i.e. only once a connection actually succeeds.

        let url = 'sse_bills.php';
        const params = [];
        if (lastCreateDate) params.push('lastCreate=' + encodeURIComponent(lastCreateDate));
        if (lastUpdateDate) params.push('lastUpdate=' + encodeURIComponent(lastUpdateDate));
        if (activeFilters.customer) params.push('customer=' + encodeURIComponent(activeFilters.customer));
        if (activeFilters.salesman) params.push('salesman=' + encodeURIComponent(activeFilters.salesman));
        if (activeFilters.dateFrom) params.push('dateFrom=' + encodeURIComponent(activeFilters.dateFrom));
        if (activeFilters.dateTo)   params.push('dateTo='   + encodeURIComponent(activeFilters.dateTo));
        if (activeFilters.search)   params.push('search='   + encodeURIComponent(activeFilters.search));
        if (params.length) url += '?' + params.join('&');

        try {
            sseConnection = new EventSource(url);
        } catch (e) {
            console.warn('SSE unavailable, using polling fallback:', e);
            startPollingFallback();
            return;
        }

        sseConnection.addEventListener('open', () => {
            sseErrorCount = 0;      // only a real connection clears the counter
            stopPollingFallback();  // SSE is back; stop double-fetching
            setLiveIndicator('live');
        });

        sseConnection.addEventListener('bills', (e) => {
            sseErrorCount = 0;

            let bills;
            try { bills = JSON.parse(e.data); } catch (err) { return; }
            if (!Array.isArray(bills) || !bills.length) return;

            const actuallyNew = [];

            for (const bill of bills) {
                if (isNewer(bill.CreateDate, lastCreateDate))
                    lastCreateDate = bill.CreateDate;
                if (isNewer(bill.LastUpdateDate, lastUpdateDate))
                    lastUpdateDate = bill.LastUpdateDate;

                if (!displayed.has(bill.GUID)) {
                    displayed.set(bill.GUID, bill);
                    addBillCard(bill, true);
                    actuallyNew.push(bill);
                } else {
                    // Already on screen. Only flash if it was genuinely edited.
                    const prev = displayed.get(bill.GUID);
                    const prevLU = prev ? (prev.LastUpdateDate || '') : '';
                    const newLU  = bill.LastUpdateDate || '';
                    if (newLU !== prevLU) {
                        displayed.set(bill.GUID, bill);
                        updateBillCardFromHeader(bill);
                    }
                    // else: silent re-delivery — ignore
                }
            }

            updateBillCount();
            if (actuallyNew.length) notifyNewBills(actuallyNew);
        });

        sseConnection.addEventListener('error', () => {
            sseErrorCount++;
            if (sseErrorCount >= SSE_MAX_ERRORS) {
                console.warn('SSE failed ' + SSE_MAX_ERRORS + ' times, switching to polling');
                setLiveIndicator('fallback');
                stopSSE();
                startPollingFallback();
            } else {
                // Recreate the stream with the current watermarks, backing off
                // a little further on each consecutive failure.
                stopSSE();
                setLiveIndicator('down');
                const delay = Math.min(2000 * sseErrorCount, 15000);
                setTimeout(startSSE, delay);
            }
        });
    }

    function startPollingFallback() {
        if (pollFallbackTimer) return;
        // Use the delta poller, not loadBills(false). The latter wiped the
        // DOM, cleared `displayed`, reset the watermarks and discarded every
        // "Load More" page — every 5 seconds.
        pollFallbackTimer = setInterval(pollNewBills, 5000);
        pollNewBills();
    }

    function stopPollingFallback() {
        if (pollFallbackTimer) {
            clearInterval(pollFallbackTimer);
            pollFallbackTimer = null;
        }
    }
    
    async function pollNewBills() {
        if (isLoadingMore) return;
        if (document.hidden) return;

        let url = '?ajax=1';
        const _p = [];
        if (lastCreateDate) _p.push('sinceCreate=' + encodeURIComponent(lastCreateDate));
        if (lastUpdateDate) _p.push('sinceUpdate=' + encodeURIComponent(lastUpdateDate));
        if (_p.length) url += '&' + _p.join('&');
        url += buildFilterQuery();

        try {
            const resp = await fetch(url);
            if (handleAuthFailure(resp)) return;
            const data = await resp.json();
            if (!Array.isArray(data) || !data.length) return;

            const actuallyNew = [];
            for (const bill of data) {
                // Advance watermarks (epoch comparison — see ts() above)
                if (isNewer(bill.CreateDate, lastCreateDate)) {
                    lastCreateDate = bill.CreateDate;
                }
                if (isNewer(bill.LastUpdateDate, lastUpdateDate)) {
                    lastUpdateDate = bill.LastUpdateDate;
                }

                if (!displayed.has(bill.GUID)) {
                    displayed.set(bill.GUID, bill);
                    addBillCard(bill, true);
                    actuallyNew.push(bill);
                } else {
                    // Already on screen — only re-render if it was genuinely edited
                    const prev   = displayed.get(bill.GUID);
                    const prevLU = prev ? (prev.LastUpdateDate || '') : '';
                    const newLU  = bill.LastUpdateDate || '';
                    if (newLU !== prevLU) {
                        displayed.set(bill.GUID, bill);
                        updateBillCardFromHeader(bill);
                    }
                    // else: silent re-delivery — ignore
                }
            }

            updateBillCount();
            if (actuallyNew.length) notifyNewBills(actuallyNew);
        } catch (e) {
            // silent — next tick will retry
        }
    }


    function setLiveIndicator(state) {
        const badge = document.querySelector('.live-badge');
        const dot   = document.querySelector('.live-dot');
        if (!badge || !dot) return;

        // Driven by CSS classes rather than inline colors, so each theme
        // (including the classic/original one) can style the three states
        // itself instead of every theme being stuck with one hardcoded color.
        dot.classList.remove('state-live', 'state-fallback', 'state-down');

        if (state === 'live') {
            dot.classList.add('state-live');
            badge.lastChild.textContent = ' تحديث مباشر (SSE)';
        } else if (state === 'fallback') {
            dot.classList.add('state-fallback');
            badge.lastChild.textContent = ' تحديث كل 5 ثوانٍ';
        } else if (state === 'down') {
            dot.classList.add('state-down');
            badge.lastChild.textContent = ' قطع الاتصال';
        }
    }

    // ---------- Dark mode ----------
    const THEME_KEY = 'dashboardTheme';

    (function initThemeToggle() {
        const btn  = document.getElementById('themeToggleBtn');
        const icon = document.getElementById('themeToggleIcon');
        if (!btn || !icon) return;

        // Cycle order: warm (parchment ledger) → bright (crisp white,
        // for daylight) → dark → classic (the original theme, kept for
        // customers who prefer it) → back to warm.
        const THEMES = ['warm', 'bright', 'dark', 'classic'];
        const THEME_META = {
            warm:    { icon: '📜', label: 'دافئ',   next: 'الفاتح الساطع' },
            bright:  { icon: '☀️', label: 'فاتح',   next: 'الداكن' },
            dark:    { icon: '🌙', label: 'داكن',   next: 'الأصلي' },
            classic: { icon: '🔷', label: 'الأصلي', next: 'الدافئ' },
        };

        function explicitTheme() {
            const v = document.documentElement.getAttribute('data-theme');
            return THEMES.includes(v) ? v : null;
        }
        function effectiveTheme() {
            return explicitTheme()
                || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
                        ? 'dark' : 'warm');
        }
        function updateIcon() {
            const cur = effectiveTheme();
            icon.textContent = THEME_META[cur].icon;
            btn.title = `الوضع الحالي: ${THEME_META[cur].label} — اضغط للتبديل إلى ${THEME_META[cur].next}`;
        }

        updateIcon();

        btn.addEventListener('click', () => {
            const cur  = effectiveTheme();
            const next = THEMES[(THEMES.indexOf(cur) + 1) % THEMES.length];
            document.documentElement.setAttribute('data-theme', next);
            try { localStorage.setItem(THEME_KEY, next); } catch (e) { /* ignore */ }
            updateIcon();
        });

        // Track the system preference live, but only while the user hasn't
        // made an explicit choice on this device.
        if (window.matchMedia) {
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
                let hasExplicit = false;
                try { hasExplicit = !!localStorage.getItem(THEME_KEY); } catch (e) { /* ignore */ }
                if (!hasExplicit) updateIcon();
            });
        }
    })();

    // ---------- Print ----------
    function setPrintEnabled(enabled) {
        const btn = document.getElementById('printBtn');
        if (btn) btn.disabled = !enabled;
    }

    
    // ---------- Customer-file button ----------
    // Alameen stores an all-zeros GUID for walk-in / anonymous bills; there
    // is no customer file to open for those, so the button is greyed out.
    const NULL_GUID = '00000000-0000-0000-0000-000000000000';
    function setCustomerButton(custGuid) {
        const btn = document.getElementById('customerBtn');
        if (!btn) return;
        if (custGuid && custGuid !== NULL_GUID) {
            btn.href = 'customers.php?guid=' + encodeURIComponent(custGuid);
            btn.classList.remove('disabled');
            btn.title = 'فتح ملف الزبون';
        } else {
            btn.href = '#';
            btn.classList.add('disabled');
            btn.title = 'لا يوجد زبون مسجل لهذه الفاتورة';
        }
    }

    (function initPrintButton() {
        const btn = document.getElementById('printBtn');
        if (!btn) return;
        btn.addEventListener('click', () => {
            if (!activeBillGuid) return;
            window.print();
        });
    })();

    // ---------- Sound + desktop notifications ----------
    const SOUND_ENABLED_KEY = 'dashboardSoundEnabled';

    // Declared up here so resetNotifySuppression() can set it regardless of
    // the order the functions are called in.
    let _suppressNextChime = false;   // true on first load, to skip old bills

    let soundEnabled   = false;   // will be restored from localStorage
    let audioContext   = null;

    // Restore user preference
    try {
        soundEnabled = localStorage.getItem(SOUND_ENABLED_KEY) === '1';
    } catch (e) { /* ignore */ }

    const soundBtn   = document.getElementById('soundToggleBtn');
    const soundIcon  = document.getElementById('soundToggleIcon');
    const soundLabel = document.getElementById('soundToggleLabel');

    function updateSoundButton() {
        if (!soundBtn) return;
        soundBtn.classList.toggle('enabled', soundEnabled);
        soundIcon.textContent  = soundEnabled ? '🔔' : '🔕';
        soundLabel.textContent = soundEnabled ? 'التنبيهات مفعلة' : 'صامت';
        soundBtn.title = soundEnabled
            ? 'التنبيهات مفعلة — اضغط للكتم'
            : 'التنبيهات صامتة — اضغط للتفعيل';
    }

    // Initialize button state
    updateSoundButton();

    // ─── Audio unlock + beep ────────────────────────────────────────
    function ensureAudioContext() {
        if (audioContext) return audioContext;
        const Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) return null;
        try {
            audioContext = new Ctx();
        } catch (e) {
            audioContext = null;
        }
        return audioContext;
    }

    // Two quick ascending notes: pleasant, distinct, not jarring
    function playNewBillChime() {
        const ctx = ensureAudioContext();
        if (!ctx) return;
        if (ctx.state === 'suspended') ctx.resume();

        const now = ctx.currentTime;
        const notes = [
            { freq: 880,  start: 0,     dur: 0.12 },   // A5
            { freq: 1175, start: 0.13,  dur: 0.18 }    // D6
        ];

        notes.forEach(n => {
            const osc  = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.value = n.freq;

            // Gentle envelope to avoid clicks
            gain.gain.setValueAtTime(0, now + n.start);
            gain.gain.linearRampToValueAtTime(0.18, now + n.start + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.001, now + n.start + n.dur);

            osc.connect(gain).connect(ctx.destination);
            osc.start(now + n.start);
            osc.stop(now + n.start + n.dur + 0.05);
        });
    }

    // ─── Desktop notification ──────────────────────────────────────
    async function requestNotificationPermission() {
        if (!('Notification' in window)) return false;
        if (Notification.permission === 'granted') return true;
        if (Notification.permission === 'denied')  return false;

        const result = await Notification.requestPermission();
        return result === 'granted';
    }

    function showDesktopNotification(bill, netAmount) {
        if (!('Notification' in window) || Notification.permission !== 'granted') return;

        const title = `فاتورة جديدة #${bill.Number}`;
        const body  = `${bill.Cust_Name || 'مناقلة'} — ${fmtNum(netAmount)} ${bill.CurrencyName || ''}`;

        try {
            const n = new Notification(title, {
                body,
                tag: bill.GUID,           // dedupe: same GUID replaces an older notification
                silent: true,             // we play our own chime, no OS beep
                requireInteraction: false
            });
            // Auto-close after 6 seconds
            setTimeout(() => n.close(), 6000);
        } catch (e) {
            // Some browsers throw when page isn't focused; ignore silently
        }
    }

    // ─── Toggle handler ────────────────────────────────────────────
    if (soundBtn) {
        soundBtn.addEventListener('click', async () => {
            soundEnabled = !soundEnabled;

            if (soundEnabled) {
                // This click is the user interaction that unlocks audio
                const ctx = ensureAudioContext();
                if (ctx && ctx.state === 'suspended') ctx.resume();

                // Test chime so the user immediately hears it working
                playNewBillChime();

                // Ask for desktop-notification permission too
                await requestNotificationPermission();
            }

            try { localStorage.setItem(SOUND_ENABLED_KEY, soundEnabled ? '1' : '0'); } catch (e) {}
            updateSoundButton();
        });
    }

    // ─── Main entry point — call this from the SSE handler ─────────
    function notifyNewBills(bills) {
        if (!Array.isArray(bills) || bills.length === 0) return;

        // On the very first call after page load / filter change,
        // the SSE stream may deliver a burst of historical bills — skip those.
        if (_suppressNextChime) {
            _suppressNextChime = false;
            return;
        }

        if (!soundEnabled) return;

        // Play the chime only once per batch, even if 3 bills arrived together
        playNewBillChime();

        // But show a desktop notification for the newest one only
        // (a stack of 10 notifications is annoying)
        const newest = bills[bills.length - 1];
        const num = v => { const n = parseFloat(v); return isFinite(n) ? n : 0; };
        const net = num(newest.Total) - num(newest.TotalDisc) + num(newest.TotalExtra);
        showDesktopNotification(newest, net);
    }

    // Reset the suppression flag whenever the bill list is reset
    // (page load, filter change, SSE reconnect). The body of this was
    // commented out, so the first SSE burst after a reload could chime for
    // bills the user had already seen.
    function resetNotifySuppression() {
        _suppressNextChime = true;
    }

    // ---------- Timestamp helpers ----------
    // Watermarks used to be compared as raw strings. The server sends ISO-8601
    // with its own offset ("+03:00") while the browser produced UTC ("...Z"),
    // so a lexical `>` between them was meaningless and edit detection either
    // fired for everything or never fired. Always compare epoch milliseconds.
    function ts(v) {
        if (!v) return -Infinity;
        const t = Date.parse(v);
        return Number.isFinite(t) ? t : -Infinity;
    }
    function isNewer(candidate, current) {
        return ts(candidate) > ts(current);
    }

    // ---------- Dashboard functionality ----------
    let lastCreateDate = null;
    let lastUpdateDate = null;
    let displayed = new Map();
    let isLoadingMore = false;
    let loadMoreOffset = 1;
    let hasMore = true;

    // If the session expires, every endpoint answers 401. Send the user to
    // the login page instead of rendering "error: unauthenticated".
    let _redirectingToLogin = false;
    function handleAuthFailure(resp) {
        if (resp && resp.status === 401) {
            if (!_redirectingToLogin) {
                _redirectingToLogin = true;
                stopSSE();
                stopPollingFallback();
                window.location.href = 'login.php';
            }
            return true;
        }
        return false;
    }

    async function getServerNow() {
        try {
            const r = await fetch('?serverTime=1', { cache: 'no-store' });
            const j = await r.json();
            if (j && j.now) return j.now;
        } catch (e) { /* fall through */ }
        console.warn('Server time unavailable; falling back to the browser clock.');
        return new Date().toISOString();
    }

    async function loadBills(isLoadMore = false) {
        let url = '?ajax=1';
        if (isLoadMore) {
            url += '&loadMore=' + loadMoreOffset;
        }
        url += buildFilterQuery();

        try {
            const resp = await fetch(url);
            if (handleAuthFailure(resp)) return;
            const text = await resp.text();
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error('JSON parse error:', e, 'Response:', text.substring(0, 200));
                document.getElementById('bills').innerHTML =
                    '<div class="error">خطأ في استجابة الخادم. تحقق من وحدة التحكم.</div>';
                return;
            }
            if (data.error) {
                document.getElementById('bills').innerHTML =
                    `<div class="error">${escapeHtml(data.error)}</div>`;
                return;
            }
            if (!Array.isArray(data)) return;

            if (!isLoadMore) {
                // Initial load OR filter change: reset everything
                document.getElementById('bills').innerHTML = '';
                displayed.clear();
                activeCardEl   = null;   // the node we were pointing at is gone
                lastCreateDate = null;
                lastUpdateDate = null;

                for (const bill of data) {
                    displayed.set(bill.GUID, bill);
                    addBillCard(bill, false);
                    if (isNewer(bill.CreateDate, lastCreateDate)) {
                        lastCreateDate = bill.CreateDate;
                    }
                }
                // Rewind the initial watermark by 1s so we don't miss anything
                // that arrived between the page-load query and the SSE connect.
                if (lastCreateDate) {
                    lastCreateDate = new Date(ts(lastCreateDate) - 1000).toISOString();
                }
                // From this moment forward, only catch edits newer than now.
                // "Now" must come from the SERVER: seeding this from the
                // browser clock meant any skew silently replayed or skipped
                // edits. Falls back to the local clock only if the call fails.
                lastUpdateDate = await getServerNow();
                if (data.length < 200) hasMore = false;
                document.getElementById('loadMoreBtn').style.display = hasMore ? 'block' : 'none';
                updateBillCount();
            } else {
                // Load More pagination
                for (const bill of data) {
                    if (!displayed.has(bill.GUID)) {
                        displayed.set(bill.GUID, bill);
                        addBillCard(bill, false);
                    }
                }
                if (data.length < 200) hasMore = false;
                loadMoreOffset++;
                document.getElementById('loadMoreBtn').style.display = hasMore ? 'block' : 'none';
                isLoadingMore = false;
                updateBillCount();
            }
            // Inserts and edits arrive over SSE (or pollNewBills as a
            // fallback); deletes are handled by reconcileDeletedBills().
        } catch (e) {
            console.error('Fetch error:', e);
            if (!isLoadMore) {
                document.getElementById('bills').innerHTML =
                    `<div class="error">خطأ في الشبكة: ${escapeHtml(e.message)}</div>`;
            }
        } finally {
            if (isLoadMore) isLoadingMore = false;
        }
    }

    function updateBillCount() {
        const pill = document.getElementById('billCountPill');
        if (pill) pill.textContent = displayed.size;
    }

    function addBillCard(bill, prependFlag) {
        const num = v => { const n = parseFloat(v); return isFinite(n) ? n : 0; };
        const cv  = num(bill.CurrencyVal) || 1;

        const total = num(bill.Total)     / cv;
        const disc  = num(bill.TotalDisc) / cv;
        const extra = num(bill.TotalExtra)/ cv;
        const net   = total - disc + extra;

        let div = document.createElement('div');
        div.className = 'bill-card' + (prependFlag ? ' new-flash' : '');
        div.dataset.guid = bill.GUID;
        div.innerHTML = `
            <div class="bill-title">
                <span class="bill-number">#${escapeHtml(bill.Number)}</span>
                <span class="bill-customer">${escapeHtml(bill.Cust_Name || 'مناقلة')}</span>
                <span class="bill-net">${fmtNum(net)} ${escapeHtml(bill.CurrencyName || '')}</span>
            </div>
            <div class="bill-meta">
                <span>💰 إجمالي: ${fmtNum(total)}</span>
                ${disc ? `<span class="meta-disc">➖ خصم: ${fmtNum(disc)}</span>` : ''}
                ${extra ? `<span class="meta-extra">➕ إضافي: ${fmtNum(extra)}</span>` : ''}
                <span>💳 ${payTypeLabel(bill.PayType)}</span>
                <span>🏬 ${escapeHtml(bill.StoreName || '-')}</span>
                <span>📊 ${escapeHtml(bill.CostCenterName || '-')}</span>
                <span>📅 ${new Date(bill.Date).toLocaleDateString('ar-EG-u-nu-latn')}</span>
                <span>🕒 ${new Date(bill.CreateDate).toLocaleTimeString('ar-EG-u-nu-latn')}</span>
            </div>
        `;
        div.onclick = () => showDetails(bill.GUID, div);
        const container = document.getElementById('bills');
        if (prependFlag && container.firstChild) {
            const nearTop = container.scrollTop < 50;
            const oldHeight = container.scrollHeight;
            container.insertBefore(div, container.firstChild);
            if (!nearTop) {
                // Keep the user visually where they were
                container.scrollTop += (container.scrollHeight - oldHeight);
            }
        } else {
            container.appendChild(div);
        }
    }

    let activeBillGuid = null;
    let lastDetailsSignature = '';

    let activeCardEl = null;

    async function showDetails(guid, element) {
        // Track the active card directly instead of sweeping every card in the
        // DOM on each click — that was O(n) and the list grows without bound.
        if (activeCardEl && activeCardEl !== element) {
            activeCardEl.classList.remove('active');
        }
        element.classList.add('active');
        activeCardEl = element;

        activeBillGuid = guid;
        lastDetailsSignature = '';   // force the next render
        setPrintEnabled(false);      // re-enabled by renderDetails() once items are in
        setCustomerButton(null);     // re-enabled by renderDetails() from hdr.CustGUID

        const detailsDiv = document.getElementById('detailsContainer');
        detailsDiv.innerHTML = '<div class="loading">جاري تحميل التفاصيل...</div>';

        try {
            const resp = await fetch(`?details=1&guid=${encodeURIComponent(guid)}`);
            if (handleAuthFailure(resp)) return;
            const data = await resp.json();
            if (data.error) throw new Error(data.error);

            // Guard: user clicked another bill while this was in flight
            if (activeBillGuid !== guid) return;

            renderDetails(data, false);
        } catch (e) {
            if (activeBillGuid !== guid) return;
            detailsDiv.innerHTML = `<div class="error">${escapeHtml(e.message)}</div>`;
        }
    }

    
    // Open a bill's details by GUID without needing a card in the list.
    // Used by ?bill=<guid> when the target isn't part of the current view.
    async function openBillDetailsById(guid) {
        activeBillGuid = guid;
        lastDetailsSignature = '';
        setPrintEnabled(false);
        setCustomerButton(null);

        const detailsDiv = document.getElementById('detailsContainer');
        detailsDiv.innerHTML = '<div class="loading">جاري تحميل التفاصيل...</div>';

        try {
            const resp = await fetch(`?details=1&guid=${encodeURIComponent(guid)}`);
            if (handleAuthFailure(resp)) return;
            const data = await resp.json();
            if (data.error) throw new Error(data.error);
            if (activeBillGuid !== guid) return;
            renderDetails(data, false);
        } catch (e) {
            if (activeBillGuid !== guid) return;
            detailsDiv.innerHTML = `<div class="error">${escapeHtml(e.message)}</div>`;
        }
    }

    function renderDetails(data, isRefresh) {
        const detailsDiv = document.getElementById('detailsContainer');
        const items = Array.isArray(data.items) ? data.items : [];
        const hdr   = data.header || null;

        if (!items.length) {
            detailsDiv.innerHTML = '<div class="loading">لا توجد أصناف في هذه الفاتورة.</div>';
            return;
        }

        const num = v => {
            const n = parseFloat(v);
            return isFinite(n) ? n : 0;
        };

        let sumTotal = 0, sumDisc = 0, sumExtra = 0, sumNet = 0;

        // Minimal fallback letterhead for entries with no header row (e.g. a
        // مناقلة with items but no bu000 match) — real one is built below
        // when `hdr` is available.
        let printHeader = `
            <div class="print-invoice-header">
                <div class="print-invoice-title">فاتورة مبيعات</div>
            </div>`;

        let html = '<table><thead><tr>';
        html += '<th>#</th><th>اسم الصنف</th><th>الكمية</th><th>الهدايا</th><th>الوحدة</th>'
            + '<th>سعر الوحدة</th><th>الإجمالي</th><th>الخصم</th><th>إضافي</th><th>الصافي</th>';
        html += '</tr></thead><tbody>';

        items.forEach((row, i) => {
            const iCv   = num(row.CurrencyVal) || 1;
            const price = num(row.UnitPrice)   / iCv;   // ← unit price in bill currency
            const total = num(row.Total)       / iCv;
            const disc  = num(row.DiscountValue) / iCv;
            const extra = num(row.Extra)       / iCv;
            const net   = total - disc + extra;
            sumTotal += total;
            sumDisc  += disc;
            sumExtra += extra;
            sumNet   += net;

            html += `<tr>
                <td>${i + 1}</td>
                <td>${escapeHtml(row.ItemName)}</td>
                <td>${fmtNum(row.Qty)}</td>
                <td>${fmtNum(row.BonusQty || 0)}</td>
                <td>${escapeHtml(row.Unit) || '-'}</td>
                <td>${fmtNum(price)}</td>
                <td>${fmtNum(total)}</td>
                <td>${fmtNum(disc)}</td>
                <td>${fmtNum(extra)}</td>
                <td>${fmtNum(net)}</td>
            </tr>`;
        });

        html += '</tbody><tfoot><tr class="totals-row">';
        html += '<td colspan="2">الإجمالي</td>';
        html += '<td></td><td></td><td></td><td></td>';
        html += `<td>${fmtNum(sumTotal)}</td>`;
        html += `<td>${fmtNum(sumDisc)}</td>`;
        html += `<td>${fmtNum(sumExtra)}</td>`;
        html += `<td>${fmtNum(sumNet)}</td>`;
        html += '</tr></tfoot></table>';

        if (hdr) {
            const hCv   = num(hdr.CurrencyVal) || 1;
            const gt    = num(hdr.Total)      / hCv;
            const gd    = num(hdr.TotalDisc)  / hCv;
            const ge    = num(hdr.TotalExtra) / hCv;
            const gvat  = num(hdr.VAT)        / hCv;
            const grand = gt - gd + ge;

            // ── Consistency check: does the header match the sum of items? ──
            const EPSILON = 0.01;   // tolerance for rounding
            const mismatchTotal = Math.abs(gt - sumTotal) > EPSILON;
            const mismatchDisc  = Math.abs(gd - sumDisc)  > EPSILON;
            const mismatchExtra = Math.abs(ge - sumExtra) > EPSILON;
            const hasMismatch   = mismatchTotal || mismatchDisc || mismatchExtra;

                        // Exchange rate chip: 1 USD = 1/CurrencyVal units of the bill currency.
            const rate = (hCv > 0 && Math.abs(hCv - 1) > 0.000001) ? 1 / hCv : null;
            const rateChip = rate
                ? `<span class="ds-chip ds-rate" title="سعر الصرف وقت إصدار الفاتورة">💱 الدولار = <bdi dir="ltr">${fmtNum(rate)}</bdi> ${escapeHtml(hdr.CurrencyName || '')}</span>`
                : '';

            const summary = `
                <div class="details-summary ${hasMismatch ? 'has-mismatch' : ''}">
                    <span class="ds-chip ds-num">فاتورة #${escapeHtml(hdr.Number)}</span>
                    <span class="ds-chip">${escapeHtml(hdr.Cust_Name || 'مناقلة')}</span>
                    <span class="ds-chip">عملة: ${escapeHtml(hdr.CurrencyName || '-')}</span>
                    ${rateChip}
                    <span class="ds-chip">إجمالي: ${fmtNum(gt)}</span>
                    <span class="ds-chip ds-disc">خصم: ${fmtNum(gd)}</span>
                    ${ge ? `<span class="ds-chip ds-extra">إضافي: ${fmtNum(ge)}</span>` : ''}
                    ${gvat ? `<span class="ds-chip">ضريبة: ${fmtNum(gvat)}</span>` : ''}
                    <span class="ds-chip ds-grand">الصافي: ${fmtNum(grand)}</span>
                    ${hasMismatch
                        ? `<span class="ds-chip ds-warn" title="الإجمالي المسجل في الترويسة لا يطابق مجموع الأصناف">⚠ عدم تطابق الترويسة مع الأصناف</span>`
                        : ''}
                </div>`;

            // Print-only letterhead — not shown on screen, only revealed by
            // the @media print rules. Built from the same totals as the
            // on-screen summary so the printed and displayed figures match.
            const billDate = hdr.Date ? new Date(hdr.Date).toLocaleDateString('ar-EG-u-nu-latn') : '-';
            printHeader = `
                <div class="print-invoice-header">
                    <div class="print-invoice-title">فاتورة مبيعات</div>
                    <table class="print-invoice-meta">
                        <tr>
                            <td><b>رقم الفاتورة:</b> ${escapeHtml(hdr.Number)}</td>
                            <td><b>التاريخ:</b> ${billDate}</td>
                        </tr>
                        <tr>
                            <td><b>الزبون:</b> ${escapeHtml(hdr.Cust_Name || 'مناقلة')}</td>
                            <td><b>العملة:</b> ${escapeHtml(hdr.CurrencyName || '-')}</td>
                        </tr>
                        <tr>
                            <td><b>الفرع:</b> ${escapeHtml(hdr.StoreName || '-')}</td>
                            <td><b>طريقة الدفع:</b> ${payTypeLabel(hdr.PayType)}</td>
                        </tr>
                    </table>
                </div>`;

            // If mismatched, insert a small explanatory banner above the table
            const mismatchBanner = hasMismatch ? `
                <div class="mismatch-banner">
                    <b>⚠ تنبيه:</b> الإجمالي المسجل في ترويسة الفاتورة (<b>${fmtNum(gt)}</b>)
                    لا يطابق مجموع الأصناف (<b>${fmtNum(sumTotal)}</b>).
                    الفرق: <b>${fmtNum(Math.abs(gt - sumTotal))}</b>.
                    ${mismatchDisc  ? `الخصم المسجل (<b>${fmtNum(gd)}</b>) لا يطابق مجموع الخصومات (<b>${fmtNum(sumDisc)}</b>). ` : ''}
                    ${mismatchExtra ? `الإضافي المسجل (<b>${fmtNum(ge)}</b>) لا يطابق مجموع الإضافات (<b>${fmtNum(sumExtra)}</b>). ` : ''}
                    <br><span class="mismatch-hint">غالباً السبب: تعديل يدوي على قاعدة البيانات، أو حفظ غير مكتمل للفاتورة من الكاشير.</span>
                </div>` : '';

            html = summary + mismatchBanner + html;
        }

        detailsDiv.innerHTML = printHeader + html;
        setPrintEnabled(true);
        if (hdr && hdr.CustGUID) setCustomerButton(hdr.CustGUID);
        else                     setCustomerButton(null);

        // Signature of what we just rendered, so refresh can compare
        lastDetailsSignature = JSON.stringify(data);

        // If this was a refresh, flash the header and update the card in the list
        if (isRefresh && hdr) {
            flashDetailsHeader();
            updateBillCardFromHeader(hdr);
        }
    }

    // ---------- Auto-refresh the currently open bill ----------
    const DETAILS_REFRESH_MS = 10000;   // 10 seconds

    async function refreshActiveBillDetails() {
        if (!activeBillGuid) return;
        if (document.hidden) return;     // don't poll when the tab is in the background

        const guid = activeBillGuid;

        try {
            const resp = await fetch(`?details=1&guid=${encodeURIComponent(guid)}`);
            if (handleAuthFailure(resp)) return;
            const data = await resp.json();
            if (data.error) return;
            if (activeBillGuid !== guid) return;   // user switched bills mid-flight

            const sig = JSON.stringify(data);
            if (sig === lastDetailsSignature) return;   // nothing changed

            renderDetails(data, true);
        } catch (e) {
            // Silent fail — next tick will try again
        }
    }

    function updateBillCardFromHeader(hdr) {
        if (!hdr || !hdr.GUID) return;
        const card = document.querySelector(`.bill-card[data-guid="${hdr.GUID}"]`);
        if (!card) return;

        // Update the cached bill so a later re-render is consistent
        const cached = displayed.get(hdr.GUID);
        if (cached) {
            cached.Total      = hdr.Total;
            cached.TotalDisc  = hdr.TotalDisc;
            cached.TotalExtra = hdr.TotalExtra;
            cached.Cust_Name  = hdr.Cust_Name;
            cached.PayType    = hdr.PayType;
        }

        const num = v => { const n = parseFloat(v); return isFinite(n) ? n : 0; };
        const cv    = num(hdr.CurrencyVal) || 1;
        const total = num(hdr.Total)      / cv;
        const disc  = num(hdr.TotalDisc)  / cv;
        const extra = num(hdr.TotalExtra) / cv;
        const net   = total - disc + extra;

        const netEl = card.querySelector('.bill-net');
        if (netEl) netEl.textContent = `${fmtNum(net)} ${hdr.CurrencyName || ''}`;

        const metaEl = card.querySelector('.bill-meta');
        if (metaEl) {
            const timeStr = new Date(hdr.CreateDate).toLocaleTimeString('ar-EG-u-nu-latn');
            metaEl.innerHTML = `
                <span>💰 إجمالي: ${fmtNum(total)}</span>
                ${disc ? `<span class="meta-disc">➖ خصم: ${fmtNum(disc)}</span>` : ''}
                ${extra ? `<span class="meta-extra">➕ إضافي: ${fmtNum(extra)}</span>` : ''}
                <span>💳 ${payTypeLabel(hdr.PayType)}</span>
                <span>🏬 ${escapeHtml(hdr.StoreName || '-')}</span>
                <span>📊 ${escapeHtml(hdr.CostCenterName || '-')}</span>
                <span>📅 ${new Date(hdr.Date).toLocaleDateString('ar-EG-u-nu-latn')}</span>
                <span>🕒 ${timeStr}</span>
            `;
        }

        // Visual cue that the card changed
        card.classList.add('card-updated');
        setTimeout(() => card.classList.remove('card-updated'), 1500);
    }

    function flashDetailsHeader() {
        const hdr = document.querySelector('.details-header');
        if (!hdr) return;

        let pill = hdr.querySelector('.update-pill');
        if (!pill) {
            pill = document.createElement('span');
            pill.className = 'update-pill';
            hdr.appendChild(pill);
        }
        pill.textContent = '🔄 تم التحديث';
        pill.classList.add('show');
        clearTimeout(pill._hideTimer);
        pill._hideTimer = setTimeout(() => pill.classList.remove('show'), 2500);
    }

    setInterval(refreshActiveBillDetails, DETAILS_REFRESH_MS);

    // ---------- Delete / void reconciliation ----------
    // SSE only ever reports inserts and edits. A bill deleted or voided in
    // Alameen would otherwise sit on screen until a manual refresh.
    const RECONCILE_MS = 60000;   // once a minute

    async function reconcileDeletedBills() {
        if (document.hidden) return;
        if (isLoadingMore) return;
        if (displayed.size === 0) return;

        let url = '?ajax=1&reconcile=1&count=' + displayed.size + buildFilterQuery();

        try {
            const resp = await fetch(url, { cache: 'no-store' });
            if (handleAuthFailure(resp)) return;
            const data = await resp.json();
            if (!data || !Array.isArray(data.guids)) return;

            const alive  = new Set(data.guids.map(g => String(g).toUpperCase()));
            const oldest = ts(data.oldest);

            for (const [guid, bill] of Array.from(displayed)) {
                if (alive.has(String(guid).toUpperCase())) continue;
                // Only remove bills inside the window the server just read.
                // Anything older than `oldest` simply fell off the end.
                if (ts(bill.CreateDate) < oldest) continue;

                displayed.delete(guid);
                const card = document.querySelector(
                    `.bill-card[data-guid="${CSS.escape(guid)}"]`);
                if (card) {
                    if (card === activeCardEl) activeCardEl = null;
                    card.remove();
                }
                if (activeBillGuid === guid) {
                    activeBillGuid = null;
                    setPrintEnabled(false);
                    setCustomerButton(null);
                    document.getElementById('detailsContainer').innerHTML =
                        '<div class="loading">اختر فاتورة لعرض الأصناف</div>';
                }
            }
            updateBillCount();
        } catch (e) {
            // Silent — the next tick retries.
        }
    }

    setInterval(reconcileDeletedBills, RECONCILE_MS);

    // Refresh immediately when the user brings the tab back into focus
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) refreshActiveBillDetails();
    });

    function escapeHtml(str) {
        // Quotes matter too: these values also land inside attributes.
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
        return String(str === null || str === undefined ? '' : str)
            .replace(/[&<>"']/g, m => map[m]);
    }

    // ---------- Number formatting ----------
    // Western (Latin) digits with thousand separators: 14112 -> "14,112.00"
    const _numFmt = new Intl.NumberFormat('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });

    function fmtNum(v) {
        const n = parseFloat(v);
        return isFinite(n) ? _numFmt.format(n) : '0.00';
    }

    // Convert an amount stored in the base currency (USD) to the bill's currency.
    // bu000.CurrencyVal = "how much of base currency is 1 unit of the bill's currency",
    // so dividing gives the amount in the bill's currency.
    function toBillCurrency(storedAmount, currencyVal) {
        const v  = parseFloat(storedAmount);
        const cv = parseFloat(currencyVal);
        if (!isFinite(v))  return 0;
        if (!isFinite(cv) || cv <= 0) return v;   // guard against missing rate
        return v / cv;
    }

    document.getElementById('loadMoreBtn').addEventListener('click', function() {
        if (isLoadingMore || !hasMore) return;
        isLoadingMore = true;
        loadBills(true);
    });

        (async () => {
        await loadBills(false);   // initial snapshot
        resetNotifySuppression(); // don't chime for the historical bills we just loaded
        startSSE();               // then keep it live

        // If the URL contains ?bill=<guid>, open that bill's details.
        // Used by bills.php (and any future drill-down page) to jump straight
        // to a specific bill without needing the user to find it in the list.
        const params = new URLSearchParams(window.location.search);
        const wantedGuid = params.get('bill');
        if (wantedGuid) {
            const card = document.querySelector(
                `.bill-card[data-guid="${CSS.escape(wantedGuid)}"]`);

            if (card) {
                // Found it in the current list — click it to open normally.
                card.scrollIntoView({ block: 'center', behavior: 'smooth' });
                card.click();
            } else {
                // Not in the loaded page of 200. Fetch details directly so
                // the user still sees what they clicked on.
                openBillDetailsById(wantedGuid);
            }

            // Clean the URL so a refresh doesn't re-trigger, and so
            // "back" doesn't bounce the user into the same state.
            try {
                window.history.replaceState({}, '', window.location.pathname);
            } catch (e) { /* ignore */ }
        }
    })();
    
    // ---------- TV auto-scroll ----------
    (function initTvAutoScroll() {
        if (!TV_MODE) return;
        const container = document.getElementById('bills');
        if (!container) return;

        const TICK_MS         = 50;
        const PIXELS_PER_TICK = 1;      // 1px / 50ms = 20 px/s
        const BOTTOM_PAUSE_MS = 5000;
        const RETURN_MS       = 1200;

        let paused         = false;   // user is hovering / touching
        let atBottomPause  = false;   // waiting at the bottom before jumping up

        container.addEventListener('mouseenter', () => { paused = true; });
        container.addEventListener('mouseleave', () => { paused = false; });
        container.addEventListener('touchstart', () => {
            paused = true;
            clearTimeout(container._tvResumeTimer);
            container._tvResumeTimer = setTimeout(() => { paused = false; }, 10000);
        }, { passive: true });

        function tick() {
            if (document.hidden || paused || atBottomPause) return;
            // Nothing to scroll — don't thrash the top position
            if (container.scrollHeight <= container.clientHeight + 5) return;

            const atBottom =
                container.scrollTop + container.clientHeight >= container.scrollHeight - 2;

            if (atBottom) {
                atBottomPause = true;
                setTimeout(() => {
                    container.scrollTo({ top: 0, behavior: 'smooth' });
                    setTimeout(() => { atBottomPause = false; }, RETURN_MS);
                }, BOTTOM_PAUSE_MS);
            } else {
                container.scrollTop += PIXELS_PER_TICK;
            }
        }

        setInterval(tick, TICK_MS);
    })();
</script>
</body>
</html>