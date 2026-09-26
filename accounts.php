<?php
// accounts.php - chart of accounts + per-account ledger.
//
// Balances come from ac000 (which = SUM of en000 per Alameen's own
// aggregate). Ledger comes from en000 directly. Debit/Credit are stored
// in USD and divided by the account's CurrencyVal for display.
require_once 'config.php';
require_once 'auth.php';

$isApiRequest = isset($_GET['list']) || isset($_GET['account'])
             || isset($_GET['ledger']) || isset($_GET['kpi']);
requireLogin($isApiRequest);

$NULL_GUID = '00000000-0000-0000-0000-000000000000';

// ─── KPI: the seven root accounts ────────────────────────────────
if (isset($_GET['kpi']) && $_GET['kpi'] == 1) {
    header('Content-Type: application/json');
    try {
        $pdo = getDBConnection();
        $sql = "SELECT a.Number, a.Name, a.Code, a.Debit, a.Credit,
                       a.CurrencyVal, cur.Name AS CurrencyName
                FROM ac000 a
                LEFT JOIN my000 cur ON a.CurrencyGUID = cur.GUID
                WHERE a.ParentGUID = :root
                  AND a.Code IN ('1','2','3','4','5','6','7')
                ORDER BY a.Code";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':root' => $NULL_GUID]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $kpis = [];
        foreach ($rows as $r) {
            $cv     = (float)($r['CurrencyVal'] ?? 1) ?: 1;
            $debit  = (float)$r['Debit']  / $cv;
            $credit = (float)$r['Credit'] / $cv;
            // Nature of each account class: 1,5,6 → debit-nature; 2,3,4,7 → credit-nature
            $isDebitNature = in_array($r['Code'], ['1','5','6'], true);
            $kpis[] = [
                'code'         => $r['Code'],
                'name'         => $r['Name'],
                'debit'        => $debit,
                'credit'       => $credit,
                'diff'         => $debit - $credit,
                'display'      => $isDebitNature ? ($debit - $credit) : ($credit - $debit),
                'currencyName' => $r['CurrencyName'] ?: 'USD',
            ];
        }
        echo json_encode(['kpis' => $kpis], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        jsonFail($e, 500, 'Could not load KPIs.');
    }
    exit;
}

// ─── List: paginated + searchable + sortable ─────────────────────
if (isset($_GET['list']) && $_GET['list'] == 1) {
    header('Content-Type: application/json');

    $search      = isset($_GET['search']) && $_GET['search'] !== '' ? trim($_GET['search']) : null;
    $showParents = isset($_GET['showParents']) && $_GET['showParents'] == 1;
    $offset      = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
    $limit       = 100;

    $sort    = $_GET['sort'] ?? 'code';
    $dir     = ($_GET['dir'] ?? 'asc') === 'asc' ? 'ASC' : 'DESC';
    $sortMap = [
        'code'   => 'a.Code',
        'name'   => 'a.Name',
        'debit'  => 'a.Debit',
        'credit' => 'a.Credit',
        'diff'   => '(a.Debit - a.Credit)',
    ];
    $orderCol = $sortMap[$sort] ?? 'a.Code';

    try {
        $pdo    = getDBConnection();
        $where  = [];
        $params = [];

        if ($search !== null) {
            $where[] = "(a.Name LIKE :s1 OR a.Code LIKE :s2)";
            $params[':s1'] = '%' . $search . '%';
            $params[':s2'] = '%' . $search . '%';
        } elseif (!$showParents) {
            $where[] = "a.NSons = 0";
        }

        $whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

        $sql = "SELECT a.GUID, a.Number, a.Name, a.Code, a.Debit, a.Credit,
                       a.CurrencyVal, a.NSons, cur.Name AS CurrencyName
                FROM ac000 a
                LEFT JOIN my000 cur ON a.CurrencyGUID = cur.GUID
                $whereSql
                ORDER BY $orderCol $dir
                OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':offset', $offset,     PDO::PARAM_INT);
        $stmt->bindValue(':limit',  $limit + 1,  PDO::PARAM_INT);   // +1 = detect more
        $stmt->execute();

        $rows    = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);

        $accounts = [];
        foreach ($rows as $r) {
            $cv = (float)($r['CurrencyVal'] ?? 1) ?: 1;
            $accounts[] = [
                'GUID'         => $r['GUID'],
                'Number'       => $r['Number'],
                'Name'         => $r['Name'],
                'Code'         => $r['Code'],
                'Debit'        => (float)$r['Debit']  / $cv,
                'Credit'       => (float)$r['Credit'] / $cv,
                'Diff'         => ((float)$r['Debit'] - (float)$r['Credit']) / $cv,
                'NSons'        => (int)$r['NSons'],
                'CurrencyName' => $r['CurrencyName'] ?: 'USD',
            ];
        }

        echo json_encode(
            ['accounts' => $accounts, 'hasMore' => $hasMore],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    } catch (Throwable $e) {
        jsonFail($e, 500, 'Could not load accounts.');
    }
    exit;
}

// ─── Account detail + children ───────────────────────────────────
if (isset($_GET['account']) && $_GET['account'] == 1 && isset($_GET['guid'])) {
    header('Content-Type: application/json');
    $guid = $_GET['guid'];
    if (!preg_match('/^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/', $guid)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid GUID']);
        exit;
    }

    try {
        $pdo = getDBConnection();

        $stmt = $pdo->prepare("
            SELECT a.GUID, a.Number, a.Name, a.Code, a.CDate, a.Debit, a.Credit,
                   a.CurrencyVal, a.NSons, a.ParentGUID, a.Notes, a.Security,
                   cur.Name AS CurrencyName,
                   par.Name AS ParentName, par.Code AS ParentCode
            FROM ac000 a
            LEFT JOIN my000 cur ON a.CurrencyGUID = cur.GUID
            LEFT JOIN ac000 par ON a.ParentGUID = par.GUID AND par.GUID <> :root
            WHERE a.GUID = :guid
        ");
        $stmt->execute([':guid' => $guid, ':root' => $NULL_GUID]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            http_response_code(404);
            echo json_encode(['error' => 'Account not found']);
            exit;
        }

        $cv = (float)($account['CurrencyVal'] ?? 1) ?: 1;
        $account['CurrencyVal']  = $cv;
        $account['DebitRaw']     = (float)$account['Debit'];
        $account['CreditRaw']    = (float)$account['Credit'];
        $account['Debit']        = (float)$account['Debit']  / $cv;
        $account['Credit']       = (float)$account['Credit'] / $cv;
        $account['Diff']         = $account['Debit'] - $account['Credit'];
        $account['CDate']        = isoStamp($account['CDate'] ?? null);
        $account['NSons']        = (int)$account['NSons'];
        if ($account['ParentGUID'] === $NULL_GUID) {
            $account['ParentName'] = null;
            $account['ParentCode'] = null;
        }

        // Children — only when this account has any
        $children = [];
        if ($account['NSons'] > 0) {
            $childStmt = $pdo->prepare("
                SELECT a.GUID, a.Number, a.Name, a.Code, a.Debit, a.Credit,
                       a.CurrencyVal, a.NSons, cur.Name AS CurrencyName
                FROM ac000 a
                LEFT JOIN my000 cur ON a.CurrencyGUID = cur.GUID
                WHERE a.ParentGUID = :guid
                ORDER BY a.Code
            ");
            $childStmt->execute([':guid' => $guid]);
            foreach ($childStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $ccv = (float)($c['CurrencyVal'] ?? 1) ?: 1;
                $children[] = [
                    'GUID'         => $c['GUID'],
                    'Number'       => $c['Number'],
                    'Name'         => $c['Name'],
                    'Code'         => $c['Code'],
                    'Debit'        => (float)$c['Debit']  / $ccv,
                    'Credit'       => (float)$c['Credit'] / $ccv,
                    'Diff'         => ((float)$c['Debit'] - (float)$c['Credit']) / $ccv,
                    'NSons'        => (int)$c['NSons'],
                    'CurrencyName' => $c['CurrencyName'] ?: 'USD',
                ];
            }
        }

        echo json_encode([
            'account'  => $account,
            'children' => $children,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        jsonFail($e, 500, 'Could not load account.');
    }
    exit;
}

// ─── Ledger: paginated entries from en000 ────────────────────────
if (isset($_GET['ledger']) && $_GET['ledger'] == 1 && isset($_GET['guid'])) {
    header('Content-Type: application/json');
    $guid = $_GET['guid'];
    if (!preg_match('/^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/', $guid)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid GUID']);
        exit;
    }
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
    $limit  = 100;

    try {
        $pdo = getDBConnection();

        // Fetch the account's own rate so every row on this page uses one consistent conversion
        $cvStmt = $pdo->prepare("SELECT CurrencyVal FROM ac000 WHERE GUID = :guid");
        $cvStmt->execute([':guid' => $guid]);
        $cvRow = $cvStmt->fetch(PDO::FETCH_ASSOC);
        $acv   = (float)($cvRow['CurrencyVal'] ?? 1) ?: 1;

        $sql = "SELECT e.GUID, e.Number, e.Date, e.Notes, e.Debit, e.Credit,
                       e.ContraAccGUID, ca.Name AS ContraAccName,
                       e.CustomerGUID, cu.CustomerName
                FROM en000 e
                LEFT JOIN ac000 ca ON e.ContraAccGUID = ca.GUID
                LEFT JOIN cu000 cu ON e.CustomerGUID = cu.GUID
                WHERE e.AccountGUID = :guid
                ORDER BY e.Date DESC, e.Number DESC
                OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':guid',   $guid);
        $stmt->bindValue(':offset', $offset,    PDO::PARAM_INT);
        $stmt->bindValue(':limit',  $limit + 1, PDO::PARAM_INT);
        $stmt->execute();

        $rows    = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);

        $entries = [];
        foreach ($rows as $r) {
            $debit  = (float)$r['Debit']  / $acv;
            $credit = (float)$r['Credit'] / $acv;
            $entries[] = [
                'GUID'          => $r['GUID'],
                'Number'        => $r['Number'],
                'Date'          => isoStamp($r['Date'] ?? null),
                'Notes'         => $r['Notes'],
                'Debit'         => $debit,
                'Credit'        => $credit,
                'Net'           => $debit - $credit,   // +ve = debit side, -ve = credit side
                'ContraAccName' => $r['ContraAccName'],
                'CustomerName'  => $r['CustomerName'],
            ];
        }

        echo json_encode([
            'entries'     => $entries,
            'hasMore'     => $hasMore,
            'currencyVal' => $acv,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        jsonFail($e, 500, 'Could not load ledger.');
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الحسابات - QuantuSphere Web</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;900&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap" rel="stylesheet">
    <!-- Local icon font — Tabler Icons (MIT) -->
    <link rel="stylesheet" href="assets/icons/tabler/tabler-icons.min.css">
    <script>
        (function () {
            try {
                var saved = localStorage.getItem('dashboardTheme');
                if (saved === 'light') saved = 'warm';
                if (saved === 'warm' || saved === 'bright' || saved === 'dark' || saved === 'classic') {
                    document.documentElement.setAttribute('data-theme', saved);
                }
            } catch (e) {}
        })();
    </script>
    <style>
        * { box-sizing: border-box; }
        :root {
            --primary: #B8863C; --primary-dark: #96692C;
            --primary-light: rgba(184,134,60,0.10);
            --accent: #2F4C3B; --success: #2F4C3B; --danger: #A23B2E;
            --bg: #F6F1E4; --surface: #FBF8F0; --text: #1D2A35;
            --text-muted: #5B6672; --border: #C9C0AC; --radius: 6px;
            --shadow-sm: none; --shadow-md: 0 2px 8px rgba(29,42,53,0.08);
            --font-num: 'Fraunces', Georgia, serif;
            --header-bg: #1D2A35; --header-fg: #F6F1E4;
            color-scheme: light;
        }
        @media (prefers-color-scheme: dark) {
            :root:not([data-theme]) {
                --primary: #D9A855; --primary-dark: #B8863C;
                --primary-light: rgba(217,168,85,0.16);
                --accent: #4C7B60; --success: #4C7B60; --danger: #C96354;
                --bg: #12181F; --surface: #1B232C; --text: #EDE6D6;
                --text-muted: #8C97A3; --border: #2B3542; color-scheme: dark;
            }
        }
        :root[data-theme="dark"] {
            --primary: #D9A855; --primary-dark: #B8863C;
            --primary-light: rgba(217,168,85,0.16);
            --accent: #4C7B60; --success: #4C7B60; --danger: #C96354;
            --bg: #12181F; --surface: #1B232C; --text: #EDE6D6;
            --text-muted: #8C97A3; --border: #2B3542; color-scheme: dark;
        }
        :root[data-theme="bright"] {
            --bg: #FFFFFF; --surface: #FFFFFF; --text: #1D2A35;
            --text-muted: #5B6672; --border: #E2E5E9; color-scheme: light;
        }
        :root[data-theme="classic"] {
            --primary: #4f46e5; --primary-dark: #4338ca;
            --primary-light: #eef2ff;
            --accent: #06b6d4; --success: #10b981; --danger: #ef4444;
            --bg: #f4f5fa; --surface: #ffffff; --text: #1e1b2e;
            --text-muted: #6b7280; --border: #e5e7eb; --radius: 14px;
            --font-num: 'Tajawal', sans-serif; color-scheme: light;
        }
        body {
            font-family: 'Tajawal', 'Segoe UI', Tahoma, Verdana, sans-serif;
            background: var(--bg); color: var(--text);
            margin: 0; padding: 16px; min-height: 100vh;
        }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-thumb { background: #C9C0AC; border-radius: 8px; }
        ::-webkit-scrollbar-thumb:hover { background: #B8863C; }

        .page { max-width: 1500px; margin: 0 auto; }

        .page-header {
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px; margin-bottom: 16px; flex-wrap: wrap;
        }
        h1 { font-size: 1.4rem; font-weight: 900; margin: 0; display: flex; align-items: center; gap: 8px; }

        .topbar-start { display: flex; align-items: center; gap: 12px; }
        .topbar-start h1 { margin: 0; }

        /* ---------- Hamburger menu ---------- */
        .menu-wrap { position: relative; }
        .menu-toggle-btn {
            display: inline-flex; align-items: center; justify-content: center;
            width: 36px; height: 36px;
            background: var(--surface); border: 1px solid var(--border);
            border-radius: 10px; color: var(--text);
            font-size: 1.05rem; font-family: inherit; cursor: pointer;
            transition: all 0.15s;
        }
        .menu-toggle-btn:hover { border-color: var(--primary); color: var(--primary); }
        .menu-toggle-btn.open {
            background: var(--primary); border-color: var(--primary); color: white;
        }
        .menu-dropdown {
            display: none; position: absolute;
            top: calc(100% + 8px); right: 0;
            width: max-content; max-width: calc(100vw - 24px);
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: 0 10px 30px rgba(29,42,53,0.15);
            padding: 6px; z-index: 300;
        }
        .menu-dropdown.open { display: block; animation: menuFade 0.12s ease-out; }
        @keyframes menuFade {
            from { opacity: 0; transform: translateY(-4px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .menu-dropdown .menu-item,
        .menu-dropdown .menu-section-title { white-space: nowrap; }
        .menu-section { padding: 4px 0; }
        .menu-section + .menu-section {
            border-top: 1px solid var(--border);
            margin-top: 4px; padding-top: 8px;
        }
        .menu-section-title {
            font-size: 0.68rem; color: var(--text-muted); font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.4px;
            padding: 4px 12px 6px;
        }
        .menu-item {
            display: flex; align-items: center; gap: 10px;
            width: 100%; padding: 9px 12px;
            background: none; border: none; border-radius: 8px;
            color: var(--text); font-family: inherit; font-size: 0.85rem;
            font-weight: 600; text-align: right; text-decoration: none;
            cursor: pointer; transition: background 0.12s;
        }
        .menu-item:hover { background: var(--primary-light); }
        .menu-item-icon { font-size: 1rem; width: 20px; text-align: center; flex-shrink: 0; }
        .menu-item-danger { color: var(--danger); }
        .menu-item-danger:hover { background: rgba(162,59,46,0.08); }

        /* ---------- Buttons ---------- */
        .btn {
            display: inline-flex; align-items: center; gap: 6px;
            background: var(--surface); border: 1px solid var(--border);
            padding: 7px 12px; border-radius: 999px;
            font-size: 0.78rem; font-weight: 700; color: var(--text-muted);
            font-family: inherit; cursor: pointer; text-decoration: none;
            transition: all 0.15s;
        }
        .btn:hover { border-color: var(--primary); color: var(--primary); }
        .btn-primary { background: var(--primary); border-color: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); }

        /* ---------- Search bar ---------- */
        .search-bar {
            display: flex; align-items: center; gap: 12px;
            margin-bottom: 16px;
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 10px 14px;
            flex-wrap: wrap;
        }
        .search-bar input[type="text"] {
            flex: 1; min-width: 180px;
            padding: 8px 14px;
            border: 1px solid var(--border); border-radius: 999px;
            background: var(--bg); color: var(--text);
            font-family: inherit; font-size: 0.85rem;
        }
        .search-bar input[type="text"]:focus {
            outline: none; border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-light);
        }
        .toggle-chip {
            display: inline-flex; align-items: center; gap: 6px;
            background: var(--surface); border: 1px solid var(--border);
            padding: 7px 12px; border-radius: 999px;
            font-size: 0.78rem; font-weight: 700; color: var(--text-muted);
            font-family: inherit; cursor: pointer;
            transition: all 0.15s;
        }
        .toggle-chip:hover { border-color: var(--primary); color: var(--primary); }
        .toggle-chip.active {
            background: var(--primary); border-color: var(--primary); color: white;
        }

        /* ---------- KPI strip ---------- */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px; margin-bottom: 18px;
        }
        .kpi-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 12px 14px; text-align: right;
        }
        .kpi-label {
            font-size: 0.72rem; color: var(--text-muted); font-weight: 700;
            margin-bottom: 6px; white-space: nowrap; overflow: hidden;
            text-overflow: ellipsis;
        }
        .kpi-value {
            font-family: var(--font-num); font-variant-numeric: tabular-nums;
            font-size: 1.15rem; font-weight: 700; color: var(--text);
            line-height: 1.2;
        }
        .kpi-currency {
            font-size: 0.7rem; color: var(--text-muted); font-weight: 700;
            margin-right: 4px;
        }
        .kpi-card.kpi-assets    { border-color: #2F4C3B; }
        .kpi-card.kpi-assets .kpi-value    { color: #2F4C3B; }
        .kpi-card.kpi-liab      { border-color: var(--danger); }
        .kpi-card.kpi-liab .kpi-value      { color: var(--danger); }
        .kpi-card.kpi-equity    { border-color: var(--primary); }
        .kpi-card.kpi-equity .kpi-value    { color: var(--primary-dark); }
        .kpi-card.kpi-profit    { border-color: var(--primary); background: var(--primary-light); }
        .kpi-card.kpi-profit .kpi-value    { color: var(--primary-dark); font-size: 1.4rem; }

        /* ---------- Section ---------- */
        .section {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); margin-bottom: 16px; overflow: hidden;
        }
        .section-title {
            margin: 0; padding: 12px 18px; font-size: 0.95rem; font-weight: 700;
            background: var(--header-bg); color: var(--header-fg);
            border-bottom: 2px solid var(--primary);
            display: flex; align-items: center; justify-content: space-between;
            gap: 8px;
        }
        .section-title .muted { font-size: 0.78rem; font-weight: 600; opacity: 0.8; }

        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        th {
            background: var(--bg); color: var(--text); font-weight: 700;
            padding: 10px 12px; border-bottom: 1px solid var(--border);
            text-align: right; white-space: nowrap;
        }
        td {
            padding: 10px 12px; border-bottom: 1px solid var(--border);
            text-align: right; color: var(--text); vertical-align: top;
        }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover td { background: var(--primary-light); }
        tbody tr.clickable { cursor: pointer; }
        .num { font-family: var(--font-num); font-variant-numeric: tabular-nums; }
        .muted { color: var(--text-muted); }
        .code-cell {
            font-family: var(--font-num); font-variant-numeric: tabular-nums;
            color: var(--text-muted); font-size: 0.78rem; letter-spacing: 0.5px;
        }
        .parent-badge {
            display: inline-block; font-size: 0.65rem; font-weight: 700;
            color: var(--primary-dark); background: var(--primary-light);
            padding: 1px 6px; border-radius: 4px; margin-right: 6px;
        }
        .pos { color: var(--danger); font-weight: 700; }
        .neg { color: var(--accent); font-weight: 700; }
        .zero { color: var(--text-muted); }

        th.sortable { cursor: pointer; user-select: none; white-space: nowrap; }
        th.sortable:hover { color: var(--primary-dark); }
        th.sortable.sort-active { color: var(--primary-dark); }
        .sort-ind {
            display: inline-block; width: 12px; margin-right: 4px;
            color: var(--text-muted); font-size: 0.7rem;
        }
        th.sortable.sort-active .sort-ind { color: var(--primary-dark); font-weight: 900; }

        .load-more {
            display: block; width: 100%; padding: 11px;
            background: var(--bg); border: none;
            border-top: 1px solid var(--border);
            color: var(--primary-dark); font-weight: 700;
            font-size: 0.85rem; font-family: inherit; cursor: pointer;
        }
        .load-more:hover { background: var(--primary-light); }

        /* ---------- Detail view ---------- */
        .cust-header {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 16px 20px; margin-bottom: 16px;
        }
        .cust-header h2 { margin: 0 0 4px; font-size: 1.3rem; font-weight: 900; }
        .cust-header .sub { color: var(--text-muted); font-size: 0.85rem; }

        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px; }
        @media (max-width: 700px) { .grid-2 { grid-template-columns: 1fr; } }

        .info-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 14px 18px;
        }
        .info-card h3 {
            margin: 0 0 12px; font-size: 0.9rem; font-weight: 800;
            color: var(--primary-dark); border-bottom: 1px solid var(--border);
            padding-bottom: 8px;
        }
        .info-row {
            display: flex; justify-content: space-between; gap: 12px;
            padding: 6px 0; font-size: 0.85rem;
            border-bottom: 1px dashed var(--border);
        }
        .info-row:last-child { border-bottom: none; }
        .info-row .lbl { color: var(--text-muted); font-weight: 600; flex-shrink: 0; }
        .info-row .val { text-align: left; word-break: break-word; }
        .info-row .val.num { font-family: var(--font-num); font-variant-numeric: tabular-nums; font-weight: 700; }

        .balance-row {
            display: flex; align-items: baseline; justify-content: space-between;
            padding: 8px 0; font-size: 0.95rem;
            border-bottom: 1px dashed var(--border);
        }
        .balance-row:last-child { border-bottom: none; padding-top: 12px; }
        .balance-row .lbl { color: var(--text-muted); font-weight: 700; }
        .balance-row .val {
            font-family: var(--font-num); font-variant-numeric: tabular-nums;
            font-weight: 700; font-size: 1.1rem;
        }
        .balance-row.grand .val { font-size: 1.4rem; }

        .loading, .empty {
            padding: 30px; text-align: center;
            color: var(--text-muted); font-size: 0.85rem;
        }

        @media (max-width: 600px) {
            body { padding: 10px; }
            h1 { font-size: 1.1rem; }
            th, td { padding: 8px 6px; font-size: 0.75rem; }
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
            .kpi-value { font-size: 1rem; }
            .kpi-card.kpi-profit .kpi-value { font-size: 1.2rem; }
            .search-bar { padding: 8px 10px; }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="page-header">
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
                        </div>
                        <div class="menu-section">
                            <a href="logout.php" class="menu-item menu-item-danger">
                                <span class="menu-item-icon">🚪</span>
                                <span>تسجيل الخروج</span>
                            </a>
                        </div>
                    </div>
                </div>
                <h1>💰 الحسابات</h1>
            </div>
        </div>

        <!-- LIST VIEW -->
        <div id="listView">
            <!-- KPI strip -->
            <div class="kpi-grid" id="kpiGrid">
                <div class="kpi-card"><div class="kpi-label">جاري التحميل...</div><div class="kpi-value">—</div></div>
            </div>

            <!-- Search + filters -->
            <div class="search-bar">
                <input type="text" id="searchInput"
                       placeholder="🔍 ابحث بالاسم أو الكود..."
                       autocomplete="off" spellcheck="false">
                <button type="button" class="toggle-chip" id="showParentsBtn"
                        title="عرض الحسابات الأب (الرئيسية) أيضاً">
                    📁 <span>إظهار الحسابات الأب</span>
                </button>
            </div>

            <div class="section">
                <h2 class="section-title">
                    <span>📒 دفتر الحسابات <span class="muted" id="listCount"></span></span>
                </h2>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th class="sortable list-sortable" data-sort="name">الاسم <span class="sort-ind"></span></th>
                                <th class="sortable list-sortable" data-sort="code">الكود <span class="sort-ind"></span></th>
                                <th>العملة</th>
                                <th class="sortable list-sortable" data-sort="debit">مدين <span class="sort-ind"></span></th>
                                <th class="sortable list-sortable" data-sort="credit">دائن <span class="sort-ind"></span></th>
                                <th class="sortable list-sortable" data-sort="diff">الفرق <span class="sort-ind"></span></th>
                            </tr>
                        </thead>
                        <tbody id="listBody">
                            <tr><td colspan="7" class="loading">جاري التحميل...</td></tr>
                        </tbody>
                    </table>
                </div>
                <button type="button" class="load-more" id="loadMoreBtn" style="display:none;">➕ تحميل المزيد</button>
            </div>
        </div>

        <!-- DETAIL VIEW -->
        <div id="detailView" style="display:none;">
            <div class="cust-header">
                <h2 id="detailName">—</h2>
                <div class="sub" id="detailSub">—</div>
            </div>

            <div class="grid-2">
                <div class="info-card">
                    <h3>📋 معلومات الحساب</h3>
                    <div id="infoBody"></div>
                </div>
                <div class="info-card">
                    <h3>💰 الرصيد</h3>
                    <div id="balanceBody"></div>
                </div>
            </div>

            <!-- Children (only shown when the account has sub-accounts) -->
            <div class="section" id="childrenSection" style="display:none;">
                <h2 class="section-title">
                    <span>🌿 الحسابات الفرعية <span class="muted" id="childrenCount"></span></span>
                </h2>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>الاسم</th>
                                <th>الكود</th>
                                <th>العملة</th>
                                <th class="num">مدين</th>
                                <th class="num">دائن</th>
                                <th class="num">الفرق</th>
                            </tr>
                        </thead>
                        <tbody id="childrenBody"></tbody>
                    </table>
                </div>
            </div>

            <!-- Ledger -->
            <div class="section">
                <h2 class="section-title">
                    <span>📄 الحركات <span class="muted" id="ledgerCount"></span></span>
                </h2>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>التاريخ</th>
                                <th>الرقم</th>
                                <th>البيان</th>
                                <th class="num">مدين</th>
                                <th class="num">دائن</th>
                                <th class="num">الرصيد التراكمي</th>
                            </tr>
                        </thead>
                        <tbody id="ledgerBody">
                            <tr><td colspan="6" class="loading">جاري التحميل...</td></tr>
                        </tbody>
                    </table>
                </div>
                <button type="button" class="load-more" id="ledgerMoreBtn" style="display:none;">➕ تحميل المزيد</button>
            </div>
        </div>
    </div>

    <script>
        // ---------- Helpers ----------
        const _numFmt = new Intl.NumberFormat('en-US', {
            minimumFractionDigits: 2, maximumFractionDigits: 2
        });
        function fmtNum(v) {
            const n = parseFloat(v);
            return isFinite(n) ? _numFmt.format(n) : '0.00';
        }
        function escapeHtml(str) {
            const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
            return String(str === null || str === undefined ? '' : str).replace(/[&<>"']/g, m => map[m]);
        }
        function balanceClass(v) {
            if (v > 0.005)  return 'pos';
            if (v < -0.005) return 'neg';
            return 'zero';
        }

        // ---------- Theme toggle ----------
        (function initThemeToggle() {
            const btn  = document.getElementById('themeToggleBtn');
            const icon = document.getElementById('themeToggleIcon');
            if (!btn || !icon) return;
            const THEMES = ['warm', 'bright', 'dark', 'classic'];
            const ICONS  = { warm: '📜', bright: '☀️', dark: '🌙', classic: '🔷' };
            function explicit() {
                const v = document.documentElement.getAttribute('data-theme');
                return THEMES.includes(v) ? v : null;
            }
            function effective() {
                return explicit() || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'warm');
            }
            function update() { icon.textContent = ICONS[effective()]; }
            update();
            btn.addEventListener('click', () => {
                const cur  = effective();
                const next = THEMES[(THEMES.indexOf(cur) + 1) % THEMES.length];
                document.documentElement.setAttribute('data-theme', next);
                try { localStorage.setItem('dashboardTheme', next); } catch (e) {}
                update();
            });
        })();

        // ---------- Hamburger menu ----------
        (function initTopMenu() {
            const wrap = document.getElementById('menuWrap');
            const btn  = document.getElementById('menuToggleBtn');
            const menu = document.getElementById('menuDropdown');
            if (!wrap || !btn || !menu) return;
            function open()  { menu.classList.add('open');  btn.classList.add('open');  btn.setAttribute('aria-expanded', 'true'); }
            function close() { menu.classList.remove('open'); btn.classList.remove('open'); btn.setAttribute('aria-expanded', 'false'); }
            function isOpen(){ return menu.classList.contains('open'); }
            btn.addEventListener('click', (e) => { e.stopPropagation(); isOpen() ? close() : open(); });
            document.addEventListener('mousedown', (e) => { if (isOpen() && !wrap.contains(e.target)) close(); });
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && isOpen()) { close(); btn.focus(); } });
        })();

        // ---------- Routing ----------
        const urlParams = new URLSearchParams(window.location.search);
        const detailGuid = urlParams.get('guid');
        const isDetail   = !!detailGuid;

        // ---------- State ----------
        let listOffset   = 0;
        let listHasMore  = true;
        let currentSearch = '';
        let showParents  = false;
        let listSort     = { key: 'code', dir: 'asc' };
        let searchTimer  = null;

        // Ledger state
        let ledgerOffset = 0;
        let ledgerHasMore = true;
        let ledgerCurrencyVal = 1;
        let ledgerRunningUSD = 0;
        let ledgerLoadingMore = false;

        // ---------- LIST VIEW ----------
        function updateListSortIndicators() {
            document.querySelectorAll('#listView th.list-sortable').forEach(th => {
                const ind = th.querySelector('.sort-ind');
                if (th.dataset.sort === listSort.key) {
                    th.classList.add('sort-active');
                    if (ind) ind.textContent = listSort.dir === 'asc' ? '▲' : '▼';
                } else {
                    th.classList.remove('sort-active');
                    if (ind) ind.textContent = '';
                }
            });
        }

        document.querySelectorAll('#listView th.list-sortable').forEach(th => {
            th.addEventListener('click', () => {
                const key = th.dataset.sort;
                if (listSort.key === key) {
                    listSort.dir = listSort.dir === 'asc' ? 'desc' : 'asc';
                } else {
                    listSort.key = key;
                    listSort.dir = (key === 'name' || key === 'code') ? 'asc' : 'desc';
                }
                updateListSortIndicators();
                loadAccounts(true);
            });
        });

        const searchInput   = document.getElementById('searchInput');
        const showParentsBtn = document.getElementById('showParentsBtn');

        searchInput.addEventListener('input', () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                currentSearch = searchInput.value.trim();
                loadAccounts(true);
            }, 350);
        });

        showParentsBtn.addEventListener('click', () => {
            showParents = !showParents;
            showParentsBtn.classList.toggle('active', showParents);
            loadAccounts(true);
        });

        document.getElementById('loadMoreBtn').addEventListener('click', () => {
            if (!listHasMore) return;
            loadAccounts(false);
        });

        async function loadAccounts(reset) {
            if (reset) {
                listOffset  = 0;
                listHasMore = true;
                document.getElementById('listBody').innerHTML =
                    '<tr><td colspan="7" class="loading">جاري التحميل...</td></tr>';
            }

            const params = new URLSearchParams({
                list: '1',
                offset: listOffset,
                sort: listSort.key,
                dir:  listSort.dir,
            });
            if (currentSearch) params.set('search', currentSearch);
            if (showParents)   params.set('showParents', '1');

            try {
                const resp = await fetch('?' + params.toString(), { cache: 'no-store' });
                if (resp.status === 401) { window.location.href = 'login.php'; return; }
                const data = await resp.json();
                if (!data || data.error) {
                    document.getElementById('listBody').innerHTML =
                        `<tr><td colspan="7" class="loading">خطأ: ${escapeHtml(data.error || '')}</td></tr>`;
                    return;
                }

                const body = document.getElementById('listBody');
                if (reset) body.innerHTML = '';

                if (!data.accounts.length && reset) {
                    body.innerHTML = '<tr><td colspan="7" class="empty">لا توجد نتائج.</td></tr>';
                } else {
                    for (const a of data.accounts) {
                        const tr = document.createElement('tr');
                        tr.className = 'clickable';
                        tr.onclick = () => { window.location.href = 'accounts.php?guid=' + encodeURIComponent(a.GUID); };
                        const parentBadge = a.NSons > 0
                            ? `<span class="parent-badge" title="${a.NSons} حساب فرعي">📁 ${a.NSons}</span>`
                            : '';
                        tr.innerHTML = `
                            <td class="num muted">${escapeHtml(a.Number)}</td>
                            <td>${escapeHtml(a.Name || '-')} ${parentBadge}</td>
                            <td class="code-cell">${escapeHtml(a.Code || '')}</td>
                            <td class="muted">${escapeHtml(a.CurrencyName || '')}</td>
                            <td class="num">${fmtNum(a.Debit)}</td>
                            <td class="num">${fmtNum(a.Credit)}</td>
                            <td class="num ${balanceClass(a.Diff)}"><b>${fmtNum(a.Diff)}</b></td>
                        `;
                        body.appendChild(tr);
                    }
                }

                listHasMore = !!data.hasMore;
                listOffset += data.accounts.length;
                document.getElementById('loadMoreBtn').style.display = listHasMore ? 'block' : 'none';

                const countEl = document.getElementById('listCount');
                if (countEl) countEl.textContent = `(${listOffset}${listHasMore ? '+' : ''})`;
            } catch (e) {
                console.error(e);
                document.getElementById('listBody').innerHTML =
                    `<tr><td colspan="7" class="loading">خطأ في الشبكة: ${escapeHtml(e.message)}</td></tr>`;
            }
        }

        // ---------- KPI strip ----------
        async function loadKPI() {
            try {
                const resp = await fetch('?kpi=1', { cache: 'no-store' });
                if (resp.status === 401) { window.location.href = 'login.php'; return; }
                const data = await resp.json();
                if (!data || !Array.isArray(data.kpis)) return;
                renderKPIs(data.kpis);
            } catch (e) {
                console.error('KPI load failed:', e);
            }
        }

        function renderKPIs(kpis) {
            const grid = document.getElementById('kpiGrid');
            if (!grid) return;

            // Build display cards with class hints for colouring
            const CLASS_MAP = {
                '1': { cls: 'kpi-assets', icon: '🏦' },
                '2': { cls: 'kpi-liab',   icon: '📉' },
                '3': { cls: 'kpi-equity', icon: '⚖️' },
                '4': { cls: '',           icon: '💵' },
                '5': { cls: '',           icon: '📦' },
                '6': { cls: '',           icon: '💸' },
                '7': { cls: '',           icon: '💰' },
            };

            let html = '';
            let totalRev = 0, totalExp = 0;

            for (const k of kpis) {
                const meta = CLASS_MAP[k.code] || { cls: '', icon: '📊' };
                html += `<div class="kpi-card ${meta.cls}">
                    <div class="kpi-label">${meta.icon} ${escapeHtml(k.name)}</div>
                    <div class="kpi-value">${fmtNum(k.display)}<span class="kpi-currency">${escapeHtml(k.currencyName)}</span></div>
                </div>`;
                // Revenue/Expense for net-profit computation (in USD — the KPI values are already in each account's own currency, but the roots are typically USD)
                if (k.code === '4' || k.code === '7') totalRev += k.display;
                if (k.code === '5' || k.code === '6') totalExp += k.display;
            }

            const profit = totalRev - totalExp;
            html += `<div class="kpi-card kpi-profit">
                <div class="kpi-label">💎 صافي الربح</div>
                <div class="kpi-value">${fmtNum(profit)}<span class="kpi-currency">USD</span></div>
            </div>`;

            grid.innerHTML = html;
        }

        // ---------- DETAIL VIEW ----------
        async function loadAccount(guid) {
            try {
                const resp = await fetch('?account=1&guid=' + encodeURIComponent(guid), { cache: 'no-store' });
                if (resp.status === 401) { window.location.href = 'login.php'; return; }
                const data = await resp.json();
                if (!data || data.error) {
                    document.getElementById('detailName').textContent = 'خطأ';
                    document.getElementById('detailSub').textContent  = (data && data.error) || 'Account not found';
                    return;
                }

                const a = data.account;

                document.getElementById('detailName').textContent = a.Name || '(بدون اسم)';
                document.getElementById('detailSub').textContent =
                    'الكود: ' + (a.Code || '-') +
                    (a.ParentName ? ' · الحساب الأب: ' + a.ParentName + ' (' + (a.ParentCode || '') + ')' : '');

                // Info card
                function buildInfoRows(rows) {
                    let out = '';
                    for (const [lbl, val] of rows) {
                        if (val === null || val === undefined || val === '' || val === 0) continue;
                        out += `<div class="info-row"><span class="lbl">${lbl}</span><span class="val">${escapeHtml(String(val))}</span></div>`;
                    }
                    return out || '<div class="empty" style="padding:12px 0;">لا توجد بيانات.</div>';
                }

                document.getElementById('infoBody').innerHTML = buildInfoRows([
                    ['# الرقم',        a.Number],
                    ['🏷️ الكود',       a.Code],
                    ['💱 العملة',      a.CurrencyName || 'USD'],
                    ['📅 تاريخ الإنشاء', a.CDate ? new Date(a.CDate).toLocaleDateString('en-GB') : ''],
                    ['🔒 المستوى الأمني', a.Security],
                    ['👶 عدد الأبناء',  a.NSons],
                    ['📝 ملاحظات',     a.Notes],
                ]);

                // Balance card
                const bHtml = `
                    <div class="balance-row">
                        <span class="lbl">مدين (Debit)</span>
                        <span class="val num">${fmtNum(a.Debit)}</span>
                    </div>
                    <div class="balance-row">
                        <span class="lbl">دائن (Credit)</span>
                        <span class="val num">${fmtNum(a.Credit)}</span>
                    </div>
                    <div class="balance-row grand">
                        <span class="lbl">الرصيد</span>
                        <span class="val num ${balanceClass(a.Diff)}">${fmtNum(a.Diff)}</span>
                    </div>
                    <div class="balance-row" style="padding-top:8px; opacity:0.7;">
                        <span class="lbl" style="font-size:0.75rem;">العملة</span>
                        <span class="val" style="font-size:0.85rem;">${escapeHtml(a.CurrencyName || 'USD')}</span>
                    </div>`;
                document.getElementById('balanceBody').innerHTML = bHtml;

                // Children (only shown when NSons > 0)
                const childSection = document.getElementById('childrenSection');
                if (Array.isArray(data.children) && data.children.length > 0) {
                    childSection.style.display = '';
                    const cb = document.getElementById('childrenBody');
                    let ch = '';
                    for (const c of data.children) {
                        const badge = c.NSons > 0 ? ` <span class="parent-badge">📁 ${c.NSons}</span>` : '';
                        ch += `<tr class="clickable" onclick="window.location.href='accounts.php?guid=${encodeURIComponent(c.GUID)}'">
                            <td class="num muted">${escapeHtml(c.Number)}</td>
                            <td>${escapeHtml(c.Name || '-')}${badge}</td>
                            <td class="code-cell">${escapeHtml(c.Code || '')}</td>
                            <td class="muted">${escapeHtml(c.CurrencyName || '')}</td>
                            <td class="num">${fmtNum(c.Debit)}</td>
                            <td class="num">${fmtNum(c.Credit)}</td>
                            <td class="num ${balanceClass(c.Diff)}"><b>${fmtNum(c.Diff)}</b></td>
                        </tr>`;
                    }
                    cb.innerHTML = ch;
                    document.getElementById('childrenCount').textContent = `(${data.children.length})`;
                } else {
                    childSection.style.display = 'none';
                }

                // Initialize ledger: runningUSD starts from the account's current balance in USD
                ledgerOffset     = 0;
                ledgerHasMore    = true;
                ledgerRunningUSD = a.DebitRaw - a.CreditRaw;
                await loadLedger(true);
            } catch (e) {
                console.error(e);
            }
        }

        // ---------- Ledger ----------
        document.getElementById('ledgerMoreBtn').addEventListener('click', () => {
            if (!ledgerHasMore || ledgerLoadingMore) return;
            ledgerLoadingMore = true;
            loadLedger(false).finally(() => { ledgerLoadingMore = false; });
        });

        async function loadLedger(reset) {
            if (!detailGuid) return;
            if (reset) {
                ledgerOffset  = 0;
                ledgerHasMore = true;
                document.getElementById('ledgerBody').innerHTML =
                    '<tr><td colspan="6" class="loading">جاري التحميل...</td></tr>';
            }

            const params = new URLSearchParams({
                ledger: '1',
                guid:   detailGuid,
                offset: ledgerOffset,
            });

            try {
                const resp = await fetch('?' + params.toString(), { cache: 'no-store' });
                if (resp.status === 401) { window.location.href = 'login.php'; return; }
                const data = await resp.json();
                if (!data || data.error) {
                    document.getElementById('ledgerBody').innerHTML =
                        `<tr><td colspan="6" class="loading">خطأ: ${escapeHtml((data && data.error) || '')}</td></tr>`;
                    return;
                }

                const body = document.getElementById('ledgerBody');
                if (reset) body.innerHTML = '';

                if (!data.entries.length && reset) {
                    body.innerHTML = '<tr><td colspan="6" class="empty">لا توجد حركات على هذا الحساب.</td></tr>';
                } else {
                    // Entries are newest-first. Running balance shown per row is
                    // "the balance immediately after this entry was posted" — so
                    // we start from the current total and subtract as we descend.
                    const frag = document.createDocumentFragment();
                    for (const e of data.entries) {
                        const balanceAfter = ledgerRunningUSD;
                        ledgerRunningUSD -= e.Net;

                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td class="num">${e.Date ? new Date(e.Date).toLocaleDateString('en-GB') : '-'}</td>
                            <td class="num muted">${escapeHtml(e.Number)}</td>
                            <td>${escapeHtml(e.Notes || '-')}</td>
                            <td class="num">${e.Debit  ? fmtNum(e.Debit)  : ''}</td>
                            <td class="num">${e.Credit ? fmtNum(e.Credit) : ''}</td>
                            <td class="num ${balanceClass(balanceAfter)}">${fmtNum(balanceAfter)}</td>
                        `;
                        frag.appendChild(tr);
                    }
                    body.appendChild(frag);
                }

                ledgerHasMore = !!data.hasMore;
                ledgerOffset += data.entries.length;
                document.getElementById('ledgerMoreBtn').style.display = ledgerHasMore ? 'block' : 'none';
                document.getElementById('ledgerCount').textContent = `(${ledgerOffset}${ledgerHasMore ? '+' : ''})`;
            } catch (e) {
                console.error(e);
                document.getElementById('ledgerBody').innerHTML =
                    `<tr><td colspan="6" class="loading">خطأ في الشبكة: ${escapeHtml(e.message)}</td></tr>`;
            }
        }

        // ---------- Bootstrap ----------
        if (isDetail) {
            document.getElementById('listView').style.display   = 'none';
            document.getElementById('detailView').style.display = 'block';
            loadAccount(detailGuid);
        } else {
            updateListSortIndicators();
            loadKPI();
            loadAccounts(true);
        }
    </script>
</body>
</html>