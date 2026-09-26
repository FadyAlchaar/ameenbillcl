<?php
// salesmen.php - sales leaderboard + per-salesman drill-down.
//
// Attribution: bu000.StoreGUID → sm000.StoreGUID → sm000.Name.
// SalesManPtr on bu000 is not populated in this Alameen install, so the
// warehouse-to-salesman mapping is the reliable join.
require_once 'config.php';
require_once 'auth.php';

$isApiRequest = isset($_GET['list']) || isset($_GET['salesman']);
requireLogin($isApiRequest);

// ─── API: ranked salesmen for a date range ───────────────────────
if (isset($_GET['list']) && $_GET['list'] == 1) {
    header('Content-Type: application/json');

    $fromRaw = $_GET['from'] ?? null;
    $toRaw   = $_GET['to']   ?? null;
    $sort    = $_GET['sort'] ?? 'revenue';
    $dir     = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

    try {
        $from = $fromRaw ? new DateTime($fromRaw) : new DateTime('today');
        $to   = $toRaw   ? new DateTime($toRaw)   : new DateTime('today');
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid date']);
        exit;
    }
    if ($from > $to) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid date range']);
        exit;
    }

    $fromStart = $from->format('Y-m-d') . ' 00:00:00';
    $toEnd     = (clone $to)->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

    $sortMap = [
        'revenue' => 'RevenueUSD',
        'bills'   => 'BillCount',
        'avg'     => 'AvgBillUSD',
        'cash'    => 'CashUSD',
        'credit'  => 'CreditUSD',
        'name'    => 'SalesmanName',
    ];
    $orderCol = $sortMap[$sort] ?? 'RevenueUSD';

    try {
        $pdo = getDBConnection();

                // Distributor000 is Alameen's salesman master for the Distribution
        // subsystem. Some warehouses aren't mapped to a salesman (the main
        // counter, or a salesman that hasn't been registered) — those fall
        // back to st000.Name so the totals reconcile.
        $sql = "SELECT
                    d.GUID            AS DistributorGUID,
                    b.StoreGUID       AS StoreGUID,
                    COALESCE(d.Name, s.Name)  AS SalesmanName,
                    CASE WHEN d.GUID IS NULL THEN 1 ELSE 0 END AS IsUnmapped,
                    d.Number          AS SalesmanNumber,
                    COUNT(*)          AS BillCount,
                    ISNULL(SUM(b.Total - b.TotalDisc + b.TotalExtra), 0) AS RevenueUSD,
                    ISNULL(SUM(b.Total - b.TotalDisc + b.TotalExtra) / NULLIF(COUNT(*), 0), 0) AS AvgBillUSD,
                    ISNULL(SUM(CASE WHEN b.PayType = 0 THEN b.Total - b.TotalDisc + b.TotalExtra ELSE 0 END), 0) AS CashUSD,
                    ISNULL(SUM(CASE WHEN b.PayType = 1 THEN b.Total - b.TotalDisc + b.TotalExtra ELSE 0 END), 0) AS CreditUSD
                FROM bu000 b
                LEFT JOIN Distributor000 d ON b.StoreGUID = d.StoreGUID
                LEFT JOIN st000 s          ON b.StoreGUID = s.GUID
                WHERE b.Date >= :fromStart AND b.Date < :toEnd
                GROUP BY d.GUID, d.Number, b.StoreGUID, COALESCE(d.Name, s.Name),
                         CASE WHEN d.GUID IS NULL THEN 1 ELSE 0 END
                ORDER BY $orderCol $dir";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':fromStart' => $fromStart, ':toEnd' => $toEnd]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Merge rows that share the same Distributor GUID. Unmapped rows
        // are keyed by their StoreGUID so the main warehouse and an
        // unregistered salesman remain separate lines.
        $bySalesman = [];
        foreach ($rows as $r) {
            $key = $r['DistributorGUID'] ?: ('STORE:' . $r['StoreGUID']);
            if (!isset($bySalesman[$key])) {
                $bySalesman[$key] = [
                    'GUID'       => $r['DistributorGUID'],   // NULL for unmapped
                    'StoreGUID'  => $r['StoreGUID'],
                    'Number'     => $r['SalesmanNumber'],
                    'Name'       => $r['SalesmanName'] ?: '(غير معروف)',
                    'IsUnmapped' => (int)$r['IsUnmapped'],
                    'BillCount'  => 0,
                    'RevenueUSD' => 0.0,
                    'CashUSD'    => 0.0,
                    'CreditUSD'  => 0.0,
                    'AvgBillUSD' => 0.0,
                ];
            }
            $bySalesman[$key]['BillCount']  += (int)$r['BillCount'];
            $bySalesman[$key]['RevenueUSD'] += (float)$r['RevenueUSD'];
            $bySalesman[$key]['CashUSD']    += (float)$r['CashUSD'];
            $bySalesman[$key]['CreditUSD']  += (float)$r['CreditUSD'];
        }
        foreach ($bySalesman as &$s) {
            $s['AvgBillUSD'] = $s['BillCount'] > 0 ? $s['RevenueUSD'] / $s['BillCount'] : 0;
        }
        unset($s);

                // Per-salesman, per-currency breakdown. One extra query is far
        // cheaper than trying to pivot this in the same GROUP BY, and it
        // keeps the main aggregate readable.
        $curSql = "SELECT
                       d.GUID            AS DistributorGUID,
                       b.StoreGUID       AS StoreGUID,
                       cur.Name          AS CurrencyName,
                       ISNULL(SUM(b.Total - b.TotalDisc + b.TotalExtra), 0) AS NetUSD,
                       ISNULL(SUM((b.Total - b.TotalDisc + b.TotalExtra) / NULLIF(b.CurrencyVal, 0)), 0) AS NetOriginal
                   FROM bu000 b
                   LEFT JOIN Distributor000 d ON b.StoreGUID = d.StoreGUID
                   LEFT JOIN my000 cur        ON b.CurrencyGUID = cur.GUID
                   WHERE b.Date >= :fromStart AND b.Date < :toEnd
                   GROUP BY d.GUID, b.StoreGUID, b.CurrencyGUID, cur.Name
                   ORDER BY NetUSD DESC";
        $cStmt = $pdo->prepare($curSql);
        $cStmt->execute([':fromStart' => $fromStart, ':toEnd' => $toEnd]);
        $curRows = $cStmt->fetchAll(PDO::FETCH_ASSOC);

        // Attach each currency row to the salesman it belongs to. The key
        // logic mirrors the merge step above, so a salesman's currencies
        // accumulate across all his warehouses.
        foreach ($curRows as $cr) {
            $key = $cr['DistributorGUID'] ?: ('STORE:' . $cr['StoreGUID']);
            if (!isset($bySalesman[$key])) continue;
            if (!isset($bySalesman[$key]['Currencies'])) {
                $bySalesman[$key]['Currencies'] = [];
            }
            $bySalesman[$key]['Currencies'][] = [
                'name'         => $cr['CurrencyName'] ?: 'غير معروفة',
                'net_usd'      => (float)$cr['NetUSD'],
                'net_original' => (float)$cr['NetOriginal'],
            ];
        }
        
        $salesmen = array_values($bySalesman);

        // Re-sort client-agnostic — the SQL ORDER BY was pre-merge.
        $cmp = function ($a, $b) use ($sort, $dir) {
            $k = ['revenue' => 'RevenueUSD', 'bills' => 'BillCount', 'avg' => 'AvgBillUSD',
                  'cash' => 'CashUSD', 'credit' => 'CreditUSD', 'name' => 'Name'][$sort] ?? 'RevenueUSD';
            if ($k === 'Name') {
                $c = strcmp($a['Name'], $b['Name']);
                return $dir === 'ASC' ? $c : -$c;
            }
            return $dir === 'ASC' ? $a[$k] <=> $b[$k] : $b[$k] <=> $a[$k];
        };
        usort($salesmen, $cmp);

        // Grand totals for the KPI cards
        $grandTotalUSD = 0; $grandBills = 0; $grandCash = 0; $grandCredit = 0;
        foreach ($salesmen as $s) {
            $grandTotalUSD += $s['RevenueUSD'];
            $grandBills    += $s['BillCount'];
            $grandCash     += $s['CashUSD'];
            $grandCredit   += $s['CreditUSD'];
        }

        // Per-currency breakdown for the KPI card (same pattern as products.php)
        $curSql = "SELECT
                       cur.Name AS CurrencyName,
                       ISNULL(SUM(b.Total - b.TotalDisc + b.TotalExtra), 0) AS NetUSD,
                       ISNULL(SUM((b.Total - b.TotalDisc + b.TotalExtra) / NULLIF(b.CurrencyVal, 0)), 0) AS NetOriginal
                   FROM bu000 b
                   LEFT JOIN my000 cur ON b.CurrencyGUID = cur.GUID
                   WHERE b.Date >= :fromStart AND b.Date < :toEnd
                   GROUP BY b.CurrencyGUID, cur.Name
                   ORDER BY NetUSD DESC";
        $cStmt = $pdo->prepare($curSql);
        $cStmt->execute([':fromStart' => $fromStart, ':toEnd' => $toEnd]);
        $currencyRows = $cStmt->fetchAll(PDO::FETCH_ASSOC);

        $currencies = [];
        foreach ($currencyRows as $cr) {
            $currencies[] = [
                'name'         => $cr['CurrencyName'] ?: 'غير معروفة',
                'net_usd'      => (float)$cr['NetUSD'],
                'net_original' => (float)$cr['NetOriginal'],
            ];
        }

        echo json_encode([
            'from'       => $from->format('Y-m-d'),
            'to'         => $to->format('Y-m-d'),
            'salesmen'   => $salesmen,
            'currencies' => $currencies,
            'summary'    => [
                'salesmanCount' => count($salesmen),
                'billCount'     => $grandBills,
                'revenueUSD'    => $grandTotalUSD,
                'cashUSD'       => $grandCash,
                'creditUSD'     => $grandCredit,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        jsonFail($e, 500, 'Could not load salesmen.');
    }
    exit;
}

// ─── API: single salesman + recent bills ─────────────────────────
if (isset($_GET['salesman']) && $_GET['salesman'] == 1) {
    header('Content-Type: application/json');

    $guid = $_GET['guid'] ?? '';
    if (!preg_match('/^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/', $guid)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid GUID']);
        exit;
    }

    try {
        $pdo = getDBConnection();

        $stmt = $pdo->prepare("
            SELECT d.Number, d.Name, d.Code, d.LatinName, d.GUID, d.StoreGUID,
                   st.Name AS StoreName, st.Number AS StoreNumber
            FROM Distributor000 d
            LEFT JOIN st000 st ON d.StoreGUID = st.GUID
            WHERE d.GUID = :guid
        ");
        $stmt->execute([':guid' => $guid]);
        $sm = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sm) {
            http_response_code(404);
            echo json_encode(['error' => 'Salesman not found']);
            exit;
        }

        $billsStmt = $pdo->prepare("
            SELECT TOP 50
                b.GUID, b.Number, b.Date, b.PayType, b.Cust_Name,
                b.Total, b.TotalDisc, b.TotalExtra, b.CurrencyVal,
                cur.Name AS CurrencyName
            FROM bu000 b
            LEFT JOIN my000 cur ON b.CurrencyGUID = cur.GUID
            WHERE b.StoreGUID = :storeGuid
            ORDER BY b.CreateDate DESC
        ");
        $billsStmt->execute([':storeGuid' => $sm['StoreGUID']]);
        $bills = $billsStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($bills as &$b) {
            $b['Date']        = isoStamp($b['Date'] ?? null);
            $b['Total']       = (float)$b['Total'];
            $b['TotalDisc']   = (float)$b['TotalDisc'];
            $b['TotalExtra']  = (float)$b['TotalExtra'];
            $b['CurrencyVal'] = (float)$b['CurrencyVal'];
            $b['Net']         = $b['Total'] - $b['TotalDisc'] + $b['TotalExtra'];
        }
        unset($b);

        echo json_encode([
            'salesman' => $sm,
            'bills'    => $bills,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        jsonFail($e, 500, 'Could not load salesman.');
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>البائعون - QuantuSphere Web</title>
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

        .page { max-width: 1400px; margin: 0 auto; }

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
            display: none;
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            width: max-content;
            max-width: calc(100vw - 24px);
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: 0 10px 30px rgba(29,42,53,0.15);
            padding: 6px;
            z-index: 300;
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

        .date-filter {
            display: flex; align-items: center; flex-wrap: wrap; gap: 8px;
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 12px 16px; margin-bottom: 16px;
        }
        .date-filter label { font-size: 0.75rem; color: var(--text-muted); font-weight: 700; }
        .date-filter input[type="date"] {
            padding: 6px 10px; border: 1px solid var(--border);
            border-radius: 9px; font-size: 0.8rem; font-family: inherit;
            background: var(--bg); color: var(--text);
        }
        .date-filter input[type="date"]:focus { outline: none; border-color: var(--primary); }
        .chip {
            display: inline-flex; align-items: center; gap: 6px;
            background: var(--surface); border: 1px solid var(--border);
            padding: 7px 12px; border-radius: 999px;
            font-size: 0.78rem; font-weight: 700; color: var(--text-muted);
            font-family: inherit; cursor: pointer;
            transition: all 0.15s;
        }
        .chip:hover { border-color: var(--primary); color: var(--primary); }
        .chip.active { background: var(--primary); border-color: var(--primary); color: white; }
        .spacer { flex: 1; }

        .kpi-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px; margin-bottom: 20px;
        }
        .kpi-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 14px 16px; text-align: right;
        }
        .kpi-label { font-size: 0.75rem; color: var(--text-muted); font-weight: 700; margin-bottom: 6px; }
        .kpi-value {
            font-family: var(--font-num); font-variant-numeric: tabular-nums;
            font-size: 1.35rem; font-weight: 700; color: var(--text); line-height: 1.2;
        }
        .kpi-primary { border-color: var(--primary); }
        .kpi-primary .kpi-value { color: var(--primary-dark); font-size: 1.6rem; }
        .kpi-cash    .kpi-value { color: var(--accent); }
        .kpi-credit  .kpi-value { color: var(--primary-dark); }
        .kpi-currency-list {
            margin-top: 10px; padding-top: 8px;
            border-top: 1px dashed var(--border);
        }
        .kpi-currency-list:empty { display: none; }
        .kc-label {
            font-size: 0.68rem; color: var(--text-muted);
            font-weight: 700; margin-bottom: 4px;
        }
        .kc-row {
            display: flex; justify-content: space-between; gap: 10px;
            padding: 2px 0; font-size: 0.72rem;
        }
        .kc-name { color: var(--text-muted); }
        .kc-amount {
            font-family: var(--font-num); font-variant-numeric: tabular-nums;
            color: var(--text); font-weight: 700;
        }

        .section {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); margin-bottom: 16px; overflow: hidden;
        }
        .section-title {
            margin: 0; padding: 12px 18px; font-size: 0.95rem; font-weight: 700;
            background: var(--header-bg); color: var(--header-fg);
            border-bottom: 2px solid var(--primary);
        }
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        th {
            background: var(--bg); color: var(--text); font-weight: 700;
            padding: 10px 12px; border-bottom: 1px solid var(--border);
            text-align: right; white-space: nowrap;
        }
        td {
            padding: 10px 12px; border-bottom: 1px solid var(--border);
            text-align: right; color: var(--text);
        }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover td { background: var(--primary-light); }
        tbody tr.clickable { cursor: pointer; }
        .num { font-family: var(--font-num); font-variant-numeric: tabular-nums; }
        .muted { color: var(--text-muted); }
        .rank-badge {
            display: inline-block; min-width: 24px; padding: 2px 6px;
            border-radius: 6px; background: var(--bg); color: var(--text-muted);
            font-family: var(--font-num); font-variant-numeric: tabular-nums;
            font-weight: 700; text-align: center; font-size: 0.78rem;
        }

                /* Per-currency breakdown shown under a salesman's USD revenue */
        .currency-breakdown {
            margin-top: 4px;
            padding-top: 4px;
            border-top: 1px dashed var(--border);
            font-size: 0.72rem;
            font-weight: 400;
        }
        .cb-line {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            padding: 1px 0;
        }
        .cb-name {
            color: var(--text-muted);
            font-family: 'Tajawal', 'Segoe UI', sans-serif;
            font-weight: 600;
        }
        .cb-amount {
            font-family: var(--font-num);
            font-variant-numeric: tabular-nums;
            font-weight: 600;
            color: var(--text);
        }

        
        .rank-1 { background: linear-gradient(135deg, #D9A855, #B8863C); color: white; }
        .rank-2 { background: #C9C0AC; color: #1D2A35; }
        .rank-3 { background: #A67B4A; color: white; }

        .unmapped-badge {
            display: inline-block;
            margin-right: 4px;
            font-size: 0.75rem;
            opacity: 0.6;
            cursor: help;
        }

        th.sortable { cursor: pointer; user-select: none; white-space: nowrap; }
        th.sortable:hover { color: var(--primary-dark); }
        th.sortable.sort-active { color: var(--primary-dark); }
        .sort-ind { display: inline-block; width: 12px; margin-right: 4px; color: var(--text-muted); font-size: 0.7rem; }
        th.sortable.sort-active .sort-ind { color: var(--primary-dark); font-weight: 900; }

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
            padding: 5px 0; font-size: 0.85rem; border-bottom: 1px dashed var(--border);
        }
        .info-row:last-child { border-bottom: none; }
        .info-row .lbl { color: var(--text-muted); font-weight: 600; flex-shrink: 0; }
        .info-row .val { text-align: left; word-break: break-word; }

        .loading, .empty {
            padding: 30px; text-align: center;
            color: var(--text-muted); font-size: 0.85rem;
        }

        @media (max-width: 600px) {
            body { padding: 10px; }
            h1 { font-size: 1.1rem; }
            th, td { padding: 8px 6px; font-size: 0.75rem; }
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
            .kpi-primary { grid-column: 1 / -1; }
            .kpi-value { font-size: 1.1rem; }
            .kpi-primary .kpi-value { font-size: 1.3rem; }
            .kpi-cash, .kpi-credit { display: none; }  /* too cramped on phones */
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
                            <a href="index.php" class="menu-item"><span class="menu-item-icon">🏠</span><span>الرئيسية</span></a>
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
                                <span class="menu-item-icon"><i class="ti ti-logout"></i></span>
                                <span>تسجيل الخروج</span>
                            </a>
                        </div>
                    </div>
                </div>
                <h1>📊 لوحة الإحصائيات</h1>
            </div>
        </div>

        <!-- LIST VIEW -->
        <div id="listView">
            <div class="date-filter">
                <button type="button" class="chip" data-preset="today">اليوم</button>
                <button type="button" class="chip" data-preset="yesterday">أمس</button>
                <button type="button" class="chip" data-preset="week">هذا الأسبوع</button>
                <button type="button" class="chip" data-preset="month">هذا الشهر</button>
                <span class="spacer"></span>
                <label>من</label>
                <input type="date" id="dateFrom">
                <label>إلى</label>
                <input type="date" id="dateTo">
                <button type="button" class="btn-primary" id="applyBtn">تطبيق</button>
                <button type="button" class="btn" id="resetFilterBtn" title="إعادة تعيين الفلتر">↺ إعادة تعيين</button>
            </div>

            <div class="kpi-grid">
                <div class="kpi-card kpi-primary">
                    <div class="kpi-label">💰 إجمالي الإيرادات</div>
                    <div class="kpi-value" id="kpiRevenue">—</div>
                    <div class="kpi-currency-list" id="kpiCurrencyList"></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">📄 عدد الفواتير</div>
                    <div class="kpi-value" id="kpiBills">—</div>
                </div>
                <div class="kpi-card kpi-cash">
                    <div class="kpi-label">💵 نقدي</div>
                    <div class="kpi-value" id="kpiCash">—</div>
                </div>
                <div class="kpi-card kpi-credit">
                    <div class="kpi-label">📝 آجل</div>
                    <div class="kpi-value" id="kpiCredit">—</div>
                </div>
            </div>

            <div class="section">
                <h2 class="section-title">🏆 ترتيب البائعين</h2>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th class="sortable list-sortable" data-sort="name">البائع <span class="sort-ind"></span></th>
                                <th class="sortable list-sortable" data-sort="bills">عدد الفواتير <span class="sort-ind"></span></th>
                                <th class="sortable list-sortable" data-sort="revenue">الإيرادات (USD) <span class="sort-ind"></span></th>
                                <th class="sortable list-sortable" data-sort="avg">متوسط الفاتورة <span class="sort-ind"></span></th>
                                <th class="sortable list-sortable" data-sort="cash">نقدي <span class="sort-ind"></span></th>
                                <th class="sortable list-sortable" data-sort="credit">آجل <span class="sort-ind"></span></th>
                            </tr>
                        </thead>
                        <tbody id="listBody">
                            <tr><td colspan="7" class="loading">جاري التحميل...</td></tr>
                        </tbody>
                    </table>
                </div>
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
                    <h3>📋 معلومات البائع</h3>
                    <div id="infoBody"></div>
                </div>
                <div class="info-card">
                    <h3>🏬 المستودع المرتبط</h3>
                    <div id="storeBody"></div>
                </div>
            </div>

            <div class="section">
                <h2 class="section-title">
                    📄 آخر الفواتير
                    <span id="billsSummary" class="muted" style="font-size:0.8rem;font-weight:600;margin-right:8px;"></span>
                </h2>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th class="sortable sales-sortable" data-sort="Number">رقم الفاتورة <span class="sort-ind"></span></th>
                                <th class="sortable sales-sortable" data-sort="Date">التاريخ <span class="sort-ind"></span></th>
                                <th class="sortable sales-sortable" data-sort="Cust_Name">الزبون <span class="sort-ind"></span></th>
                                <th class="sortable sales-sortable" data-sort="PayType">طريقة الدفع <span class="sort-ind"></span></th>
                                <th class="sortable sales-sortable" data-sort="Net">الصافي (USD) <span class="sort-ind"></span></th>
                            </tr>
                        </thead>
                        <tbody id="billsBody">
                            <tr><td colspan="5" class="loading">جاري التحميل...</td></tr>
                        </tbody>
                    </table>
                </div>
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
        // sm000.Name starts with "المندوب " — that's a title, redundant
        // when the page is already titled البائعون.
        function cleanSalesmanName(name) {
            if (!name) return '—';
            return String(name).replace(/^المندوب\s+/, '');
        }

        // ---------- Theme toggle ----------
        (function initThemeToggle() {
            const btn  = document.getElementById('themeToggleBtn');
            const icon = document.getElementById('themeToggleIcon');
            if (!btn || !icon) return;
            const THEMES = ['warm', 'bright', 'dark', 'classic'];
            const ICONS = { warm: '📜', bright: '☀️', dark: '🌙', classic: '🔷' };
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

            document.addEventListener('mousedown', (e) => {
                if (!isOpen()) return;
                if (!wrap.contains(e.target)) close();
            });

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && isOpen()) {
                    close();
                    btn.focus();
                }
            });
        })();
        const PAY_TYPES = { '0': 'نقدي', '1': 'آجل' };
        function payTypeLabel(v) {
            if (v === null || v === undefined || v === '') return '-';
            return PAY_TYPES[String(v).trim()] || ('نوع ' + v);
        }

        // ---------- Routing ----------
        const urlParams = new URLSearchParams(window.location.search);
        const detailGuid = urlParams.get('guid');
        const isDetail = !!detailGuid;

        function fmtDateInput(d) {
            return d.getFullYear() + '-'
                 + String(d.getMonth() + 1).padStart(2, '0') + '-'
                 + String(d.getDate()).padStart(2, '0');
        }
        function dateMinusDays(date, n) {
            const d = new Date(date);
            d.setDate(d.getDate() - n);
            return d;
        }

        // ---------- LIST VIEW ----------
        let rangeFrom = null;
        let rangeTo   = null;
        let listSort  = { key: 'revenue', dir: 'desc' };

        const FILTER_KEY = 'salesmenDateRange';
        function saveFilter() {
            try {
                localStorage.setItem(FILTER_KEY, JSON.stringify({ from: rangeFrom, to: rangeTo }));
            } catch (e) {}
        }
        function loadSavedFilter() {
            try {
                const s = localStorage.getItem(FILTER_KEY);
                if (!s) return null;
                const p = JSON.parse(s);
                if (p && p.from && p.to) return p;
            } catch (e) {}
            return null;
        }
        function clearSavedFilter() {
            try { localStorage.removeItem(FILTER_KEY); } catch (e) {}
        }

        function setActiveChip(preset) {
            document.querySelectorAll('#listView .chip').forEach(c => {
                c.classList.toggle('active', c.dataset.preset === preset);
            });
        }

        function applyPreset(preset) {
            const today = new Date();
            let from = new Date(today), to = new Date(today);
            if (preset === 'yesterday') {
                from = dateMinusDays(today, 1);
                to   = new Date(from);
            } else if (preset === 'week') {
                const dow = (today.getDay() + 6) % 7;
                from = dateMinusDays(today, dow);
                to   = new Date(today);
            } else if (preset === 'month') {
                from = new Date(today.getFullYear(), today.getMonth(), 1);
                to   = new Date(today);
            }
            rangeFrom = fmtDateInput(from);
            rangeTo   = fmtDateInput(to);
            document.getElementById('dateFrom').value = rangeFrom;
            document.getElementById('dateTo').value   = rangeTo;
            setActiveChip(preset);
            saveFilter();
            loadSalesmen();
        }

        document.querySelectorAll('#listView .chip[data-preset]').forEach(btn => {
            btn.addEventListener('click', () => applyPreset(btn.dataset.preset));
        });
        document.getElementById('applyBtn').addEventListener('click', () => {
            const from = document.getElementById('dateFrom').value;
            const to   = document.getElementById('dateTo').value;
            if (!from || !to) return;
            if (from > to) { alert('تاريخ البداية يجب أن يكون قبل تاريخ النهاية.'); return; }
            rangeFrom = from;
            rangeTo   = to;
            setActiveChip(null);
            saveFilter();
            loadSalesmen();
        });
        document.getElementById('resetFilterBtn').addEventListener('click', () => {
            clearSavedFilter();
            document.getElementById('dateFrom').value = '';
            document.getElementById('dateTo').value   = '';
            applyPreset('today');
        });

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
                    listSort.dir = (key === 'name') ? 'asc' : 'desc';
                }
                updateListSortIndicators();
                loadSalesmen();
            });
        });

        async function loadSalesmen() {
            const params = new URLSearchParams({ list: '1' });
            if (rangeFrom) params.set('from', rangeFrom);
            if (rangeTo)   params.set('to',   rangeTo);
            params.set('sort', listSort.key);
            params.set('dir',  listSort.dir);

            const body = document.getElementById('listBody');
            body.innerHTML = '<tr><td colspan="7" class="loading">جاري التحميل...</td></tr>';

            try {
                const resp = await fetch('?' + params.toString(), { cache: 'no-store' });
                if (resp.status === 401) { window.location.href = 'login.php'; return; }
                const data = await resp.json();
                if (!data || data.error) {
                    body.innerHTML = `<tr><td colspan="7" class="loading">خطأ: ${escapeHtml(data.error || '')}</td></tr>`;
                    return;
                }

                document.getElementById('kpiRevenue').textContent = fmtNum(data.summary.revenueUSD) + ' USD';
                document.getElementById('kpiBills').textContent   = data.summary.billCount;
                document.getElementById('kpiCash').textContent    = fmtNum(data.summary.cashUSD)   + ' USD';
                document.getElementById('kpiCredit').textContent  = fmtNum(data.summary.creditUSD) + ' USD';

                renderKpiCurrencies(data.currencies);

                if (!data.salesmen.length) {
                    body.innerHTML = '<tr><td colspan="7" class="empty">لا توجد بيانات في هذه الفترة.</td></tr>';
                    return;
                }

                let html = '';
                data.salesmen.forEach((s, i) => {
                    const rankCls   = i < 3 ? `rank-${i + 1}` : '';
                    const clickable = s.GUID ? 'clickable' : '';
                    const href      = s.GUID ? `onclick="window.location.href='salesmen.php?guid=${encodeURIComponent(s.GUID)}'"` : '';
                    // Unmapped rows come from st000: the main counter, or a
                    // salesman not yet registered in Distributor000. Mark
                    // them so nobody mistakes them for a real salesman.
                    const badge = s.IsUnmapped
                        ? ` <span class="unmapped-badge" title="غير مرتبط ببائع">🏬</span>`
                        : '';
                    const displayName = s.IsUnmapped
                        ? escapeHtml(s.Name)
                        : escapeHtml(cleanSalesmanName(s.Name));

                                        // Currency breakdown: one line per currency the salesman
                    // received. Skipped when everything was in USD alone —
                    // in that case the headline number already says it all.
                    let breakdownHtml = '';
                    const curs = Array.isArray(s.Currencies) ? s.Currencies : [];
                    const onlyUSD = curs.length === 1 &&
                        /دولار|usd|dollar/i.test(curs[0].name || '');
                    if (curs.length > 0 && !onlyUSD) {
                        let lines = '';
                        for (const c of curs) {
                            lines += `<div class="cb-line">
                                <span class="cb-name">${escapeHtml(c.name)}</span>
                                <span class="cb-amount">${fmtNum(c.net_original)}</span>
                            </div>`;
                        }
                        breakdownHtml = `<div class="currency-breakdown">${lines}</div>`;
                    }

                    html += `<tr class="${clickable}" ${href}>
                        <td><span class="rank-badge ${rankCls}">${i + 1}</span></td>
                        <td>${displayName}${badge}</td>
                        <td class="num">${s.BillCount}</td>
                        <td class="num"><b>${fmtNum(s.RevenueUSD)}</b>${breakdownHtml}</td>
                        <td class="num">${fmtNum(s.AvgBillUSD)}</td>
                        <td class="num" style="color:var(--accent);">${fmtNum(s.CashUSD)}</td>
                        <td class="num" style="color:var(--primary-dark);">${fmtNum(s.CreditUSD)}</td>
                    </tr>`;
                });
                body.innerHTML = html;
            } catch (e) {
                console.error(e);
                body.innerHTML = `<tr><td colspan="7" class="loading">خطأ في الشبكة: ${escapeHtml(e.message)}</td></tr>`;
            }
        }

        function renderKpiCurrencies(currencies) {
            const el = document.getElementById('kpiCurrencyList');
            if (!el) return;
            if (!Array.isArray(currencies) || currencies.length === 0) {
                el.innerHTML = '';
                return;
            }
            if (currencies.length === 1) {
                const n = (currencies[0].name || '').toLowerCase();
                if (n.includes('دولار') || n.includes('usd') || n.includes('dollar')) {
                    el.innerHTML = '';
                    return;
                }
            }
            let html = '<div class="kc-label">حسب العملة:</div>';
            for (const c of currencies) {
                html += `<div class="kc-row">
                    <span class="kc-name">${escapeHtml(c.name || '—')}</span>
                    <span class="kc-amount">${fmtNum(c.net_original)}</span>
                </div>`;
            }
            el.innerHTML = html;
        }

        // ---------- DETAIL VIEW: bills sorting ----------
        let currentBills = [];
        let billsSort = { key: 'Date', dir: 'desc' };

        function renderBills() {
            const bb = document.getElementById('billsBody');
            if (!currentBills.length) {
                bb.innerHTML = '<tr><td colspan="5" class="empty">لا توجد فواتير لهذا البائع.</td></tr>';
                return;
            }
            const dir = billsSort.dir === 'asc' ? 1 : -1;
            const k   = billsSort.key;

            const sorted = currentBills.slice().sort((a, b) => {
                let va, vb;
                if (k === 'Date') {
                    va = new Date(a.Date || 0).getTime();
                    vb = new Date(b.Date || 0).getTime();
                } else if (k === 'Number') {
                    va = parseFloat(a.Number) || 0;
                    vb = parseFloat(b.Number) || 0;
                } else if (k === 'Net') {
                    const cvA = parseFloat(a.CurrencyVal) || 1;
                    const cvB = parseFloat(b.CurrencyVal) || 1;
                    va = (parseFloat(a.Net) || 0) / cvA;
                    vb = (parseFloat(b.Net) || 0) / cvB;
                } else {
                    va = String(a[k] ?? '');
                    vb = String(b[k] ?? '');
                }
                if (va < vb) return -1 * dir;
                if (va > vb) return  1 * dir;
                return 0;
            });

            let bh = '';
            for (const bill of sorted) {
                const cv  = parseFloat(bill.CurrencyVal) || 1;
                const net = (parseFloat(bill.Net) || 0) / cv;
                const date = bill.Date ? new Date(bill.Date).toLocaleDateString('en-GB') : '-';
                bh += `<tr>
                    <td class="num">#${escapeHtml(bill.Number)}</td>
                    <td class="num">${date}</td>
                    <td>${escapeHtml(bill.Cust_Name || 'مناقلة')}</td>
                    <td>${payTypeLabel(bill.PayType)}</td>
                    <td class="num"><b>${fmtNum(net)}</b></td>
                </tr>`;
            }
            bb.innerHTML = bh;
        }

        function updateBillsSortIndicators() {
            document.querySelectorAll('#detailView th.sales-sortable').forEach(th => {
                const ind = th.querySelector('.sort-ind');
                if (th.dataset.sort === billsSort.key) {
                    th.classList.add('sort-active');
                    if (ind) ind.textContent = billsSort.dir === 'asc' ? '▲' : '▼';
                } else {
                    th.classList.remove('sort-active');
                    if (ind) ind.textContent = '';
                }
            });
        }

        document.querySelectorAll('#detailView th.sales-sortable').forEach(th => {
            th.addEventListener('click', () => {
                const key = th.dataset.sort;
                if (billsSort.key === key) {
                    billsSort.dir = billsSort.dir === 'asc' ? 'desc' : 'asc';
                } else {
                    billsSort.key = key;
                    billsSort.dir = 'asc';
                }
                updateBillsSortIndicators();
                renderBills();
            });
        });

        async function loadSalesman(guid) {
            try {
                const resp = await fetch('?salesman=1&guid=' + encodeURIComponent(guid), { cache: 'no-store' });
                if (resp.status === 401) { window.location.href = 'login.php'; return; }
                const data = await resp.json();
                if (!data || data.error) {
                    document.getElementById('detailName').textContent = 'خطأ';
                    document.getElementById('detailSub').textContent  = data.error || 'Salesman not found';
                    return;
                }

                const s = data.salesman;

                document.getElementById('detailName').textContent = cleanSalesmanName(s.Name);
                document.getElementById('detailSub').textContent =
                    'رقم البائع: ' + (s.Number || '-') + (s.LatinName ? ' · ' + s.LatinName : '');

                function buildInfoRows(rows) {
                    let html = '';
                    for (const [lbl, val] of rows) {
                        if (val === null || val === undefined || val === '' || val === 0) continue;
                        html += `<div class="info-row"><span class="lbl">${lbl}</span><span class="val">${escapeHtml(String(val))}</span></div>`;
                    }
                    return html || '<div class="empty" style="padding:12px 0;">لا توجد بيانات.</div>';
                }

                document.getElementById('infoBody').innerHTML = buildInfoRows([
                    ['# الرقم',   s.Number],
                    ['🏷️ الكود',  s.Code],
                    ['📝 الاسم',  cleanSalesmanName(s.Name)],
                ]);

                document.getElementById('storeBody').innerHTML = buildInfoRows([
                    ['🏬 اسم المستودع', s.StoreName],
                    ['# رقم المستودع',  s.StoreNumber],
                ]);

                currentBills = Array.isArray(data.bills) ? data.bills.slice() : [];
                billsSort = { key: 'Date', dir: 'desc' };
                updateBillsSortIndicators();
                renderBills();
                document.getElementById('billsSummary').textContent =
                    currentBills.length > 0 ? `(آخر ${currentBills.length} فاتورة)` : '';
            } catch (e) {
                console.error(e);
            }
        }

        // ---------- Bootstrap ----------
        if (isDetail) {
            document.getElementById('listView').style.display   = 'none';
            document.getElementById('detailView').style.display = 'block';
            loadSalesman(detailGuid);
        } else {
            updateListSortIndicators();
            const saved = loadSavedFilter();
            if (saved) {
                rangeFrom = saved.from;
                rangeTo   = saved.to;
                document.getElementById('dateFrom').value = rangeFrom;
                document.getElementById('dateTo').value   = rangeTo;
                setActiveChip(null);
                loadSalesmen();
            } else {
                applyPreset('today');
            }
        }
    </script>
</body>
</html>