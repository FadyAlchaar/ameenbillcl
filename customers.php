<?php
// customers.php - customer directory + per-customer profile.
require_once 'config.php';
require_once 'auth.php';

$isApiRequest = isset($_GET['list']) || isset($_GET['customer']);
requireLogin($isApiRequest);

// ─── API: paginated customer list ────────────────────────────────
if (isset($_GET['list']) && $_GET['list'] == 1) {
    header('Content-Type: application/json');

    $search = isset($_GET['search']) && $_GET['search'] !== '' ? trim($_GET['search']) : null;
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
    $limit  = 100;

    // Whitelisted sort mapping — never interpolate user input into SQL.
    $sortKey = isset($_GET['sort']) ? $_GET['sort'] : 'Number';
    $sortDir = (isset($_GET['dir']) && strtolower($_GET['dir']) === 'asc') ? 'ASC' : 'DESC';

    $sortMap = [
        'Number'       => 'CAST(Number AS INT)',
        'CustomerName' => 'CustomerName',
        'Phone1'       => 'Phone1',
        'Mobile'       => 'Mobile',
        'Balance'      => '(Debit - Credit)',
    ];
    $orderBy  = $sortMap[$sortKey] ?? $sortMap['Number'];
    $orderSql = "ORDER BY $orderBy $sortDir";

    try {
        $pdo = getDBConnection();

        $where  = [];
        $params = [];
        if ($search !== null) {
            $where[] = "(CustomerName LIKE :s1 OR Number LIKE :s2 OR Phone1 LIKE :s3 OR Mobile LIKE :s4)";
            $like = '%' . $search . '%';
            $params[':s1'] = $like;
            $params[':s2'] = $like;
            $params[':s3'] = $like;
            $params[':s4'] = $like;
        }
        $whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

        $sql = "SELECT Number, CustomerName, Phone1, Mobile, Debit, Credit, GUID
                FROM cu000
                $whereSql
                $orderSql
                OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit',  $limit + 1, PDO::PARAM_INT);   // +1 to detect more
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);

        $customers = [];
        foreach ($rows as $r) {
            $debit  = (float)($r['Debit']  ?? 0);
            $credit = (float)($r['Credit'] ?? 0);
            $customers[] = [
                'Number'       => $r['Number'],
                'CustomerName' => $r['CustomerName'],
                'Phone1'       => $r['Phone1'],
                'Mobile'       => $r['Mobile'],
                'Debit'        => $debit,
                'Credit'       => $credit,
                'Balance'      => $debit - $credit,
                'GUID'         => $r['GUID'],
            ];
        }

        echo json_encode(
            ['customers' => $customers, 'hasMore' => $hasMore],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    } catch (Throwable $e) {
        jsonFail($e, 500, 'Could not load customers.');
    }
    exit;
}

// ─── API: single customer + recent bills ─────────────────────────
if (isset($_GET['customer']) && $_GET['customer'] == 1) {
    header('Content-Type: application/json');

    $guid = isset($_GET['guid']) ? $_GET['guid'] : '';
    if (!preg_match('/^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/', $guid)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid GUID']);
        exit;
    }

    try {
        $pdo = getDBConnection();

        $stmt = $pdo->prepare("
            SELECT Number, CustomerName, LatinName, Phone1, Phone2, FAX, Mobile,
                   EMail, HomePage, Notes, Debit, Credit, TaxNumber, ExemptFromTax,
                   BarCode, DistCustForceVisitStartWithBarcode, DistCustForceVisitEndWithBarcode,
                   UseFlag, GUID
            FROM cu000 WHERE GUID = :guid
        ");
        $stmt->execute([':guid' => $guid]);
        $cust = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cust) {
            http_response_code(404);
            echo json_encode(['error' => 'Customer not found']);
            exit;
        }

        $debit  = (float)($cust['Debit']  ?? 0);
        $credit = (float)($cust['Credit'] ?? 0);
        $cust['Debit']   = $debit;
        $cust['Credit']  = $credit;
        $cust['Balance'] = $debit - $credit;

                // Recent bills — joined by CustGUID (the real FK from Alameen).
        // Faster than matching Cust_Name: binary compare, no collation,
        // and the value is guaranteed to be in sync with cu000.
        $billsStmt = $pdo->prepare("
            SELECT TOP 50
                b.GUID, b.Number, b.Date, b.PayType,
                b.Total, b.TotalDisc, b.TotalExtra, b.CurrencyVal,
                cur.Name AS CurrencyName
            FROM bu000 b
            LEFT JOIN my000 cur ON b.CurrencyGUID = cur.GUID
            WHERE b.CustGUID = :custGuid
            ORDER BY b.CreateDate DESC
        ");
        $billsStmt->execute([':custGuid' => $guid]);
        $bills = $billsStmt->fetchAll(PDO::FETCH_ASSOC);

                // Addresses + GPS from CustAddress000. Multiple rows per customer are
        // expected; "الرئيسي" is usually the primary one.
        $addrStmt = $pdo->prepare("
            SELECT Number, Name, District, GPSX, GPSY, GPSZ
            FROM CustAddress000
            WHERE CustomerGUID = :custGuid
            ORDER BY CAST(Number AS INT)
        ");
        $addrStmt->execute([':custGuid' => $guid]);
        $addresses = $addrStmt->fetchAll(PDO::FETCH_ASSOC);

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
            'customer'  => $cust,
            'bills'     => $bills,
            'addresses' => $addresses,
            'summary'   => [
                'billCount' => count($bills),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        jsonFail($e, 500, 'Could not load customer.');
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الزبائن - بياناتي ويب</title>
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
            --primary: #B8863C;
            --primary-dark: #96692C;
            --primary-light: rgba(184,134,60,0.10);
            --accent: #2F4C3B;
            --success: #2F4C3B;
            --danger: #A23B2E;
            --bg: #F6F1E4;
            --surface: #FBF8F0;
            --text: #1D2A35;
            --text-muted: #5B6672;
            --border: #C9C0AC;
            --radius: 6px;
            --shadow-sm: none;
            --shadow-md: 0 2px 8px rgba(29,42,53,0.08);
            --font-num: 'Fraunces', Georgia, serif;
            --header-bg: #1D2A35;
            --header-fg: #F6F1E4;
            color-scheme: light;
        }
        @media (prefers-color-scheme: dark) {
            :root:not([data-theme]) {
                --primary: #D9A855;
                --primary-dark: #B8863C;
                --primary-light: rgba(217,168,85,0.16);
                --accent: #4C7B60;
                --success: #4C7B60;
                --danger: #C96354;
                --bg: #12181F; --surface: #1B232C; --text: #EDE6D6;
                --text-muted: #8C97A3; --border: #2B3542;
                color-scheme: dark;
            }
        }
        :root[data-theme="dark"] {
            --primary: #D9A855; --primary-dark: #B8863C;
            --primary-light: rgba(217,168,85,0.16);
            --accent: #4C7B60; --success: #4C7B60; --danger: #C96354;
            --bg: #12181F; --surface: #1B232C; --text: #EDE6D6;
            --text-muted: #8C97A3; --border: #2B3542;
            color-scheme: dark;
        }
        :root[data-theme="bright"] {
            --bg: #FFFFFF; --surface: #FFFFFF;
            --text: #1D2A35; --text-muted: #5B6672; --border: #E2E5E9;
            color-scheme: light;
        }
        :root[data-theme="classic"] {
            --primary: #4f46e5; --primary-dark: #4338ca;
            --primary-light: #eef2ff;
            --accent: #06b6d4; --success: #10b981; --danger: #ef4444;
            --bg: #f4f5fa; --surface: #ffffff; --text: #1e1b2e;
            --text-muted: #6b7280; --border: #e5e7eb; --radius: 14px;
            --font-num: 'Tajawal', sans-serif;
            color-scheme: light;
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
        h1 {
            font-size: 1.4rem; font-weight: 900; margin: 0;
            display: flex; align-items: center; gap: 8px;
        }
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
            font-size: 0.78rem; font-weight: 700;
            color: var(--text-muted); font-family: inherit;
            cursor: pointer; text-decoration: none;
            transition: all 0.15s;
        }
        .btn:hover { border-color: var(--primary); color: var(--primary); }
        .btn-primary {
            background: var(--primary); border-color: var(--primary); color: white;
        }
        .btn-primary:hover { background: var(--primary-dark); }

        .search-bar {
            display: flex; gap: 10px; margin-bottom: 16px;
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 10px 14px;
            align-items: center;
        }
        .search-bar input {
            flex: 1; padding: 8px 12px;
            border: 1px solid var(--border); border-radius: 999px;
            background: var(--bg); color: var(--text);
            font-family: inherit; font-size: 0.85rem;
        }
        .search-bar input:focus {
            outline: none; border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-light);
        }

        .section {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); margin-bottom: 16px; overflow: hidden;
        }
        .section-title {
            margin: 0; padding: 12px 18px;
            font-size: 0.95rem; font-weight: 700;
            background: var(--header-bg); color: var(--header-fg);
            border-bottom: 2px solid var(--primary);
        }
        table {
            width: 100%; border-collapse: collapse; font-size: 0.85rem;
        }
        th {
            background: var(--bg); color: var(--text);
            font-weight: 700; padding: 10px 12px;
            border-bottom: 1px solid var(--border);
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
        .pos { color: var(--danger); font-weight: 700; }
        .neg { color: var(--accent); font-weight: 700; }
        .zero { color: var(--text-muted); }
                /* Sortable column headers */
        th.sortable {
            cursor: pointer;
            user-select: none;
            white-space: nowrap;
        }
        th.sortable:hover { color: var(--primary-dark); }
        th.sortable.sort-active { color: var(--primary-dark); }
        .sort-ind {
            display: inline-block;
            width: 12px;
            margin-right: 4px;
            color: var(--text-muted);
            font-size: 0.7rem;
        }
        th.sortable.sort-active .sort-ind {
            color: var(--primary-dark);
            font-weight: 900;
        }

        .load-more {
            display: block; width: 100%; padding: 11px;
            background: var(--bg); border: none;
            border-top: 1px solid var(--border);
            color: var(--primary-dark); font-weight: 700;
            font-size: 0.85rem; font-family: inherit; cursor: pointer;
        }
        .load-more:hover { background: var(--primary-light); }

        /* Detail view */
        .cust-header {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 16px 20px;
            margin-bottom: 16px;
        }
        .cust-header h2 { margin: 0 0 4px; font-size: 1.3rem; font-weight: 900; }
        .cust-header .sub { color: var(--text-muted); font-size: 0.85rem; }

        .grid-2 {
            display: grid; grid-template-columns: 1fr 1fr;
            gap: 12px; margin-bottom: 16px;
        }
        @media (max-width: 700px) { .grid-2 { grid-template-columns: 1fr; } }

        .info-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 14px 18px;
        }
        .info-card h3 {
            margin: 0 0 12px; font-size: 0.9rem; font-weight: 800;
            color: var(--primary-dark);
            border-bottom: 1px solid var(--border); padding-bottom: 8px;
        }
        .info-row {
            display: flex; justify-content: space-between; gap: 12px;
            padding: 5px 0; font-size: 0.85rem;
            border-bottom: 1px dashed var(--border);
        }
        .info-row:last-child { border-bottom: none; }
        .info-row .lbl { color: var(--text-muted); font-weight: 600; flex-shrink: 0; }
        .info-row .val { text-align: left; word-break: break-word; }
        .info-row .val a { color: var(--primary-dark); text-decoration: none; }
        .info-row .val a:hover { text-decoration: underline; }

        .balance-row {
            display: flex; align-items: baseline; justify-content: space-between;
            padding: 8px 0; font-size: 1rem;
            border-bottom: 1px dashed var(--border);
        }
        .balance-row:last-child { border-bottom: none; padding-top: 12px; }
        .balance-row .lbl { color: var(--text-muted); font-weight: 700; }
        .balance-row .val {
            font-family: var(--font-num); font-variant-numeric: tabular-nums;
            font-weight: 700; font-size: 1.15rem;
        }
        .balance-row.grand .val { font-size: 1.5rem; }

        .loading, .empty {
            padding: 30px; text-align: center;
            color: var(--text-muted); font-size: 0.85rem;
        }

        @media (max-width: 600px) {
            body { padding: 10px; }
            h1 { font-size: 1.1rem; }
            th, td { padding: 8px 6px; font-size: 0.75rem; }
            .cust-header { padding: 12px 14px; }
            .cust-header h2 { font-size: 1.05rem; }
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
                            <a href="stats.php" class="menu-item"><span class="menu-item-icon">📈</span><span>الإحصائيات</span></a>
                            <a href="products.php" class="menu-item"><span class="menu-item-icon">📦</span><span>الأصناف</span></a>
                            <a href="inventory.php" class="menu-item"><span class="menu-item-icon">🏭</span><span>المخزون</span></a>
                            <a href="movements.php" class="menu-item"><span class="menu-item-icon">📄</span><span>حركة المواد</span></a>
                            <a href="serial-movements.php" class="menu-item"><span class="menu-item-icon">🔢</span><span>حركة الأرقام التسلسلية</span></a>
                            <a href="customers.php" class="menu-item"><span class="menu-item-icon">👥</span><span>الزبائن</span></a>
                            <a href="salesmen.php" class="menu-item"><span class="menu-item-icon">🧑</span><span>البائعون</span></a>
                            <a href="accounts.php" class="menu-item"><span class="menu-item-icon">💰</span><span>الحسابات</span></a>
                            <a href="bills.php" class="menu-item"><span class="menu-item-icon">📋</span><span>أنماط الفواتير</span></a>
                            <a href="cost-centers.php" class="menu-item"><span class="menu-item-icon">💼</span><span>مراكز التكلفة</span></a>
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
                <h1>📊 لوحة الإحصائيات</h1>
            </div>
        </div>

        <!-- LIST VIEW (default) -->
        <div id="listView">
            <div class="search-bar">
                <input type="text" id="searchInput"
                       placeholder="🔍 ابحث بالاسم أو الرقم أو الهاتف..."
                       autocomplete="off">
            </div>
            <div class="section">
                <h2 class="section-title">قائمة الزبائن <span id="listCount" class="muted" style="font-size:0.8rem;font-weight:600;"></span></h2>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th class="sortable list-sortable" data-sort="Number"># <span class="sort-ind"></span></th>
                                <th class="sortable list-sortable" data-sort="CustomerName">الاسم <span class="sort-ind"></span></th>
                                <th class="sortable list-sortable" data-sort="Phone1">الهاتف <span class="sort-ind"></span></th>
                                <th class="sortable list-sortable" data-sort="Mobile">الموبايل <span class="sort-ind"></span></th>
                                <th class="sortable list-sortable" data-sort="Balance">الرصيد <span class="sort-ind"></span></th>
                            </tr>
                        </thead>
                        <tbody id="listBody">
                            <tr><td colspan="5" class="loading">جاري التحميل...</td></tr>
                        </tbody>
                    </table>
                </div>
                <button type="button" class="load-more" id="loadMoreBtn" style="display:none;">➕ تحميل المزيد</button>
            </div>
        </div>

        <!-- DETAIL VIEW (shown when ?guid= present) -->
        <div id="detailView" style="display:none;">
            <div id="detailHeader" class="cust-header">
                <h2 id="detailName">—</h2>
                <div class="sub" id="detailSub">—</div>
            </div>

            <div class="grid-2">
                <div class="info-card">
                    <h3>📞 معلومات الاتصال</h3>
                    <div id="contactBody"></div>
                </div>
                <div class="info-card">
                    <h3>💰 الرصيد</h3>
                    <div id="balanceBody"></div>
                </div>
            </div>

            <div class="section">
                <h2 class="section-title">📍 العناوين والمواقع</h2>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th class="sortable addr-sortable" data-sort="Number"># <span class="sort-ind"></span></th>
                                <th class="sortable addr-sortable" data-sort="Name">اسم العنوان <span class="sort-ind"></span></th>
                                <th class="sortable addr-sortable" data-sort="District">الحي <span class="sort-ind"></span></th>
                                <th class="sortable addr-sortable" data-sort="GPSX">GPSX <span class="sort-ind"></span></th>
                                <th class="sortable addr-sortable" data-sort="GPSY">GPSY <span class="sort-ind"></span></th>
                                <th class="sortable addr-sortable" data-sort="GPSZ">GPSZ <span class="sort-ind"></span></th>
                                <th>الخريطة</th>
                            </tr>
                        </thead>
                        <tbody id="addressesBody">
                            <tr><td colspan="7" class="loading">جاري التحميل...</td></tr>
                        </tbody>
                    </table>
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
                                <th class="sortable" data-sort="Number">رقم الفاتورة <span class="sort-ind"></span></th>
                                <th class="sortable" data-sort="Date">التاريخ <span class="sort-ind"></span></th>
                                <th class="sortable" data-sort="CurrencyName">العملة <span class="sort-ind"></span></th>
                                <th class="sortable" data-sort="PayType">طريقة الدفع <span class="sort-ind"></span></th>
                                <th class="sortable" data-sort="Net">الصافي <span class="sort-ind"></span></th>
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
        function balanceClass(v) {
            if (v > 0.005)  return 'pos';   // customer owes us
            if (v < -0.005) return 'neg';   // we owe customer
            return 'zero';
        }

        // ---------- Theme toggle ----------
        (function initThemeToggle() {
            const btn  = document.getElementById('themeToggleBtn');
            const icon = document.getElementById('themeToggleIcon');
            if (!btn || !icon) return;
            const THEMES = ['warm', 'bright', 'dark', 'classic'];
            const THEME_META = {
                warm: { icon: '📜' }, bright: { icon: '☀️' },
                dark: { icon: '🌙' }, classic: { icon: '🔷' }
            };
            function explicit() {
                const v = document.documentElement.getAttribute('data-theme');
                return THEMES.includes(v) ? v : null;
            }
            function effective() {
                return explicit() || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'warm');
            }
            function update() { icon.textContent = THEME_META[effective()].icon; }
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

                // ---------- LIST VIEW ----------
        let listOffset = 0;
        let listHasMore = true;
        let searchTimer = null;
        let currentSearch = '';
        let listSort = { key: 'Number', dir: 'desc' };

        function updateListSortIndicators() {
            document.querySelectorAll('th.list-sortable').forEach(th => {
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

        document.querySelectorAll('th.list-sortable').forEach(th => {
            th.addEventListener('click', () => {
                const key = th.dataset.sort;
                if (listSort.key === key) {
                    listSort.dir = listSort.dir === 'asc' ? 'desc' : 'asc';
                } else {
                    listSort.key = key;
                    listSort.dir = 'asc';
                }
                updateListSortIndicators();
                loadCustomers(true);
            });
        });

        async function loadCustomers(reset) {
            if (reset) {
                listOffset = 0;
                listHasMore = true;
                document.getElementById('listBody').innerHTML =
                    '<tr><td colspan="5" class="loading">جاري التحميل...</td></tr>';
            }
            const params = new URLSearchParams({ list: '1', offset: listOffset });
            if (currentSearch) params.set('search', currentSearch);
            params.set('sort', listSort.key);
            params.set('dir',  listSort.dir);

            try {
                const resp = await fetch('?' + params.toString(), { cache: 'no-store' });
                if (resp.status === 401) { window.location.href = 'login.php'; return; }
                const data = await resp.json();
                if (!data || data.error) {
                    document.getElementById('listBody').innerHTML =
                        `<tr><td colspan="5" class="loading">خطأ: ${escapeHtml(data.error || '')}</td></tr>`;
                    return;
                }

                const body = document.getElementById('listBody');
                if (reset) body.innerHTML = '';

                if (!data.customers.length && reset) {
                    body.innerHTML = '<tr><td colspan="5" class="empty">لا توجد نتائج.</td></tr>';
                } else {
                    for (const c of data.customers) {
                        const tr = document.createElement('tr');
                        tr.className = 'clickable';
                        tr.onclick = () => { window.location.href = 'customers.php?guid=' + encodeURIComponent(c.GUID); };
                        tr.innerHTML = `
                            <td class="num muted">${escapeHtml(c.Number)}</td>
                            <td>${escapeHtml(c.CustomerName || '-')}</td>
                            <td class="num">${escapeHtml(c.Phone1 || '-')}</td>
                            <td class="num">${escapeHtml(c.Mobile || '-')}</td>
                            <td class="num ${balanceClass(c.Balance)}">${fmtNum(c.Balance)}</td>
                        `;
                        body.appendChild(tr);
                    }
                }

                listHasMore = !!data.hasMore;
                listOffset += data.customers.length;
                document.getElementById('loadMoreBtn').style.display = listHasMore ? 'block' : 'none';

                const countEl = document.getElementById('listCount');
                if (countEl) countEl.textContent = `(${listOffset}${listHasMore ? '+' : ''})`;
            } catch (e) {
                console.error(e);
            }
        }

        document.getElementById('searchInput').addEventListener('input', (e) => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                currentSearch = e.target.value.trim();
                loadCustomers(true);
            }, 350);
        });

        document.getElementById('loadMoreBtn').addEventListener('click', () => {
            if (!listHasMore) return;
            loadCustomers(false);
        });

        
                // ---------- Bills table: sorting ----------
        let currentBills = [];
        let sortState = { key: 'Date', dir: 'desc' };

        function renderBills() {
            const bb = document.getElementById('billsBody');
            if (!currentBills.length) {
                bb.innerHTML = '<tr><td colspan="5" class="empty">لا توجد فواتير لهذا الزبون.</td></tr>';
                return;
            }

            const dir = sortState.dir === 'asc' ? 1 : -1;
            const k   = sortState.key;

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
                } else if (k === 'PayType') {
                    va = String(a.PayType ?? '');
                    vb = String(b.PayType ?? '');
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
                const cv   = parseFloat(bill.CurrencyVal) || 1;
                const net  = (parseFloat(bill.Net) || 0) / cv;
                const date = bill.Date ? new Date(bill.Date).toLocaleDateString('en-GB') : '-';
                bh += `<tr>
                    <td class="num">#${escapeHtml(bill.Number)}</td>
                    <td class="num">${date}</td>
                    <td>${escapeHtml(bill.CurrencyName || '-')}</td>
                    <td>${payTypeLabel(bill.PayType)}</td>
                    <td class="num">${fmtNum(net)}</td>
                </tr>`;
            }
            bb.innerHTML = bh;
        }

        function updateSortIndicators() {
            document.querySelectorAll('#detailView th.sortable').forEach(th => {
                const ind = th.querySelector('.sort-ind');
                if (th.dataset.sort === sortState.key) {
                    th.classList.add('sort-active');
                    if (ind) ind.textContent = sortState.dir === 'asc' ? '▲' : '▼';
                } else {
                    th.classList.remove('sort-active');
                    if (ind) ind.textContent = '';
                }
            });
        }

        document.querySelectorAll('#detailView th.sortable').forEach(th => {
            th.addEventListener('click', () => {
                const key = th.dataset.sort;
                if (sortState.key === key) {
                    sortState.dir = sortState.dir === 'asc' ? 'desc' : 'asc';
                } else {
                    sortState.key = key;
                    sortState.dir = 'asc';
                }
                updateSortIndicators();
                renderBills();
            });
        });

                // ---------- Addresses table: sorting ----------
        let currentAddresses = [];
        let addrSort = { key: 'Number', dir: 'asc' };

        function renderAddresses() {
            const ab = document.getElementById('addressesBody');
            if (!currentAddresses.length) {
                ab.innerHTML = '<tr><td colspan="7" class="empty">لا توجد عناوين مسجلة.</td></tr>';
                return;
            }

            const dir = addrSort.dir === 'asc' ? 1 : -1;
            const k   = addrSort.key;

            const sorted = currentAddresses.slice().sort((a, b) => {
                let va, vb;
                if (['GPSX','GPSY','GPSZ'].includes(k)) {
                    va = parseFloat(a[k]) || 0;
                    vb = parseFloat(b[k]) || 0;
                } else if (k === 'Number') {
                    va = parseFloat(a.Number) || 0;
                    vb = parseFloat(b.Number) || 0;
                } else {
                    va = String(a[k] ?? '');
                    vb = String(b[k] ?? '');
                }
                if (va < vb) return -1 * dir;
                if (va > vb) return  1 * dir;
                return 0;
            });

            let ah = '';
            for (const a of sorted) {
                const x = parseFloat(a.GPSX) || 0;
                const y = parseFloat(a.GPSY) || 0;
                const z = parseFloat(a.GPSZ) || 0;
                const hasGps = (x !== 0 || y !== 0);
                const mapLink = hasGps
                    ? `<a href="https://www.google.com/maps?q=${y},${x}" target="_blank" rel="noopener">🗺️ فتح في الخريطة</a>`
                    : '<span class="muted">—</span>';
                ah += `<tr>
                    <td class="num muted">${escapeHtml(a.Number)}</td>
                    <td>${escapeHtml(a.Name || '-')}</td>
                    <td>${escapeHtml(a.District || '-')}</td>
                    <td class="num">${hasGps ? x.toFixed(6) : '-'}</td>
                    <td class="num">${hasGps ? y.toFixed(6) : '-'}</td>
                    <td class="num">${hasGps ? z.toFixed(2) : '-'}</td>
                    <td>${mapLink}</td>
                </tr>`;
            }
            ab.innerHTML = ah;
        }

        function updateAddrSortIndicators() {
            document.querySelectorAll('#detailView th.addr-sortable').forEach(th => {
                const ind = th.querySelector('.sort-ind');
                if (th.dataset.sort === addrSort.key) {
                    th.classList.add('sort-active');
                    if (ind) ind.textContent = addrSort.dir === 'asc' ? '▲' : '▼';
                } else {
                    th.classList.remove('sort-active');
                    if (ind) ind.textContent = '';
                }
            });
        }

        document.querySelectorAll('#detailView th.addr-sortable').forEach(th => {
            th.addEventListener('click', () => {
                const key = th.dataset.sort;
                if (addrSort.key === key) {
                    addrSort.dir = addrSort.dir === 'asc' ? 'desc' : 'asc';
                } else {
                    addrSort.key = key;
                    addrSort.dir = 'asc';
                }
                updateAddrSortIndicators();
                renderAddresses();
            });
        });

        // ---------- DETAIL VIEW ----------
        async function loadCustomer(guid) {
            try {
                const resp = await fetch('?customer=1&guid=' + encodeURIComponent(guid), { cache: 'no-store' });
                if (resp.status === 401) { window.location.href = 'login.php'; return; }
                const data = await resp.json();
                if (!data || data.error) {
                    document.getElementById('detailName').textContent = 'خطأ';
                    document.getElementById('detailSub').textContent = data.error || 'Customer not found';
                    return;
                }

                const c = data.customer;

                document.getElementById('detailName').textContent = c.CustomerName || '(بدون اسم)';
                document.getElementById('detailSub').textContent =
                    'رقم الزبون: ' + (c.Number || '-') + (c.LatinName ? ' · ' + c.LatinName : '');

                // Contact card
                const rows = [
                    ['📞 الهاتف 1', c.Phone1],
                    ['📞 الهاتف 2', c.Phone2],
                    ['📱 الموبايل', c.Mobile],
                    ['📠 الفاكس', c.FAX],
                    ['✉️ البريد الإلكتروني', c.EMail ? `<a href="mailto:${escapeHtml(c.EMail)}">${escapeHtml(c.EMail)}</a>` : ''],
                    ['🌐 الموقع', c.HomePage ? `<a href="${escapeHtml(c.HomePage)}" target="_blank" rel="noopener">${escapeHtml(c.HomePage)}</a>` : ''],
                    ['🔖 الباركود', c.BarCode],
                ];
                let html = '';
                for (const [lbl, val] of rows) {
                    if (!val) continue;
                    html += `<div class="info-row"><span class="lbl">${lbl}</span><span class="val">${val}</span></div>`;
                }
                if (!html) html = '<div class="empty" style="padding:12px 0;">لا توجد معلومات اتصال مسجلة.</div>';
                document.getElementById('contactBody').innerHTML = html;

                // Balance card
                const b = c.Balance;
                let bHtml = `
                    <div class="balance-row">
                        <span class="lbl">مدين (Debit)</span>
                        <span class="val">${fmtNum(c.Debit)}</span>
                    </div>
                    <div class="balance-row">
                        <span class="lbl">دائن (Credit)</span>
                        <span class="val">${fmtNum(c.Credit)}</span>
                    </div>
                    <div class="balance-row grand">
                        <span class="lbl">الرصيد</span>
                        <span class="val ${balanceClass(b)}">${fmtNum(b)}</span>
                    </div>`;
                /* if (data.summary.billCount > 0) {
                    bHtml += `
                    <div class="balance-row" style="padding-top:16px;">
                        <span class="lbl" style="font-size:0.8rem;">مجموع آخر ${data.summary.billCount} فاتورة</span>
                        <span class="val" style="font-size:0.95rem;">${fmtNum(data.summary.billTotal)} USD</span>
                    </div>`;
                } */
                        document.getElementById('balanceBody').innerHTML = bHtml;

                                // Addresses + GPS — store, then render with sorting
                currentAddresses = Array.isArray(data.addresses) ? data.addresses.slice() : [];
                addrSort = { key: 'Number', dir: 'asc' };   // reset to default on load
                updateAddrSortIndicators();
                renderAddresses();

                // Bills — store, then hand off to renderBills() for display
                currentBills = Array.isArray(data.bills) ? data.bills.slice() : [];
                sortState = { key: 'Date', dir: 'desc' };   // reset to default on each load
                updateSortIndicators();
                renderBills();

                document.getElementById('billsSummary').textContent =
                    data.summary.billCount > 0
                        ? `(${data.summary.billCount} فاتورة)`
                        : '';
            } catch (e) {
                console.error(e);
            }
        }

                // ---------- Bootstrap ----------
        if (isDetail) {
            document.getElementById('listView').style.display  = 'none';
            document.getElementById('detailView').style.display = 'block';
            loadCustomer(detailGuid);
        } else {
            updateListSortIndicators();
            loadCustomers(true);
        }
    </script>
</body>
</html>