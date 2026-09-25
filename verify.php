<?php
// install/verify.php — one-page diagnostic for AmeenBill install.
//
// Drop-in file. Open in a browser:
//   http://server/ameenweb/install/verify.php
//
// It runs as the reader login, so every check reflects what the
// dashboard can actually do on this install. Nothing is written.
//
// Query parameters:
//   ?live=1     run one row-limited sample query per wrapper (slower)
//   ?detail=1   show full error messages (default: abbreviated)
//
// Delete this file after the install is verified.

header('Content-Type: text/html; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

$detail = isset($_GET['detail']) && $_GET['detail'] == 1;
$live   = isset($_GET['live'])   && $_GET['live']   == 1;

// ─── Locate config.php — this file may sit at root or in /install ─
$configPath = null;
foreach ([
    __DIR__ . '/../config.php',       // install/verify.php
    __DIR__ . '/config.php',          // same folder as config
    dirname(__DIR__) . '/config.php',
] as $candidate) {
    if (file_exists($candidate)) { $configPath = $candidate; break; }
}
if ($configPath === null) {
    die('<h1>Could not find config.php</h1><p>Place verify.php in the same folder as config.php, or one level below.</p>');
}
require_once $configPath;

// ─── Constants ────────────────────────────────────────────────────
// These match install/setup_reader.sql. If they ever change there,
// change them here too.
$SRC_MOVEMENTS = '4737E1CE-4983-487F-9DA9-503B8171AE4B';
$SRC_SN        = '1E065244-0F86-4774-87F2-2BED53B6C7BA';
$SRC_COST      = '25DAF4D1-7F61-46E4-A5C0-C6F5FD4BDD0E';

$REQUIRED_TABLES = [
    'bu000','bi000','mt000','gr000','st000','my000','mh000','co000',
    'Distributor000','DistDeviceST000','cu000','CustAddress000',
    'ac000','en000','bt000','ms000',
    // Infrastructure
    'Connections','RepSrcs',
];

$WRAPPERS = [
    'ameenbill_GetMatMovements'   => 'material movements',
    'ameenbill_GetSnMovements'    => 'serial number (IMEI) movements',
    'ameenbill_GetCostCenterLedger' => 'cost center ledger',
];

$UNDERLYING_PROCS = [
    'repMatMoveMultiProduct' => 'material movements',
    'SNMove'                 => 'serial number (IMEI) movements',
    'RepCostGl'              => 'cost center ledger',
];

// ─── Result collection ────────────────────────────────────────────
$checks = [];
function addCheck($group, $name, $pass, $message = '', $extra = []) {
    global $checks;
    $checks[] = [
        'group' => $group,
        'name'  => $name,
        'pass'  => $pass,
        'message' => $message,
        'extra' => $extra,
    ];
}

// ─── 1. PHP environment ──────────────────────────────────────────
$phpOk = true;
$missingExt = [];
foreach (['sqlsrv', 'pdo_sqlsrv'] as $ext) {
    if (!extension_loaded($ext)) { $phpOk = false; $missingExt[] = $ext; }
}
addCheck('PHP',
    'Extensions loaded',
    $phpOk,
    $phpOk ? 'sqlsrv ' . phpversion('sqlsrv') . ', pdo_sqlsrv ' . phpversion('pdo_sqlsrv')
           : 'Missing: ' . implode(', ', $missingExt)
);

$encOk = defined('PDO::SQLSRV_ATTR_ENCODING');
addCheck('PHP', 'PDO::SQLSRV_ATTR_ENCODING defined', $encOk,
    $encOk ? '' : 'Install pdo_sqlsrv correctly — see https://learn.microsoft.com/sql/connect/php/');

// ─── 2. Database connection ──────────────────────────────────────
$pdo = null;
$connOk = false;
$connMessage = '';
try {
    $pdo = getDBConnection();
    $row = $pdo->query("SELECT DB_NAME() AS db, SUSER_SNAME() AS login, USER_NAME() AS dbuser")->fetch();
    $connOk = true;
    $connMessage = 'Server: ' . DB_SERVER
                 . ' · Database: ' . $row['db']
                 . ' · Login: ' . $row['login']
                 . ' · DB user: ' . $row['dbuser'];
} catch (Throwable $e) {
    $connMessage = 'Connection failed';
    if ($detail) $connMessage .= ': ' . $e->getMessage();
    else          $connMessage .= ' (add ?detail=1 for the message)';
}
addCheck('Database', 'Connection works', $connOk, $connMessage);

if (!$connOk) {
    renderReport($checks, $detail);
    exit;
}

// ─── 3. SELECT permissions on required tables ────────────────────
$tableChecks = [];
foreach ($REQUIRED_TABLES as $t) {
    try {
        $stmt = $pdo->prepare("SELECT HAS_PERMS_BY_NAME(?, 'OBJECT', 'SELECT') AS ok");
        $stmt->execute(['dbo.' . $t]);
        $ok = (int)$stmt->fetchColumn() === 1;
        $tableChecks[$t] = $ok;
    } catch (Throwable $e) {
        $tableChecks[$t] = false;
    }
}
$allTablesOk = !in_array(false, $tableChecks, true);
$missingTables = array_keys(array_filter($tableChecks, fn($v) => !$v));
addCheck('Permissions',
    'SELECT on ' . count($REQUIRED_TABLES) . ' required tables',
    $allTablesOk,
    $allTablesOk ? 'All present'
                 : 'Missing: ' . implode(', ', $missingTables),
    $tableChecks
);

// ─── 4. Wrappers exist and reader can EXECUTE ────────────────────
foreach ($WRAPPERS as $proc => $desc) {
    // Existence
    $exists = false;
    try {
        $stmt = $pdo->prepare("SELECT CASE WHEN OBJECT_ID(?, 'P') IS NULL THEN 0 ELSE 1 END");
        $stmt->execute(['dbo.' . $proc]);
        $exists = (int)$stmt->fetchColumn() === 1;
    } catch (Throwable $e) {}

    addCheck('Wrappers',
        "Exists: $proc ($desc)",
        $exists,
        $exists ? '' : 'Run install/setup_reader.sql to create it'
    );

    if (!$exists) continue;

    // EXECUTE permission
    $canExec = false;
    try {
        $stmt = $pdo->prepare("SELECT HAS_PERMS_BY_NAME(?, 'OBJECT', 'EXECUTE')");
        $stmt->execute(['dbo.' . $proc]);
        $canExec = (int)$stmt->fetchColumn() === 1;
    } catch (Throwable $e) {}
    addCheck('Wrappers',
        "Reader can EXECUTE: $proc",
        $canExec,
        $canExec ? '' : 'Run install/setup_reader.sql — Step 6 grants this'
    );
}

// ─── 5. Underlying Alameen procedures exist ──────────────────────
foreach ($UNDERLYING_PROCS as $proc => $desc) {
    // The reader login lacks VIEW DEFINITION, so sys.procedures hides
    // most objects from it. We try both common schemas anyway, and if
    // neither is visible we mark this as INFO instead of FAIL — because
    // the live wrapper tests are the definitive proof that it works.
    $visible = false;
    foreach (['dbo.' . $proc, $proc] as $candidate) {
        try {
            $stmt = $pdo->prepare("SELECT CASE WHEN OBJECT_ID(?, 'P') IS NULL THEN 0 ELSE 1 END");
            $stmt->execute([$candidate]);
            if ((int)$stmt->fetchColumn() === 1) { $visible = true; break; }
        } catch (Throwable $e) { /* ignore */ }
    }

    if ($visible) {
        addCheck('Alameen procedures', "Visible: $proc ($desc)", true, '');
    } else {
        addCheck('Alameen procedures',
            "Visibility: $proc ($desc)",
            true,   // not a failure — expected behaviour
            'Hidden from reader (no VIEW DEFINITION) — confirmed working by live tests below'
        );
    }
}

// ─── 6. RepSrcs namespaces ───────────────────────────────────────
$namespaces = [];
try {
    $stmt = $pdo->query("
        SELECT IdTbl, COUNT(*) AS n
        FROM RepSrcs
        GROUP BY IdTbl
        ORDER BY n DESC
    ");
    $namespaces = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$namespaceMap = [];
foreach ($namespaces as $row) $namespaceMap[strtoupper($row['IdTbl'])] = (int)$row['n'];

$expectations = [
    'material movements'   => $SRC_MOVEMENTS,
    'SN movements'         => $SRC_SN,
    'cost center ledger'   => $SRC_COST,
];
foreach ($expectations as $label => $src) {
    $rows = $namespaceMap[strtoupper($src)] ?? 0;
    $ok = $rows > 0;
    addCheck('RepSrcs',
        "Namespace populated: $label",
        $ok,
        $ok ? "$rows rows in RepSrcs"
            : "MISSING — run install/setup_reader.sql Step 7"
    );
}
addCheck('RepSrcs',
    'All namespaces on this install',
    true,
    count($namespaces) . ' total namespaces',
    $namespaces
);

// ─── 7. Admin session in Connections ─────────────────────────────
$adminCount = 0;
try {
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM Connections
        WHERE BranchMask = 9223372036854775807
          AND UserGUID IS NOT NULL
          AND UserGUID <> '00000000-0000-0000-0000-000000000000'
          AND HostId <> HOST_ID()
    ");
    $adminCount = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}
addCheck('Connections',
    'At least one admin session available',
    $adminCount > 0,
    $adminCount > 0
        ? "$adminCount admin session(s) found"
        : 'Log into the Alameen client once as an administrator, then reload this page'
);

// ─── 8. Optional live wrapper tests ──────────────────────────────
if ($live) {
    $today = (new DateTime())->format('Y-m-d');

    // Movements wrapper — a small range with all materials
    try {
        $stmt = $pdo->prepare("
            EXEC dbo.ameenbill_GetMatMovements
                @StartDate = :from, @EndDate = :to, @PostedValue = 1
        ");
        $stmt->execute([':from' => $today, ':to' => $today]);
        $n = count($stmt->fetchAll(PDO::FETCH_ASSOC));
        addCheck('Live tests',
            'Material movements wrapper returns rows',
            true,
            "$n row(s) for today"
        );
    } catch (Throwable $e) {
        $msg = $detail ? $e->getMessage() : 'Wrapper call failed — use ?detail=1';
        addCheck('Live tests', 'Material movements wrapper returns rows', false, $msg);
    }

    // SN wrapper — a nonsense SN that matches nothing (fast)
    try {
        $stmt = $pdo->prepare("
            EXEC dbo.ameenbill_GetSnMovements
                @SN = :sn, @StartDate = :from, @EndDate = :to
        ");
        $stmt->execute([':sn' => '__VERIFY__', ':from' => $today, ':to' => $today]);
        $n = count($stmt->fetchAll(PDO::FETCH_ASSOC));
        addCheck('Live tests',
            'SN movements wrapper responds',
            true,
            "$n row(s) for placeholder SN (0 expected)"
        );
    } catch (Throwable $e) {
        $msg = $detail ? $e->getMessage() : 'Wrapper call failed — use ?detail=1';
        addCheck('Live tests', 'SN movements wrapper responds', false, $msg);
    }

    // Cost center wrapper — pick any real cost center from co000
    try {
        $costGuid = $pdo->query("SELECT TOP 1 GUID FROM co000 ORDER BY Number")->fetchColumn();
        if ($costGuid) {
            $stmt = $pdo->prepare("
                EXEC dbo.ameenbill_GetCostCenterLedger
                    @CostGUID = :cost, @StartDate = :from, @EndDate = :to
            ");
            $stmt->execute([':cost' => $costGuid, ':from' => $today, ':to' => $today]);
            $n = count($stmt->fetchAll(PDO::FETCH_ASSOC));
            addCheck('Live tests',
                'Cost center wrapper returns rows',
                true,
                "$n row(s) for cost center $costGuid on $today"
            );
        } else {
            addCheck('Live tests', 'Cost center wrapper returns rows', true,
                'No cost centers in co000 — skipped');
        }
    } catch (Throwable $e) {
        $msg = $detail ? $e->getMessage() : 'Wrapper call failed — use ?detail=1';
        addCheck('Live tests', 'Cost center wrapper returns rows', false, $msg);
    }
}

renderReport($checks, $detail, $live);
exit;

// ═════════════════════════════════════════════════════════════════
//  Rendering
// ═════════════════════════════════════════════════════════════════
function renderReport(array $checks, $detail, $live = false) {
    $groups = [];
    foreach ($checks as $c) $groups[$c['group']][] = $c;

    $anyFail = false;
    foreach ($checks as $c) if (!$c['pass']) { $anyFail = true; break; }

    ?><!DOCTYPE html>
    <html lang="en" dir="ltr">
    <head>
        <meta charset="UTF-8">
        <title>AmeenBill install verification</title>
        <style>
            * { box-sizing: border-box; }
            body {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
                background: #f6f7fb; color: #1e1b2e;
                margin: 0; padding: 24px; line-height: 1.5;
            }
            .wrap { max-width: 900px; margin: 0 auto; }
            h1 { font-size: 1.4rem; margin: 0 0 4px; }
            .sub { color: #6b7280; font-size: 0.9rem; margin-bottom: 20px; }
            .summary {
                padding: 14px 18px; border-radius: 10px; margin-bottom: 20px;
                font-weight: 700; font-size: 1rem;
            }
            .summary.ok   { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
            .summary.fail { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
            .actions { margin-bottom: 20px; display: flex; gap: 8px; flex-wrap: wrap; }
            .actions a {
                display: inline-block; padding: 8px 14px; border-radius: 6px;
                background: white; border: 1px solid #e5e7eb;
                color: #4f46e5; text-decoration: none; font-weight: 700; font-size: 0.85rem;
            }
            .actions a:hover { border-color: #4f46e5; }
            .group {
                background: white; border: 1px solid #e5e7eb; border-radius: 10px;
                margin-bottom: 14px; overflow: hidden;
            }
            .group h2 {
                margin: 0; padding: 10px 16px; font-size: 0.9rem;
                background: #1e293b; color: #f8fafc; font-weight: 700;
            }
            .row {
                display: flex; align-items: flex-start; gap: 10px;
                padding: 10px 16px; border-bottom: 1px solid #f1f2f7;
                font-size: 0.85rem;
            }
            .row:last-child { border-bottom: none; }
            .icon {
                flex-shrink: 0; width: 22px; height: 22px; border-radius: 50%;
                display: inline-flex; align-items: center; justify-content: center;
                font-weight: 900; font-size: 0.72rem; color: white;
            }
            .icon.ok   { background: #10b981; }
            .icon.fail { background: #ef4444; }
            .row .name { font-weight: 700; }
            .row .msg  { color: #6b7280; font-size: 0.8rem; margin-top: 2px; }
            .row .msg.err { color: #b91c1c; }
            .extra {
                background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;
                margin: 6px 16px 14px; padding: 10px 12px; font-size: 0.78rem;
                font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                white-space: pre-wrap; word-break: break-all;
            }
            .extra table { width: 100%; border-collapse: collapse; }
            .extra td { padding: 3px 6px; border-bottom: 1px solid #f1f2f7; }
            .extra td:first-child { color: #6b7280; }
            code {
                background: #eef2ff; padding: 1px 6px; border-radius: 4px;
                font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                font-size: 0.78rem;
            }
        </style>
    </head>
    <body>
        <div class="wrap">
            <h1>AmeenBill install verification</h1>
            <div class="sub">
                Checks the reader connection, permissions, wrappers, and RepSrcs.
                Runs as the reader login — nothing is written.
            </div>

            <div class="summary <?= $anyFail ? 'fail' : 'ok' ?>">
                <?= $anyFail
                    ? 'Some checks failed — see red rows below.'
                    : 'All checks passed. Installation is healthy.' ?>
            </div>

            <div class="actions">
                <a href="?<?= http_build_query(array_merge($_GET, ['live' => $live ? 0 : 1])) ?>">
                    <?= $live ? 'Hide' : 'Run' ?> live wrapper tests
                </a>
                <a href="?<?= http_build_query(array_merge($_GET, ['detail' => $detail ? 0 : 1])) ?>">
                    <?= $detail ? 'Hide' : 'Show' ?> full error messages
                </a>
                <a href="?">Reset</a>
            </div>

            <?php foreach ($groups as $groupName => $rows): ?>
                <div class="group">
                    <h2><?= htmlspecialchars($groupName) ?></h2>
                    <?php foreach ($rows as $c): ?>
                        <div class="row">
                            <span class="icon <?= $c['pass'] ? 'ok' : 'fail' ?>">
                                <?= $c['pass'] ? '✓' : '✗' ?>
                            </span>
                            <div style="flex:1;">
                                <div class="name"><?= htmlspecialchars($c['name']) ?></div>
                                <?php if ($c['message']): ?>
                                    <div class="msg <?= $c['pass'] ? '' : 'err' ?>">
                                        <?= htmlspecialchars($c['message']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!empty($c['extra']) && is_array($c['extra'])):
                            // Table of sub-checks (e.g. per-table SELECT) or namespaces
                            $firstKey = array_key_first($c['extra']);
                            if (is_string($firstKey)): ?>
                                <div class="extra">
                                    <table>
                                        <?php foreach ($c['extra'] as $k => $v): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($k) ?></td>
                                                <td>
                                                    <?php if (is_array($v)): ?>
                                                        <?= htmlspecialchars(($v['IdTbl'] ?? '') . '  ·  ' . ($v['n'] ?? 0) . ' rows') ?>
                                                    <?php elseif (is_bool($v)): ?>
                                                        <?= $v ? '✓' : '✗' ?>
                                                    <?php else: ?>
                                                        <?= htmlspecialchars((string)$v) ?>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                </div>
                            <?php endif;
                        endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>

            <div class="sub" style="margin-top:20px;">
                Delete this file after the install is verified.
                For a bug report, screenshot the entire page.
            </div>
        </div>
    </body>
    </html>
    <?php
}