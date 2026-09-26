<?php
// exchange-rates.php — latest exchange rate of each currency vs. base (USD).
// Reads my000 (currency master) and mh000 (rate history).
// Base currency = the one whose CurrencyVal = 1 in my000 (USD here).
require_once 'config.php';
require_once 'auth.php';
requireLogin(true);

// ─── API: latest rates for every currency ───────────────────────
if (isset($_GET['rates']) && $_GET['rates'] == 1) {
    header('Content-Type: application/json');
    try {
        $pdo = getDBConnection();

        // Base currency (CurrencyVal = 1)
        $base = $pdo->query("
            SELECT TOP 1 GUID, Number, Code, Name, LatinName, CurrencyISO
            FROM my000
            WHERE CurrencyVal = 1
            ORDER BY CAST(Number AS INT)
        ")->fetch(PDO::FETCH_ASSOC);

        if (!$base) {
            throw new RuntimeException('Base currency not found in my000.');
        }

        // Every currency + its two most recent rate rows from mh000
        $sql = "
            SELECT
                cur.GUID,
                cur.Number,
                cur.Code,
                cur.Name,
                cur.LatinName,
                cur.CurrencyISO,
                cur.CurrencyVal  AS MyVal,
                latest.CurrencyVal AS LatestVal,
                latest.Date        AS LatestDate,
                prev.CurrencyVal   AS PrevVal,
                prev.Date          AS PrevDate
            FROM my000 cur
            OUTER APPLY (
                SELECT TOP 1 CurrencyVal, Date
                FROM mh000
                WHERE CurrencyGUID = cur.GUID
                ORDER BY Date DESC
            ) latest
            OUTER APPLY (
                SELECT TOP 1 CurrencyVal, Date
                FROM mh000
                WHERE CurrencyGUID = cur.GUID
                  AND Date < latest.Date
                ORDER BY Date DESC
            ) prev
            ORDER BY CAST(cur.Number AS INT)
        ";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $currencies = [];
        foreach ($rows as $r) {
            // Fall back to master-table CurrencyVal if mh000 has no history yet
            $latestVal = ($r['LatestVal'] !== null)
                ? (float)$r['LatestVal']
                : (float)$r['MyVal'];
            if ($latestVal <= 0) $latestVal = (float)$r['MyVal'];

            $prevVal = ($r['PrevVal'] !== null) ? (float)$r['PrevVal'] : null;

            $changePct = null;
            if ($prevVal !== null && $prevVal > 0) {
                $changePct = (($latestVal - $prevVal) / $prevVal) * 100.0;
            }

            $currencies[] = [
                'GUID'        => $r['GUID'],
                'Number'      => trim((string)$r['Number']),
                'Code'        => $r['Code'],
                'Name'        => $r['Name'],
                'LatinName'   => $r['LatinName'],
                'ISO'         => $r['CurrencyISO'],
                'IsBase'      => ((float)$r['MyVal'] === 1.0),
                // 1 unit of this currency = ToUSD USD
                'ToUSD'       => $latestVal,
                // 1 USD = FromUSD units of this currency
                'FromUSD'     => $latestVal > 0 ? (1.0 / $latestVal) : null,
                'LatestDate'  => isoStamp($r['LatestDate'] ?? null),
                'PrevDate'    => isoStamp($r['PrevDate']   ?? null),
                'ChangePct'   => $changePct,
            ];
        }

        echo json_encode([
            'base' => [
                'GUID' => $base['GUID'],
                'Code' => $base['Code'],
                'Name' => $base['Name'],
                'ISO'  => $base['CurrencyISO'],
            ],
            'currencies' => $currencies,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (Throwable $e) {
        jsonFail($e, 500, 'Could not load exchange rates.');
    }
    exit;
}

// ─── API: paginated rate history ────────────────────────────────
if (isset($_GET['history']) && $_GET['history'] == 1) {
    header('Content-Type: application/json');

    $currencyGuid = (isset($_GET['currency']) && $_GET['currency'] !== '')
                  ? $_GET['currency'] : null;
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $limit  = 200;

    $sort = $_GET['sort'] ?? 'date';
    $dir  = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
    $sortMap = [
        'date'     => 'mh.Date',
        'currency' => 'cur.Name',
        'rate'     => 'mh.CurrencyVal',
    ];
    $orderCol = $sortMap[$sort] ?? 'mh.Date';

    // Unique tiebreaker (mh.GUID) keeps pagination stable and avoids a
    // duplicate-column ORDER BY when sorting by date, which some SQL
    // Server builds reject with error 16902 or similar.
    $orderSql = "$orderCol $dir, mh.GUID ASC";

    try {
        $pdo = getDBConnection();

        $where  = [];
        $params = [];
        if ($currencyGuid) {
            $where[] = 'mh.CurrencyGUID = :cur';
            $params[':cur'] = $currencyGuid;
        }
        $whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

        // Correlated subquery instead of LAG() — works on every SQL
        // Server version without CTE/window-function edge cases.
        $sql = "
            SELECT
                mh.GUID,
                mh.Date,
                mh.CurrencyVal,
                cur.Name        AS CurrencyName,
                cur.Code        AS CurrencyCode,
                cur.CurrencyISO AS CurrencyISO,
                (
                    SELECT TOP 1 mh2.CurrencyVal
                    FROM dbo.mh000 mh2
                    WHERE mh2.CurrencyGUID = mh.CurrencyGUID
                      AND mh2.Date < mh.Date
                    ORDER BY mh2.Date DESC
                ) AS PrevVal
            FROM dbo.mh000 mh
            LEFT JOIN dbo.my000 cur ON mh.CurrencyGUID = cur.GUID
            $whereSql
            ORDER BY $orderSql
            OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY
        ";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':offset', $offset,    PDO::PARAM_INT);
        $stmt->bindValue(':limit',  $limit + 1, PDO::PARAM_INT);
        $stmt->execute();

        $rows    = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);

        $items = [];
        foreach ($rows as $r) {
            $rateVal = (float)$r['CurrencyVal'];
            $prevVal = ($r['PrevVal'] !== null) ? (float)$r['PrevVal'] : null;

            $changePct = null;
            if ($prevVal !== null && $prevVal > 0) {
                $changePct = (($rateVal - $prevVal) / $prevVal) * 100.0;
            }

            $items[] = [
                'GUID'         => $r['GUID'],
                'Date'         => isoStamp($r['Date'] ?? null),
                'CurrencyName' => $r['CurrencyName'],
                'CurrencyCode' => $r['CurrencyCode'],
                'CurrencyISO'  => $r['CurrencyISO'],
                'FromUSD'      => $rateVal > 0 ? (1.0 / $rateVal) : null,
                'ToUSD'        => $rateVal,
                'ChangePct'    => $changePct,
            ];
        }

        echo json_encode(
            ['items' => $items, 'hasMore' => $hasMore],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    } catch (Throwable $e) {
        jsonFail($e, 500, 'Could not load rate history.');
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>أسعار الصرف - QuantuSphere Web</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;900&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap" rel="stylesheet">
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

        .page { max-width: 1200px; margin: 0 auto; }

        .page-header {
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px; margin-bottom: 16px; flex-wrap: wrap;
        }
        h1 { font-size: 1.4rem; font-weight: 900; margin: 0; display: flex; align-items: center; gap: 8px; }
        .topbar-start { display: flex; align-items: center; gap: 12px; }
        .topbar-start h1 { margin: 0; }

        /* Hamburger */
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
        .menu-toggle-btn.open { background: var(--primary); border-color: var(--primary); color: white; }
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
        @keyframes menuFade { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }
        .menu-dropdown .menu-item, .menu-dropdown .menu-section-title { white-space: nowrap; }
        .menu-section { padding: 4px 0; }
        .menu-section + .menu-section { border-top: 1px solid var(--border); margin-top: 4px; padding-top: 8px; }
        .menu-section-title {
            font-size: 0.68rem; color: var(--text-muted); font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.4px; padding: 4px 12px 6px;
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

        /* Base currency banner */
        .base-banner {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 14px 20px;
            margin-bottom: 20px;
            display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
            border-right: 4px solid var(--primary);
        }
        .base-icon {
            font-size: 1.6rem; line-height: 1;
        }
        .base-label {
            font-size: 0.72rem; color: var(--text-muted);
            font-weight: 700; letter-spacing: 0.3px;
            text-transform: uppercase;
        }
        .base-name {
            font-size: 1.05rem; font-weight: 900; color: var(--text);
            margin-top: 2px;
        }
        .base-meta {
            margin-right: auto;
            font-family: var(--font-num);
            font-size: 0.8rem; color: var(--text-muted);
            font-weight: 700;
        }
        .base-meta .iso {
            display: inline-block;
            background: var(--primary);
            color: white;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 0.75rem;
            letter-spacing: 0.5px;
        }

        /* Rates grid */
        .rate-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 14px;
        }

        .rate-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 18px 20px;
            position: relative;
            transition: transform 0.15s, box-shadow 0.15s, border-color 0.15s;
        }
        .rate-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(29,42,53,0.10);
            border-color: var(--primary);
        }
        .rate-card.is-base {
            border-color: var(--primary);
            border-width: 2px;
            background: linear-gradient(180deg, var(--primary-light), var(--surface) 60%);
        }
        .rate-card.is-base::after {
            content: 'أساسية';
            position: absolute;
            top: 14px; left: 14px;
            background: var(--primary);
            color: white;
            font-size: 0.62rem;
            font-weight: 800;
            padding: 2px 8px;
            border-radius: 999px;
            letter-spacing: 0.3px;
        }

        .rate-head {
            display: flex; align-items: baseline;
            justify-content: space-between; gap: 8px;
            margin-bottom: 16px;
        }
        .rate-name {
            font-size: 1rem; font-weight: 800;
            color: var(--text); line-height: 1.2;
        }
        .rate-iso {
            font-family: var(--font-num);
            font-size: 0.72rem;
            color: var(--text-muted);
            font-weight: 700;
            letter-spacing: 0.6px;
            background: var(--bg);
            padding: 2px 8px;
            border-radius: 4px;
        }

        .rate-main {
            font-family: var(--font-num);
            font-variant-numeric: tabular-nums;
            font-size: 1.65rem;
            font-weight: 700;
            color: var(--primary-dark);
            line-height: 1.1;
            direction: ltr;
            text-align: right;
            word-break: break-all;
        }
        .rate-main-sub {
            font-family: 'Tajawal', sans-serif;
            font-size: 0.72rem;
            font-weight: 600;
            color: var(--text-muted);
            direction: rtl;
            margin-bottom: 4px;
        }
        .rate-main-label {
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--text-muted);
            margin-bottom: 4px;
        }

        .rate-inverse {
            font-family: var(--font-num);
            font-variant-numeric: tabular-nums;
            font-size: 0.82rem;
            color: var(--text-muted);
            direction: ltr;
            text-align: right;
            margin-top: 6px;
            word-break: break-all;
        }
        .rate-inverse-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--text-muted);
            direction: rtl;
            margin-top: 2px;
        }

        .rate-footer {
            margin-top: 14px;
            padding-top: 12px;
            border-top: 1px dashed var(--border);
            display: flex; align-items: center;
            justify-content: space-between; gap: 8px;
            font-size: 0.72rem;
            color: var(--text-muted);
            font-weight: 600;
        }
        .rate-date {
            font-family: var(--font-num);
            font-variant-numeric: tabular-nums;
        }
        .rate-change {
            font-family: var(--font-num);
            font-variant-numeric: tabular-nums;
            font-weight: 800;
            font-size: 0.78rem;
            padding: 2px 8px;
            border-radius: 999px;
            white-space: nowrap;
        }
        .rate-change.up   { color: var(--accent); background: rgba(47,76,59,0.10); }
        .rate-change.down { color: var(--danger); background: rgba(162,59,46,0.08); }
        .rate-change.flat { color: var(--text-muted); background: var(--bg); }

                .loading, .empty {
            padding: 40px 20px; text-align: center;
            color: var(--text-muted); font-size: 0.9rem;
            grid-column: 1 / -1;
        }

        /* ── History section ─────────────────────────────────────── */
        .section {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); overflow: hidden;
        }
        .section-title {
            margin: 0; padding: 12px 18px; font-size: 0.95rem; font-weight: 700;
            background: var(--header-bg); color: var(--header-fg);
            border-bottom: 2px solid var(--primary);
            display: flex; align-items: center; justify-content: space-between;
            gap: 8px;
        }
        .section-title .muted {
            font-size: 0.78rem; font-weight: 600; opacity: 0.85;
        }

        .history-filter {
            display: flex; align-items: center; flex-wrap: wrap; gap: 10px;
            padding: 12px 18px; background: var(--bg);
            border-bottom: 1px solid var(--border);
        }
        .history-filter label {
            font-size: 0.75rem; color: var(--text-muted); font-weight: 700;
        }
        .history-filter select {
            padding: 7px 12px; border: 1px solid var(--border);
            border-radius: 9px; font-size: 0.82rem; font-family: inherit;
            background: var(--surface); color: var(--text);
            min-width: 180px;
        }
        .history-filter select:focus { outline: none; border-color: var(--primary); }
        .history-filter .spacer { flex: 1; }
        .btn-primary {
            background: var(--primary); border: 1px solid var(--primary);
            color: white; padding: 7px 14px; border-radius: 999px;
            font-size: 0.78rem; font-weight: 700; font-family: inherit;
            cursor: pointer; transition: background 0.15s;
        }
        .btn-primary:hover { background: var(--primary-dark); }

        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        th {
            background: var(--bg); color: var(--text); font-weight: 700;
            padding: 10px 12px; border-bottom: 1px solid var(--border);
            text-align: right; white-space: nowrap;
        }
        td {
            padding: 10px 12px; border-bottom: 1px solid var(--border);
            text-align: right; color: var(--text); vertical-align: middle;
        }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover td { background: var(--primary-light); }
        .num { font-family: var(--font-num); font-variant-numeric: tabular-nums; }
        .muted { color: var(--text-muted); }

        .hist-iso {
            display: inline-block;
            font-family: var(--font-num);
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.4px;
            background: var(--bg);
            color: var(--text-muted);
            padding: 2px 7px;
            border-radius: 4px;
            margin-right: 6px;
        }
        .hist-rate {
            font-family: var(--font-num);
            font-variant-numeric: tabular-nums;
            font-weight: 700;
            direction: ltr;
            display: inline-block;
            text-align: right;
        }
        .hist-rate-inv {
            font-family: var(--font-num);
            font-variant-numeric: tabular-nums;
            font-size: 0.72rem;
            color: var(--text-muted);
            direction: ltr;
            display: block;
            margin-top: 2px;
        }

        th.sortable { cursor: pointer; user-select: none; white-space: nowrap; }
        th.sortable:hover { color: var(--primary-dark); }
        th.sortable.sort-active { color: var(--primary-dark); }
        .sort-ind {
            display: inline-block; width: 12px; margin-right: 4px;
            color: var(--text-muted); font-size: 0.7rem;
        }
        th.sortable.sort-active .sort-ind {
            color: var(--primary-dark); font-weight: 900;
        }

        .hist-change {
            font-family: var(--font-num);
            font-variant-numeric: tabular-nums;
            font-weight: 800;
            font-size: 0.78rem;
            padding: 2px 8px;
            border-radius: 999px;
            white-space: nowrap;
            display: inline-block;
        }
        .hist-change.up   { color: var(--accent); background: rgba(47,76,59,0.10); }
        .hist-change.down { color: var(--danger); background: rgba(162,59,46,0.08); }
        .hist-change.flat { color: var(--text-muted); background: var(--bg); }

        .load-more {
            display: block; width: 100%; padding: 11px;
            background: var(--bg); border: none;
            border-top: 1px solid var(--border);
            color: var(--primary-dark); font-weight: 700;
            font-size: 0.85rem; font-family: inherit; cursor: pointer;
        }
        .load-more:hover { background: var(--primary-light); }

        @media (max-width: 600px) {
            body { padding: 10px; }
            h1 { font-size: 1.1rem; }
            .base-banner { padding: 12px 14px; }
            .base-name { font-size: 0.95rem; }
            .rate-grid { grid-template-columns: 1fr; gap: 10px; }
            .rate-card { padding: 14px 16px; }
            .rate-main { font-size: 1.4rem; }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="page-header">
            <div class="topbar-start">
                <div class="menu-wrap" id="menuWrap">
                    <button type="button" class="menu-toggle-btn" id="menuToggleBtn"
                            title="القائمة" aria-haspopup="true" aria-expanded="false">☰</button>
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
                            <a href="salesmen.php" class="menu-item"><span class="tile-icon"><i class="ti ti-user"></i></span><span>مندوبو المبيعات</span></a>
                            <a href="accounts.php" class="menu-item"><span class="tile-icon"><i class="ti ti-wallet"></i></span><span>الحسابات</span></a>
                            <a href="bills.php" class="menu-item"><span class="tile-icon"><i class="ti ti-receipt"></i></span><span>أنماط الفواتير</span></a>
                            <a href="cost-centers.php" class="menu-item"><span class="tile-icon"><i class="ti ti-briefcase"></i></span><span>مراكز التكلفة</span></a>
                            <a href="customer-statement.php" class="menu-item"><span class="tile-icon"><i class="ti ti-file-invoice"></i></span><span>كشف حساب العميل</span></a>
                            <a href="exchange-rates.php" class="menu-item"><span class="tile-icon"><i class="ti ti-currency-dollar"></i></span><span>أسعار الصرف</span></a>
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
                                <span class="menu-item-icon">🚪</span><span>تسجيل الخروج</span>
                            </a>
                        </div>
                    </div>
                </div>
                <h1>💱 أسعار الصرف</h1>
            </div>
        </div>

        <div class="base-banner" id="baseBanner" style="display:none;">
            <span class="base-icon">💵</span>
            <div>
                <div class="base-label">العملة الأساسية</div>
                <div class="base-name" id="baseName">—</div>
            </div>
            <div class="base-meta">
                <span class="iso" id="baseISO">—</span>
            </div>
        </div>

                <div class="rate-grid" id="rateGrid">
            <div class="loading">جاري التحميل...</div>
        </div>

        <!-- ─── Rate history ───────────────────────────────────── -->
        <div class="section" id="historySection" style="margin-top:22px;">
            <h2 class="section-title">
                <span>📋 سجل أسعار الصرف</span>
                <span class="muted" id="historyCount"></span>
            </h2>

            <div class="history-filter">
                <label>العملة</label>
                <select id="historyCurrency">
                    <option value="">كل العملات</option>
                </select>
                <span class="spacer"></span>
                <button type="button" class="btn-primary" id="historyResetBtn">↺ إعادة تعيين</button>
            </div>

            <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th class="sortable hist-sortable" data-sort="date">التاريخ <span class="sort-ind"></span></th>
                            <th class="sortable hist-sortable" data-sort="currency">العملة <span class="sort-ind"></span></th>
                            <th class="sortable hist-sortable" data-sort="rate">السعر (1 USD =) <span class="sort-ind"></span></th>
                            <th>التغيير</th>
                        </tr>
                    </thead>
                    <tbody id="historyBody">
                        <tr><td colspan="5" class="loading">جاري التحميل...</td></tr>
                    </tbody>
                </table>
            </div>
            <button type="button" class="load-more" id="historyMoreBtn" style="display:none;">➕ تحميل المزيد</button>
        </div>
    </div>

    <script>
        // ---------- Helpers ----------
        function escapeHtml(str) {
            const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
            return String(str === null || str === undefined ? '' : str).replace(/[&<>"']/g, m => map[m]);
        }

        // Dynamic precision: small values (like SYP) get more decimals,
        // large values (like the 12000+ inverse) stay compact.
        function fmtRate(v) {
            const n = parseFloat(v);
            if (!isFinite(n) || n === 0) return '0';
            const abs = Math.abs(n);
            let maxDec;
            if (abs >= 1000)      maxDec = 2;
            else if (abs >= 1)    maxDec = 4;
            else if (abs >= 0.01) maxDec = 6;
            else                  maxDec = 10;
            return n.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: maxDec
            });
        }

        function fmtPct(v) {
            if (v === null || v === undefined) return null;
            const n = parseFloat(v);
            if (!isFinite(n)) return null;
            const sign = n > 0 ? '+' : '';
            return sign + n.toFixed(2) + '%';
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

        // ---------- Hamburger ----------
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

        // ---------- Load & render ----------
        async function loadRates() {
            const grid = document.getElementById('rateGrid');
            grid.innerHTML = '<div class="loading">جاري التحميل...</div>';
            try {
                const resp = await fetch('?rates=1', { cache: 'no-store' });
                if (resp.status === 401) { window.location.href = 'login.php'; return; }
                const data = await resp.json();
                if (!data || data.error) {
                    grid.innerHTML = `<div class="loading">خطأ: ${escapeHtml((data && data.error) || '')}</div>`;
                    return;
                }
                renderRates(data);
            } catch (e) {
                console.error(e);
                grid.innerHTML = `<div class="loading">خطأ في الشبكة: ${escapeHtml(e.message)}</div>`;
            }
        }

        function renderRates(data) {
            // Base currency banner
            const base = data.base || {};
            document.getElementById('baseName').textContent = base.Name || '—';
            document.getElementById('baseISO').textContent  = base.ISO || base.Code || '—';
            document.getElementById('baseBanner').style.display = '';

            const grid = document.getElementById('rateGrid');
            const currencies = Array.isArray(data.currencies) ? data.currencies : [];

            if (!currencies.length) {
                grid.innerHTML = '<div class="empty">لا توجد عملات مسجّلة.</div>';
                return;
            }

            let html = '';
            for (const c of currencies) {
                const isBase = !!c.IsBase;

                // Change chip
                const pct = c.ChangePct;
                let changeChip = '<span class="rate-change flat">— لا يوجد سعر سابق</span>';
                if (pct !== null && pct !== undefined) {
                    const cls = pct > 0.005 ? 'up' : (pct < -0.005 ? 'down' : 'flat');
                    const arrow = pct > 0.005 ? '▲' : (pct < -0.005 ? '▼' : '·');
                    changeChip = `<span class="rate-change ${cls}">${arrow} ${fmtPct(pct)}</span>`;
                }

                const dateStr = c.LatestDate
                    ? new Date(c.LatestDate).toLocaleDateString('en-GB')
                    : '—';

                // For the base currency, show it distinctly
                if (isBase) {
                    html += `
                        <div class="rate-card is-base">
                            <div class="rate-head">
                                <div class="rate-name">${escapeHtml(c.Name || '—')}</div>
                                <span class="rate-iso">${escapeHtml(c.ISO || c.Code || '')}</span>
                            </div>
                            <div class="rate-main-label">القيمة المرجعية</div>
                            <div class="rate-main">1.00</div>
                            <div class="rate-inverse-label">تُقاس جميع الأسعار الأخرى بالنسبة لهذه العملة</div>
                            <div class="rate-footer">
                                <span class="rate-date">${escapeHtml(dateStr)}</span>
                                <span class="rate-change flat">· مرجعي</span>
                            </div>
                        </div>`;
                    continue;
                }

                html += `
                    <div class="rate-card">
                        <div class="rate-head">
                            <div class="rate-name">${escapeHtml(c.Name || '—')}</div>
                            <span class="rate-iso">${escapeHtml(c.ISO || c.Code || '')}</span>
                        </div>
                        <div class="rate-main-label">1 دولار أمريكي يساوي</div>
                        <div class="rate-main">${fmtRate(c.FromUSD)}</div>
                        <div class="rate-main-sub">${escapeHtml(c.Code || '')}</div>
                        <div class="rate-inverse-label">وبالمقابل</div>
                        <div class="rate-inverse">1 ${escapeHtml(c.Code || '')} = ${fmtRate(c.ToUSD)} USD</div>
                        <div class="rate-footer">
                            <span class="rate-date">آخر تحديث: ${escapeHtml(dateStr)}</span>
                            ${changeChip}
                        </div>
                    </div>`;
            }
            grid.innerHTML = html;
        }

                // ---------- History state ----------
        let histOffset  = 0;
        let histHasMore = true;
        let histSort    = { key: 'date', dir: 'desc' };
        let histFilter  = '';
        let histLoadingMore = false;

        function updateHistSortIndicators() {
            document.querySelectorAll('th.hist-sortable').forEach(th => {
                const ind = th.querySelector('.sort-ind');
                if (th.dataset.sort === histSort.key) {
                    th.classList.add('sort-active');
                    if (ind) ind.textContent = histSort.dir === 'asc' ? '▲' : '▼';
                } else {
                    th.classList.remove('sort-active');
                    if (ind) ind.textContent = '';
                }
            });
        }

        document.querySelectorAll('th.hist-sortable').forEach(th => {
            th.addEventListener('click', () => {
                const key = th.dataset.sort;
                if (histSort.key === key) {
                    histSort.dir = histSort.dir === 'asc' ? 'desc' : 'asc';
                } else {
                    histSort.key = key;
                    // text → asc, date/rate → desc by default
                    histSort.dir = (key === 'currency') ? 'asc' : 'desc';
                }
                updateHistSortIndicators();
                loadHistory(true);
            });
        });

        document.getElementById('historyCurrency').addEventListener('change', (e) => {
            histFilter = e.target.value;
            loadHistory(true);
        });

        document.getElementById('historyResetBtn').addEventListener('click', () => {
            histFilter = '';
            histSort   = { key: 'date', dir: 'desc' };
            document.getElementById('historyCurrency').value = '';
            updateHistSortIndicators();
            loadHistory(true);
        });

        document.getElementById('historyMoreBtn').addEventListener('click', () => {
            if (!histHasMore || histLoadingMore) return;
            histLoadingMore = true;
            loadHistory(false).finally(() => { histLoadingMore = false; });
        });

        async function loadHistory(reset) {
            if (reset) {
                histOffset  = 0;
                histHasMore = true;
                document.getElementById('historyBody').innerHTML =
                    '<tr><td colspan="5" class="loading">جاري التحميل...</td></tr>';
            }

            const params = new URLSearchParams({
                history: '1',
                offset:  histOffset,
                sort:    histSort.key,
                dir:     histSort.dir,
            });
            if (histFilter) params.set('currency', histFilter);

            try {
                const resp = await fetch('?' + params.toString(), { cache: 'no-store' });
                if (resp.status === 401) { window.location.href = 'login.php'; return; }
                const data = await resp.json();
                if (!data || data.error) {
                    document.getElementById('historyBody').innerHTML =
                        `<tr><td colspan="5" class="loading">خطأ: ${escapeHtml((data && data.error) || '')}</td></tr>`;
                    return;
                }

                const body = document.getElementById('historyBody');
                if (reset) body.innerHTML = '';

                const items = Array.isArray(data.items) ? data.items : [];

                if (!items.length && reset) {
                    body.innerHTML = '<tr><td colspan="5" class="empty">لا توجد سجلات مطابقة.</td></tr>';
                    document.getElementById('historyCount').textContent = '';
                    document.getElementById('historyMoreBtn').style.display = 'none';
                    return;
                }

                const frag = document.createDocumentFragment();
                let idx = reset ? 0 : histOffset;

                for (const it of items) {
                    idx++;
                    const tr = document.createElement('tr');

                    const dateStr = it.Date
                        ? new Date(it.Date).toLocaleDateString('en-GB')
                        : '-';
                    const iso = it.CurrencyISO || it.CurrencyCode || '';
                    const fromUsd = fmtRate(it.FromUSD);
                    const toUsd   = fmtRate(it.ToUSD);

                    let changeHtml = '<span class="hist-change flat">—</span>';
                    if (it.ChangePct !== null && it.ChangePct !== undefined) {
                        const c = it.ChangePct;
                        const cls = c > 0.005 ? 'up' : (c < -0.005 ? 'down' : 'flat');
                        const arrow = c > 0.005 ? '▲' : (c < -0.005 ? '▼' : '·');
                        const pct = (c > 0 ? '+' : '') + c.toFixed(2) + '%';
                        changeHtml = `<span class="hist-change ${cls}">${arrow} ${pct}</span>`;
                    }

                    tr.innerHTML = `
                        <td class="num muted">${idx}</td>
                        <td class="num">${escapeHtml(dateStr)}</td>
                        <td><span class="hist-iso">${escapeHtml(iso)}</span>${escapeHtml(it.CurrencyName || '-')}</td>
                        <td>
                            <span class="hist-rate">${fromUsd}</span>
                            <span class="hist-rate-inv">1 ${escapeHtml(it.CurrencyCode || '')} = ${toUsd} USD</span>
                        </td>
                        <td>${changeHtml}</td>
                    `;
                    frag.appendChild(tr);
                }
                body.appendChild(frag);

                histHasMore = !!data.hasMore;
                histOffset += items.length;
                document.getElementById('historyMoreBtn').style.display = histHasMore ? 'block' : 'none';
                document.getElementById('historyCount').textContent =
                    `(${histOffset}${histHasMore ? '+' : ''} سجل)`;
            } catch (e) {
                console.error(e);
                if (reset) {
                    document.getElementById('historyBody').innerHTML =
                        `<tr><td colspan="5" class="loading">خطأ في الشبكة: ${escapeHtml(e.message)}</td></tr>`;
                }
            }
        }

        // Populate the currency filter from the currencies we already have
        function populateHistoryFilter(currencies) {
            const sel = document.getElementById('historyCurrency');
            // preserve current selection when re-populating
            const cur = sel.value;
            sel.innerHTML = '<option value="">كل العملات</option>';
            for (const c of currencies) {
                const opt = document.createElement('option');
                opt.value = c.GUID;
                opt.textContent = (c.Name || '') + ' — ' + (c.ISO || c.Code || '');
                sel.appendChild(opt);
            }
            sel.value = cur;
        }

        // ---------- Bootstrap ----------
        (async () => {
            // The rates endpoint returns the currency list; capture it
            // and hand it to the history filter dropdown, then wire up
            // the history table.
            const orig = window.renderRates;
            window.renderRates = function (data) {
                orig(data);
                populateHistoryFilter(data.currencies || []);
            };

            await loadRates();
            updateHistSortIndicators();
            loadHistory(true);
        })();
    </script>
</body>
</html>