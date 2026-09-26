<?php
// serial-movements.php — serial number / IMEI movement ledger.
// Wraps Alameen's SNMove procedure.
require_once 'config.php';
require_once 'auth.php';
requireLogin(true);

$NULL_GUID = '00000000-0000-0000-0000-000000000000';
$MAX_ROWS  = 2000;

// ─── API: bill type lookup ───────────────────────────────────────
if (isset($_GET['billtypes']) && $_GET['billtypes'] == 1) {
    header('Content-Type: application/json');
    try {
        $pdo = getDBConnection();
        $rows = $pdo->query("
            SELECT GUID, Name, Abbrev, BillType
            FROM bt000
            ORDER BY BillType, SortNum
        ")->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $r) {
            $map[$r['GUID']] = [
                'name'     => $r['Name'] ?: $r['Abbrev'],
                'billType' => (int)$r['BillType'],
            ];
        }
        echo json_encode(['billTypes' => $map], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        jsonFail($e, 500, 'Could not load bill types.');
    }
    exit;
}

// ─── API: dropdown references ────────────────────────────────────
if (isset($_GET['refs']) && $_GET['refs'] == 1) {
    header('Content-Type: application/json');
    try {
        $pdo = getDBConnection();

        // Materials that are serial-tracked (SNFlag = 1)
        $mats = $pdo->query("
            SELECT GUID, Name, Code
            FROM mt000
            WHERE SNFlag = 1
            ORDER BY Name
        ")->fetchAll(PDO::FETCH_ASSOC);

        $stores = $pdo->query("
            SELECT GUID, Number, Name
            FROM st000
            WHERE IsActive = 1 OR IsActive IS NULL
            ORDER BY CAST(Number AS INT)
        ")->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'materials' => $mats,
            'stores'    => $stores,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        jsonFail($e, 500, 'Could not load references.');
    }
    exit;
}

// ─── API: run the SN movement report ─────────────────────────────
if (isset($_GET['sn']) && $_GET['sn'] == 1) {
    header('Content-Type: application/json');

    $sn       = isset($_GET['snVal']) ? trim($_GET['snVal']) : '';
    $matGuid  = isset($_GET['mat'])   && $_GET['mat']   !== '' ? $_GET['mat']   : null;
    $storeGuid= isset($_GET['store']) && $_GET['store'] !== '' ? $_GET['store'] : null;
    $custGuid = isset($_GET['cust'])  && $_GET['cust']  !== '' ? $_GET['cust']  : null;

    $fromRaw = $_GET['from'] ?? null;
    $toRaw   = $_GET['to']   ?? null;
    try {
        $from = $fromRaw ? new DateTime($fromRaw) : (new DateTime('today'))->modify('-30 days');
        $to   = $toRaw   ? new DateTime($toRaw)   : new DateTime('today');
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid date']);
        exit;
    }

    // Clamp to today
    $today = new DateTime('today');
    if ($to > $today)  $to   = $today;
    if ($from > $to)   $from = clone $to;

    // Safety: if SN is empty (bulk report), restrict the range to 31 days
    if ($sn === '' && $from->diff($to)->days > 31) {
        http_response_code(400);
        echo json_encode(['error' => 'When the SN is empty, the date range must be 31 days or less.']);
        exit;
    }

    $fromSql = $from->format('Y-m-d') . ' 00:00:00';
    $toSql   = $to->format('Y-m-d')   . ' 23:59:59.997';

    try {
        $pdo = getDBConnection();

        $stmt = $pdo->prepare("
            EXEC dbo.ameenbill_GetSnMovements
                @SN = :sn,
                @StartDate = :from,
                @EndDate = :to,
                @MatGUID = :mat,
                @StoreGUID = :store,
                @CustGUID = :cust,
                @PostedValue = 1,
                @Lang = 0
        ");
        $stmt->execute([
            ':sn'    => $sn,
            ':from'  => $fromSql,
            ':to'    => $toSql,
            ':mat'   => $matGuid   ?: $NULL_GUID,
            ':store' => $storeGuid ?: $NULL_GUID,
            ':cust'  => $custGuid  ?: $NULL_GUID,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $truncated = false;
        if (count($rows) > $MAX_ROWS) {
            $rows = array_slice($rows, 0, $MAX_ROWS);
            $truncated = true;
        }

        $movements = [];
        $distinctSn = [];
        $distinctMat = [];
        foreach ($rows as $r) {
            $distinctSn[$r['SN']] = true;
            $distinctMat[$r['MatPtr']] = true;

            $movements[] = [
                'SN'         => $r['SN'],
                'BillGuid'   => $r['buNumber'],
                'BillTypeGuid' => $r['buType'],
                'BillTypeName' => $r['buName'],
                'BillNum'    => $r['buNum'],
                'SortFlag'   => (int)$r['BuSortFlag'],
                'LineGuid'   => $r['biGuid'],
                'IsPosted'   => (int)$r['buIsPosted'],
                'Notes'      => $r['biNotes'],
                'Price'      => (float)$r['biPrice'],
                'Discount'   => (float)$r['buDisc'],
                'Extra'      => (float)$r['buExtra'],
                'Qty'        => (float)$r['biQty'],
                'CurrencyVal'=> (float)$r['biCurrencyVal'],
                'Date'       => isoStamp($r['buDate'] ?? null),
                'HeaderNotes'=> $r['buNotes'],
                'CustName'   => $r['buCust_Name'],
                'MatPtr'     => $r['MatPtr'],
                'UnitName'   => $r['mtDefUnitName'],
                'GroupName'  => $r['grName'],
                'StoreName'  => $r['stName'],
            ];
        }

        echo json_encode([
            'movements' => $movements,
            'totals' => [
                'count'    => count($movements),
                'snCount'  => count($distinctSn),
                'matCount' => count($distinctMat),
            ],
            'range' => [
                'from' => $from->format('Y-m-d'),
                'to'   => $to->format('Y-m-d'),
            ],
            'truncated' => $truncated,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        jsonFail($e, 500, 'Could not load SN movements.');
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>حركة الأرقام التسلسلية - QuantuSphere Web</title>
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

        .btn {
            display: inline-flex; align-items: center; gap: 6px;
            background: var(--surface); border: 1px solid var(--border);
            padding: 7px 12px; border-radius: 999px;
            font-size: 0.78rem; font-weight: 700; color: var(--text-muted);
            font-family: inherit; cursor: pointer; text-decoration: none;
            transition: all 0.15s;
        }
        .btn:hover { border-color: var(--primary); color: var(--primary); }

        /* ── SN search bar (big, prominent) ───────────────────── */
        .sn-search {
            background: var(--surface); border: 2px solid var(--primary);
            border-radius: var(--radius); padding: 14px 18px;
            margin-bottom: 16px;
            display: flex; align-items: center; gap: 12px;
            box-shadow: 0 2px 12px rgba(184,134,60,0.15);
        }
        .sn-search label {
            font-size: 0.85rem; font-weight: 800; color: var(--primary-dark);
            white-space: nowrap;
        }
        .sn-search input {
            flex: 1; padding: 10px 16px;
            border: 1px solid var(--border); border-radius: 9px;
            background: var(--bg); color: var(--text);
            font-family: 'Courier New', monospace;
            font-size: 1rem; font-weight: 700;
            letter-spacing: 1px;
        }
        .sn-search input:focus {
            outline: none; border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-light);
        }
        .sn-search .clear-sn {
            background: none; border: none; color: var(--text-muted);
            font-size: 1.1rem; cursor: pointer; padding: 4px 10px;
            border-radius: 999px;
        }
        .sn-search .clear-sn:hover { color: var(--danger); background: rgba(162,59,46,0.08); }

        /* ── Filters row ─────────────────────────────────────── */
        .chip-bar {
            display: flex; align-items: center; flex-wrap: wrap; gap: 8px;
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 10px 14px; margin-bottom: 16px;
        }
        .chip-bar label { font-size: 0.72rem; color: var(--text-muted); font-weight: 700; }
        .chip-bar select {
            padding: 6px 10px; border: 1px solid var(--border);
            border-radius: 9px; font-size: 0.8rem; font-family: inherit;
            background: var(--bg); color: var(--text);
            max-width: 220px;
        }
        .chip-bar select:focus { outline: none; border-color: var(--primary); }
        .chip-bar input[type="date"] {
            padding: 6px 10px; border: 1px solid var(--border);
            border-radius: 9px; font-size: 0.8rem; font-family: inherit;
            background: var(--bg); color: var(--text);
        }
        .chip-bar input[type="date"]:focus { outline: none; border-color: var(--primary); }
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

        /* ── KPI strip ───────────────────────────────────────── */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px; margin-bottom: 18px;
        }
        .kpi-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 12px 14px; text-align: right;
        }
        .kpi-label { font-size: 0.72rem; color: var(--text-muted); font-weight: 700; margin-bottom: 6px; }
        .kpi-value {
            font-family: var(--font-num); font-variant-numeric: tabular-nums;
            font-size: 1.2rem; font-weight: 700; color: var(--text); line-height: 1.2;
        }
        .kpi-primary { border-color: var(--primary); }
        .kpi-primary .kpi-value { color: var(--primary-dark); font-size: 1.4rem; }

        /* ── Results section ─────────────────────────────────── */
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

        table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
        th {
            background: var(--bg); color: var(--text); font-weight: 700;
            padding: 10px 10px; border-bottom: 1px solid var(--border);
            text-align: right; white-space: nowrap;
        }
        td {
            padding: 9px 10px; border-bottom: 1px solid var(--border);
            text-align: right; color: var(--text); vertical-align: middle;
        }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover td { background: var(--primary-light); }
        .num { font-family: var(--font-num); font-variant-numeric: tabular-nums; }
        .muted { color: var(--text-muted); }
        .sn-cell {
            font-family: 'Courier New', monospace; font-weight: 700;
            letter-spacing: 0.5px; cursor: pointer; color: var(--primary-dark);
        }
        .sn-cell:hover { text-decoration: underline; }

        .badge-in  {
            display: inline-block; font-size: 0.72rem; font-weight: 800;
            color: white; background: var(--accent); padding: 2px 10px;
            border-radius: 999px;
        }
        .badge-out {
            display: inline-block; font-size: 0.72rem; font-weight: 800;
            color: white; background: var(--danger); padding: 2px 10px;
            border-radius: 999px;
        }
        .badge-unknown {
            display: inline-block; font-size: 0.72rem; font-weight: 800;
            color: var(--text-muted); background: var(--bg); padding: 2px 10px;
            border-radius: 999px;
        }

        .bill-link {
            color: var(--primary-dark); text-decoration: none; font-weight: 700;
        }
        .bill-link:hover { text-decoration: underline; }

        .type-pill {
            display: inline-block; font-size: 0.68rem; font-weight: 700;
            color: var(--text-muted); background: var(--bg);
            padding: 2px 8px; border-radius: 4px; white-space: nowrap;
        }

        .loading, .empty {
            padding: 30px; text-align: center;
            color: var(--text-muted); font-size: 0.85rem;
        }
        .warn-banner {
            background: #fffbeb; border: 1px solid #fde68a;
            color: #92400e; padding: 10px 16px; border-radius: var(--radius);
            font-size: 0.82rem; margin-bottom: 16px;
        }

        @media (max-width: 700px) {
            body { padding: 10px; }
            h1 { font-size: 1.1rem; }
            .sn-search { flex-direction: column; align-items: stretch; gap: 8px; }
            .sn-search input { font-size: 0.95rem; }
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
            .kpi-value { font-size: 1rem; }
            th, td { padding: 7px 6px; font-size: 0.72rem; }
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
                                <span class="menu-item-icon"><i class="ti ti-logout"></i></span><span>تسجيل الخروج</span>
                            </a>
                        </div>
                    </div>
                </div>
                <h1><span class="tile-icon"><i class="ti ti-barcode"></i></span> حركة الأرقام التسلسلية</h1>
            </div>
        </div>

        <!-- SN search box -->
        <div class="sn-search">
            <label>🔎 الرقم التسلسلي</label>
            <input type="text" id="snInput"
                   placeholder="امسح الباركود أو اكتب الرقم التسلسلي ثم اضغط Enter..."
                   autocomplete="off" spellcheck="false"
                   autofocus>
            <button type="button" class="clear-sn" id="clearSn" style="display:none;" title="مسح">✕</button>
        </div>

        <!-- Filters -->
        <div class="chip-bar">
            <button type="button" class="chip" data-preset="week">آخر أسبوع</button>
            <button type="button" class="chip" data-preset="month">آخر شهر</button>
            <button type="button" class="chip" data-preset="quarter">آخر 3 أشهر</button>
            <button type="button" class="chip" data-preset="year">هذه السنة</button>
            <button type="button" class="chip" data-preset="all">الكل</button>
            <span class="spacer"></span>
            <label>من</label>
            <input type="date" id="dateFrom">
            <label>إلى</label>
            <input type="date" id="dateTo">
        </div>

        <div class="chip-bar">
            <label>الصنف</label>
            <select id="matSelect"><option value="">الكل</option></select>
            <label>المستودع</label>
            <select id="storeSelect"><option value="">الكل</option></select>
            <span class="spacer"></span>
            <button type="button" class="btn" id="applyBtn" style="background:var(--primary); color:white; border-color:var(--primary);">تطبيق</button>
        </div>

        <!-- KPIs -->
        <div class="kpi-grid">
            <div class="kpi-card kpi-primary">
                <div class="kpi-label">📄 عدد الحركات</div>
                <div class="kpi-value" id="kpiCount">—</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">🔢 عدد الأرقام التسلسلية</div>
                <div class="kpi-value" id="kpiSnCount">—</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">📦 عدد الأصناف</div>
                <div class="kpi-value" id="kpiMatCount">—</div>
            </div>
        </div>

        <div id="truncatedWarning" class="warn-banner" style="display:none;">
            ⚠ النتائج مقيدة بـ 2000 حركة. اختر فترة زمنية أقصر أو استخدم رقم تسلسلي محدد.
        </div>

        <!-- Results -->
        <div class="section">
            <h2 class="section-title">
                <span>📋 سجل الحركات</span>
                <span class="muted" id="listCount"></span>
            </h2>
            <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الرقم التسلسلي</th>
                            <th>التاريخ</th>
                            <th>الفاتورة</th>
                            <th>النوع</th>
                            <th>الاتجاه</th>
                            <th>السعر</th>
                            <th>المستودع</th>
                            <th>الزبون</th>
                        </tr>
                    </thead>
                    <tbody id="listBody">
                        <tr><td colspan="9" class="loading">اكتب رقمًا تسلسليًا أو اختر فلترًا للبدء</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // ---------- Helpers ----------
        const _numFmt = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        function fmtNum(v) { const n = parseFloat(v); return isFinite(n) ? _numFmt.format(n) : '0.00'; }
        function escapeHtml(str) {
            const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
            return String(str === null || str === undefined ? '' : str).replace(/[&<>"']/g, m => map[m]);
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

        // ---------- State ----------
        let currentRange = { from: '', to: '' };
        let billTypes    = {};
        let matNames     = {};

        function fmtDateInput(d) {
            return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
        }
        function dateMinusDays(date, n) {
            const d = new Date(date); d.setDate(d.getDate() - n); return d;
        }
        function setActiveChip(preset) {
            document.querySelectorAll('.chip[data-preset]').forEach(c => {
                c.classList.toggle('active', c.dataset.preset === preset);
            });
        }
        function applyPreset(preset) {
            const today = new Date();

            if (preset === 'all') {
                // Wide enough to catch any history. Server caps empty-SN
                // queries at 31 days, so this only works when an SN is set.
                currentRange = { from: '2000-01-01', to: fmtDateInput(today) };
            } else {
                let from = new Date(today);
                if      (preset === 'week')    from = dateMinusDays(today, 7);
                else if (preset === 'month')   from = dateMinusDays(today, 30);
                else if (preset === 'quarter') from = dateMinusDays(today, 90);
                else if (preset === 'year')    from = new Date(today.getFullYear(), 0, 1);
                currentRange = { from: fmtDateInput(from), to: fmtDateInput(today) };
            }

            document.getElementById('dateFrom').value = currentRange.from;
            document.getElementById('dateTo').value   = currentRange.to;
            setActiveChip(preset);
            runReport();
        }
        document.querySelectorAll('.chip[data-preset]').forEach(btn => {
            btn.addEventListener('click', () => applyPreset(btn.dataset.preset));
        });
        document.getElementById('applyBtn').addEventListener('click', () => {
            const from = document.getElementById('dateFrom').value;
            const to   = document.getElementById('dateTo').value;
            if (!from || !to) return;
            if (from > to) { alert('تاريخ البداية يجب أن يكون قبل تاريخ النهاية.'); return; }
            currentRange = { from, to };
            setActiveChip(null);
            runReport();
        });

        // ---------- Reference data ----------
        async function loadRefs() {
            try {
                const resp = await fetch('?refs=1', { cache: 'no-store' });
                const data = await resp.json();
                if (!data || data.error) return;

                const matSel = document.getElementById('matSelect');
                for (const m of data.materials) {
                    matNames[m.GUID] = m.Name;
                    const opt = document.createElement('option');
                    opt.value = m.GUID;
                    opt.textContent = m.Name || '';
                    matSel.appendChild(opt);
                }

                const storeSel = document.getElementById('storeSelect');
                for (const s of data.stores) {
                    const opt = document.createElement('option');
                    opt.value = s.GUID;
                    opt.textContent = (s.Number ? s.Number + ' - ' : '') + (s.Name || '');
                    storeSel.appendChild(opt);
                }
            } catch (e) { /* silent */ }
        }

        async function loadBillTypes() {
            try {
                const resp = await fetch('?billtypes=1', { cache: 'force-cache' });
                const data = await resp.json();
                if (data && data.billTypes) billTypes = data.billTypes;
            } catch (e) { /* silent */ }
        }

                // Normalize Arabic text: unify alef variants, strip tashkeel.
        // So "إخ.م" and "اخ.م" compare identically.
        function normalizeAr(s) {
            return String(s || '')
                .replace(/[\u0623\u0625\u0622\u0671]/g, '\u0627')  // أ إ آ ٱ → ا
                .replace(/[\u064B-\u065F\u0670]/g, '')              // strip tashkeel
                .trim();
        }

        // Direction of a movement, derived from the Arabic bill type name.
        // Names come back from SNMove directly, so this is more reliable
        // than the numeric BillType, which varies between installs.
        function directionFor(btGuid, typeName) {
            const n = normalizeAr(typeName);

            if (n) {
                // Returns — must come BEFORE "مبيع" / "مشتريات" (they contain those words)
                if (n.includes('مرتجع مبيعات')) return 'in';
                if (n.includes('مرتجع مشتريات')) return 'out';

                // Transfers — Alameen uses "إخ…" for exit and "إد…" for entry
                if (n.startsWith('اخ') || n.includes('اخراج')) return 'out';
                if (n.startsWith('اد') || n.includes('ادخال')) return 'in';

                // Sales
                if (n.includes('مبيع')) return 'out';

                // Purchases
                if (n.includes('شراء') || n.includes('مشتريات')) return 'in';

                // Opening / closing inventory
                if (n.includes('مدة') || n.includes('رصيد اول') || n.includes('رصيد اخر')) return 'in';
            }

            // Fallback: numeric BillType
            const bt = billTypes[btGuid];
            if (!bt) return null;
            const t = bt.billType;
            if (t === 0 || t === 3 || t === 4) return 'in';
            if (t === 1 || t === 2 || t === 5) return 'out';
            return null;
        }

        // ---------- Main report ----------
        async function runReport() {
            const sn = document.getElementById('snInput').value.trim();
            const mat = document.getElementById('matSelect').value;
            const store = document.getElementById('storeSelect').value;

            // If SN is empty, ensure a date range is set (client safety)
            if (!currentRange.from || !currentRange.to) {
                applyPreset('month');
                return;
            }

            const body = document.getElementById('listBody');
            body.innerHTML = '<tr><td colspan="9" class="loading">جاري التحميل...</td></tr>';
            document.getElementById('truncatedWarning').style.display = 'none';

            const qs = new URLSearchParams({
                sn: '1',
                snVal: sn,
                from: currentRange.from,
                to: currentRange.to,
            });
            if (mat)   qs.set('mat',   mat);
            if (store) qs.set('store', store);

            try {
                const resp = await fetch('?' + qs.toString(), { cache: 'no-store' });
                if (resp.status === 401) { window.location.href = 'login.php'; return; }
                const data = await resp.json();
                if (!data || data.error) {
                    body.innerHTML = `<tr><td colspan="9" class="loading">خطأ: ${escapeHtml((data && data.error) || '')}</td></tr>`;
                    return;
                }

                document.getElementById('kpiCount').textContent    = data.totals.count;
                document.getElementById('kpiSnCount').textContent  = data.totals.snCount;
                document.getElementById('kpiMatCount').textContent = data.totals.matCount;
                document.getElementById('listCount').textContent   = `(${data.totals.count} حركة)`;

                if (data.truncated) {
                    document.getElementById('truncatedWarning').style.display = '';
                }

                const rows = data.movements;
                if (rows.length === 0) {
                    body.innerHTML = '<tr><td colspan="9" class="empty">لا توجد حركات في هذه الفترة.</td></tr>';
                    return;
                }

                let html = '';
                let idx = 0;
                for (const r of rows) {
                    idx++;
                    const dateStr = r.Date ? new Date(r.Date).toLocaleDateString('en-GB') : '-';
                    const dir = directionFor(r.BillTypeGuid, r.BillTypeName);
                    const dirBadge = dir === 'in'
                        ? '<span class="badge-in">⬇️ إدخال</span>'
                        : dir === 'out'
                            ? '<span class="badge-out">⬆️ إخراج</span>'
                            : '<span class="badge-unknown">—</span>';
                    const billLink = r.BillGuid
                        ? `<a class="bill-link" href="dashboard.php?bill=${encodeURIComponent(r.BillGuid)}" target="_blank">${escapeHtml(r.BillTypeName || '')} #${escapeHtml(String(r.BillNum || ''))}</a>`
                        : '—';

                    html += `<tr>
                        <td class="num muted">${idx}</td>
                        <td><span class="sn-cell" onclick="filterBySn('${escapeHtml(r.SN)}')">${escapeHtml(r.SN)}</span></td>
                        <td class="num">${dateStr}</td>
                        <td>${billLink}</td>
                        <td><span class="type-pill">${escapeHtml(r.BillTypeName || '')}</span></td>
                        <td>${dirBadge}</td>
                        <td class="num">${fmtNum(r.Price)}</td>
                        <td>${escapeHtml(r.StoreName || '-')}</td>
                        <td>${escapeHtml(r.CustName || '—')}</td>
                    </tr>`;
                }
                body.innerHTML = html;
            } catch (e) {
                console.error(e);
                body.innerHTML = `<tr><td colspan="9" class="loading">خطأ في الشبكة: ${escapeHtml(e.message)}</td></tr>`;
            }
        }

        function filterBySn(sn) {
            document.getElementById('snInput').value = sn;
            document.getElementById('clearSn').style.display = 'block';
            runReport();
        }

        // ---------- SN search wiring ----------
        const snInput = document.getElementById('snInput');
        const clearBtn = document.getElementById('clearSn');
        let snTimer = null;
        let snHadValue = false;   // tracks the state change, so we only auto-switch once

        snInput.addEventListener('input', () => {
            clearTimeout(snTimer);
            const hasText = snInput.value.length > 0;
            clearBtn.style.display = hasText ? 'block' : 'none';

            // First keystroke in the SN box: the user wants the full history
            // of one IMEI, not the last 30 days. Auto-widen the range.
            if (hasText && !snHadValue) {
                snHadValue = true;
                applyPreset('all');   // triggers runReport internally
                return;
            }
            // SN was cleared: if the range is still "all", tighten it back
            // so the empty-SN path isn't rejected by the server's 31-day cap.
            if (!hasText && snHadValue) {
                snHadValue = false;
                if (currentRange.from && currentRange.from <= '2001-01-01') {
                    applyPreset('month');
                    return;
                }
            }

            snTimer = setTimeout(() => runReport(), 400);
        });
        snInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                clearTimeout(snTimer);
                runReport();
                snInput.blur();   // dismiss mobile keyboard
            } else if (e.key === 'Escape') {
                e.preventDefault();
                snInput.value = '';
                clearBtn.style.display = 'none';
                runReport();
            }
        });
        clearBtn.addEventListener('click', () => {
            snInput.value = '';
            clearBtn.style.display = 'none';
            runReport();
            snInput.focus();
        });

        document.getElementById('matSelect').addEventListener('change', runReport);
        document.getElementById('storeSelect').addEventListener('change', runReport);

        // ---------- Bootstrap ----------
        (async () => {
            await Promise.all([loadRefs(), loadBillTypes()]);
            applyPreset('month');
        })();
    </script>
</body>
</html>