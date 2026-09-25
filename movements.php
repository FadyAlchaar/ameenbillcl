<?php
// movements.php - item movement ledger for one material.
// Wraps Alameen's repMatMoveMultiProduct via dbo.ameenbill_GetMatMovements.
require_once 'config.php';
require_once 'auth.php';
requireLogin(true);

$NULL_GUID = '00000000-0000-0000-0000-000000000000';
$MAX_ROWS  = 5000;

// ─── API: material info + movements ──────────────────────────────
if (isset($_GET['ledger']) && $_GET['ledger'] == 1) {
    header('Content-Type: application/json');

    $guid = $_GET['guid'] ?? '';
    if (!preg_match('/^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/', $guid)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid material GUID']);
        exit;
    }

    // Date range. Defaults to the last 30 days.
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
    if ($from > $to) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid date range']);
        exit;
    }

        // Clamp the end date to today. Alameen's report emits its closing-
    // inventory snapshot at whatever end date it's given, so a future
    // range produces phantom rows. Clamping keeps the display honest.
    $today = new DateTime('today');
    if ($to > $today) $to = $today;
    if ($from > $to)  $from = clone $to;

    // End of day for the `to` boundary
    $fromSql = $from->format('Y-m-d') . ' 00:00:00';
    $toSql   = $to->format('Y-m-d')   . ' 23:59:59.997';

    try {
        $pdo = getDBConnection();

        // ── Material header ─────────────────────────────────
        $mStmt = $pdo->prepare("
            SELECT m.GUID, m.Name, m.Code, m.BarCode, m.Unity,
                   m.Unit2, m.Unit2Fact, m.Unit3, m.Unit3Fact,
                   m.Low, m.LastPrice,
                   g.Name AS GroupName,
                   ISNULL((SELECT SUM(Qty) FROM ms000 WHERE MatGUID = m.GUID), 0) AS Stock
            FROM mt000 m
            LEFT JOIN gr000 g ON m.GroupGUID = g.GUID
            WHERE m.GUID = :guid
        ");
        $mStmt->execute([':guid' => $guid]);
        $material = $mStmt->fetch(PDO::FETCH_ASSOC);
        if (!$material) {
            http_response_code(404);
            echo json_encode(['error' => 'Material not found']);
            exit;
        }

        // ── Call the wrapper ────────────────────────────────
        $stmt = $pdo->prepare("
            EXEC dbo.ameenbill_GetMatMovements
                @MatGUID = :mat,
                @StartDate = :from,
                @EndDate = :to,
                @PostedValue = 1,
                @UseUnit = 0,
                @Lang = 0
        ");
        $stmt->execute([
            ':mat'  => $guid,
            ':from' => $fromSql,
            ':to'   => $toSql,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $truncated = false;
        if (count($rows) > $MAX_ROWS) {
            $rows = array_slice($rows, 0, $MAX_ROWS);
            $truncated = true;
        }

        // ── Shape the response ──────────────────────────────
        $movements = [];
        $sumIn  = 0.0;
        $sumOut = 0.0;

        foreach ($rows as $r) {
            // Skip Alameen's closing-inventory placeholder rows.
            // They carry zero quantity and belong to no real bill.
            if ((float)$r['biBillQty'] == 0.0) continue;

            $qty       = (float)$r['biBillQty'];
            $isInput   = ((int)$r['btIsInput'] === 1);
            $unitIdx   = (int)$r['biUnity'];

            $unitName = $r['mtUnity'] ?: '';
            if ($unitIdx === 2) $unitName = $r['MtUnit2'] ?: $unitName;
            elseif ($unitIdx === 3) $unitName = $r['MtUnit3'] ?: $unitName;

            $price = (float)$r['biPrice'];
            $disc  = (float)$r['biDiscount'];
            $extra = (float)$r['biExtra'];
            $lineTotal = ($qty * $price) - $disc + $extra;

            if ($isInput) $sumIn += $qty;
            else          $sumOut += $qty;

            $movements[] = [
                'date'      => isoStamp($r['buDate'] ?? null),
                'billGuid'  => $r['buNumber'],
                'billRef'   => $r['buFormatedNumber'],
                'billType'  => $r['buType'],
                'isInput'   => $isInput,
                'qty'       => $qty,
                'unit'      => $unitName,
                'price'     => $price,
                'lineTotal' => $lineTotal,
                'warehouse' => $r['stName'],
                'customer'  => $r['CuName'] ?: null,
                'notes'     => $r['buNotes'] ?? null,
                'bonusQty'  => (float)$r['BonusQty'] ?? 0,
            ];
        }

        echo json_encode([
            'material' => [
                'GUID'      => $material['GUID'],
                'Name'      => $material['Name'],
                'Code'      => $material['Code'],
                'BarCode'   => $material['BarCode'],
                'Unit'      => $material['Unity'],
                'Unit2'     => $material['Unit2'],
                'Unit2Fact' => (float)$material['Unit2Fact'],
                'Group'     => $material['GroupName'],
                'Stock'     => (float)$material['Stock'],
                'Low'       => (float)$material['Low'],
            ],
            'movements' => $movements,
            'totals'    => [
                'count' => count($movements),
                'in'    => $sumIn,
                'out'   => $sumOut,
                'net'   => $sumIn - $sumOut,
            ],
            'range' => [
                'from' => $from->format('Y-m-d'),
                'to'   => $to->format('Y-m-d'),
            ],
            'truncated' => $truncated,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        jsonFail($e, 500, 'Could not load movements.');
    }
    exit;
}

// ─── API: bill type lookup (cached client-side) ─────────────────
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
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>حركة صنف - بياناتي ويب</title>
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

        .page { max-width: 1500px; margin: 0 auto; }

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

        /* Material header */
        .material-header {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 16px 20px;
            margin-bottom: 16px;
        }
        .material-header h2 { margin: 0 0 4px; font-size: 1.3rem; font-weight: 900; }
        .material-header .sub { color: var(--text-muted); font-size: 0.85rem; }

        /* KPI strip */
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
        .kpi-card.kpi-in    { border-color: var(--accent); }
        .kpi-card.kpi-in  .kpi-value { color: var(--accent); }
        .kpi-card.kpi-out   { border-color: var(--danger); }
        .kpi-card.kpi-out .kpi-value { color: var(--danger); }
        .kpi-card.kpi-stock { border-color: var(--primary); }
        .kpi-card.kpi-stock .kpi-value { color: var(--primary-dark); font-size: 1.4rem; }

        /* Date filter */
        .chip-bar {
            display: flex; align-items: center; flex-wrap: wrap; gap: 8px;
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 10px 14px; margin-bottom: 16px;
        }
        .chip-bar label { font-size: 0.75rem; color: var(--text-muted); font-weight: 700; }
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

        /* Section */
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
            text-align: right; color: var(--text); vertical-align: middle;
        }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover td { background: var(--primary-light); }
        .num { font-family: var(--font-num); font-variant-numeric: tabular-nums; }
        .muted { color: var(--text-muted); }

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

        .bill-link {
            color: var(--primary-dark); text-decoration: none;
            font-weight: 700;
        }
        .bill-link:hover { text-decoration: underline; }

        .type-pill {
            display: inline-block; font-size: 0.68rem; font-weight: 700;
            color: var(--text-muted); background: var(--bg);
            padding: 2px 8px; border-radius: 4px;
            white-space: nowrap;
        }

        .loading, .empty {
            padding: 30px; text-align: center;
            color: var(--text-muted); font-size: 0.85rem;
        }

        .truncated-warning {
            background: #fffbeb;
            border: 1px solid #fde68a;
            color: #92400e;
            padding: 10px 16px;
            border-radius: var(--radius);
            font-size: 0.82rem;
            margin-bottom: 16px;
        }

        @media (max-width: 600px) {
            body { padding: 10px; }
            h1 { font-size: 1.1rem; }
            th, td { padding: 8px 6px; font-size: 0.75rem; }
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
            .kpi-value { font-size: 1rem; }
            .kpi-card.kpi-stock .kpi-value { font-size: 1.15rem; }
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
                                <span class="menu-item-icon">🚪</span><span>تسجيل الخروج</span>
                            </a>
                        </div>
                    </div>
                </div>
                <h1>📄 حركة صنف</h1>
            </div>
        </div>

        <div class="material-header">
            <h2 id="matName">جاري التحميل...</h2>
            <div class="sub" id="matSub">—</div>
        </div>

        <div class="kpi-grid">
            <div class="kpi-card kpi-in">
                <div class="kpi-label">⬇️ إجمالي الإدخال (خلال الفترة)</div>
                <div class="kpi-value" id="kpiIn">—</div>
            </div>
            <div class="kpi-card kpi-out">
                <div class="kpi-label">⬆️ إجمالي الإخراج (خلال الفترة)</div>
                <div class="kpi-value" id="kpiOut">—</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">📊 صافي الحركة</div>
                <div class="kpi-value" id="kpiNet">—</div>
            </div>
            <div class="kpi-card kpi-stock">
                <div class="kpi-label">🏭 المخزون الحالي</div>
                <div class="kpi-value" id="kpiStock">—</div>
            </div>
        </div>

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
            <button type="button" class="btn-primary" id="applyBtn">تطبيق</button>
        </div>

        <div id="truncatedWarning" class="truncated-warning" style="display:none;">
            ⚠ النتائج مقيدة بـ 5000 حركة. لتقليل النتائج، اختر فترة زمنية أقصر.
        </div>

        <div class="section">
            <h2 class="section-title">
                <span>📄 سجل الحركات</span>
                <span class="muted" id="listCount"></span>
            </h2>
            <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>التاريخ</th>
                            <th>الفاتورة</th>
                            <th>النوع</th>
                            <th>الاتجاه</th>
                            <th>الكمية</th>
                            <th>الوحدة</th>
                            <th>السعر</th>
                            <th>الإجمالي</th>
                            <th>المستودع</th>
                            <th>الزبون</th>
                        </tr>
                    </thead>
                    <tbody id="listBody">
                        <tr><td colspan="11" class="loading">جاري التحميل...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // ---------- Helpers ----------
        const _numFmt = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const _qtyFmt = new Intl.NumberFormat('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 4 });
        function fmtNum(v) { const n = parseFloat(v); return isFinite(n) ? _numFmt.format(n) : '0.00'; }
        function fmtQty(v) { const n = parseFloat(v); return isFinite(n) ? _qtyFmt.format(n) : '0'; }
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

        // ---------- Routing ----------
        const params     = new URLSearchParams(window.location.search);
        const materialGuid = params.get('mat');

        if (!materialGuid) {
            document.getElementById('matName').textContent = 'خطأ';
            document.getElementById('matSub').textContent  = 'لم يتم تحديد صنف. استخدم الرابط من صفحة المخزون.';
        }

        // ---------- State ----------
        const FILTER_KEY = 'movementsDateRange';
        let currentRange = { from: '', to: '' };
        let billTypes    = {};

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
                currentRange = { from: '2000-01-01', to: fmtDateInput(today) };
                document.getElementById('dateFrom').value = currentRange.from;
                document.getElementById('dateTo').value   = currentRange.to;
                setActiveChip('all');
            } else {
                let from = new Date(today);
                if      (preset === 'week')    from = dateMinusDays(today, 7);
                else if (preset === 'month')   from = dateMinusDays(today, 30);
                else if (preset === 'quarter') from = dateMinusDays(today, 90);
                else if (preset === 'year')    from = new Date(today.getFullYear(), 0, 1);
                currentRange = { from: fmtDateInput(from), to: fmtDateInput(today) };
                document.getElementById('dateFrom').value = currentRange.from;
                document.getElementById('dateTo').value   = currentRange.to;
                setActiveChip(preset);
            }
            saveFilter();
            loadLedger();
        }

        function saveFilter() {
            try { localStorage.setItem(FILTER_KEY, JSON.stringify(currentRange)); } catch (e) {}
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
            saveFilter();
            loadLedger();
        });

        // ---------- Load bill types once ----------
        async function loadBillTypes() {
            try {
                const resp = await fetch('?billtypes=1', { cache: 'force-cache' });
                const data = await resp.json();
                if (data && data.billTypes) billTypes = data.billTypes;
            } catch (e) { /* silent */ }
        }

        // ---------- Load ledger ----------
        async function loadLedger() {
            if (!materialGuid) return;

            const body = document.getElementById('listBody');
            body.innerHTML = '<tr><td colspan="11" class="loading">جاري التحميل...</td></tr>';
            document.getElementById('truncatedWarning').style.display = 'none';

            const qs = new URLSearchParams({
                ledger: '1',
                guid:   materialGuid,
                from:   currentRange.from,
                to:     currentRange.to,
            });

            try {
                const resp = await fetch('?' + qs.toString(), { cache: 'no-store' });
                if (resp.status === 401) { window.location.href = 'login.php'; return; }
                const data = await resp.json();
                if (!data || data.error) {
                    body.innerHTML = `<tr><td colspan="11" class="loading">خطأ: ${escapeHtml((data && data.error) || '')}</td></tr>`;
                    return;
                }

                // Material header
                const m = data.material;
                document.getElementById('matName').textContent = m.Name || '(بدون اسم)';
                document.getElementById('matSub').textContent  =
                    (m.Code ? 'الكود: ' + m.Code : '') +
                    (m.BarCode ? ' · الباركود: ' + m.BarCode : '') +
                    (m.Group ? ' · المجموعة: ' + m.Group : '');

                // KPIs
                document.getElementById('kpiIn').textContent    = fmtQty(data.totals.in)  + ' ' + (m.Unit || '');
                document.getElementById('kpiOut').textContent   = fmtQty(data.totals.out) + ' ' + (m.Unit || '');
                document.getElementById('kpiNet').textContent   = fmtQty(data.totals.net) + ' ' + (m.Unit || '');
                document.getElementById('kpiStock').textContent = fmtQty(m.Stock) + ' ' + (m.Unit || '');

                // Truncation warning
                if (data.truncated) {
                    document.getElementById('truncatedWarning').style.display = '';
                }

                // Rows (report returns oldest first; show newest first)
                const rows = data.movements.slice().reverse();
                document.getElementById('listCount').textContent = `(${rows.length} حركة)`;

                if (rows.length === 0) {
                    body.innerHTML = '<tr><td colspan="11" class="empty">لا توجد حركات في هذه الفترة.</td></tr>';
                    return;
                }

                let html = '';
                let idx = 0;
                for (const r of rows) {
                    idx++;
                    const dateStr = r.date ? new Date(r.date).toLocaleDateString('en-GB') : '-';
                    const typeName = (billTypes[r.billType] && billTypes[r.billType].name) || '—';
                    const dirBadge = r.isInput
                        ? '<span class="badge-in">⬇️ إدخال</span>'
                        : '<span class="badge-out">⬆️ إخراج</span>';
                    const billLink = r.billGuid
                        ? `<a class="bill-link" href="index.php?bill=${encodeURIComponent(r.billGuid)}" target="_blank">${escapeHtml(r.billRef || '-')}</a>`
                        : escapeHtml(r.billRef || '-');

                    html += `<tr>
                        <td class="num muted">${idx}</td>
                        <td class="num">${dateStr}</td>
                        <td>${billLink}</td>
                        <td><span class="type-pill">${escapeHtml(typeName)}</span></td>
                        <td>${dirBadge}</td>
                        <td class="num">${fmtQty(r.qty)}</td>
                        <td>${escapeHtml(r.unit || '')}</td>
                        <td class="num">${fmtNum(r.price)}</td>
                        <td class="num">${fmtNum(r.lineTotal)}</td>
                        <td>${escapeHtml(r.warehouse || '-')}</td>
                        <td>${escapeHtml(r.customer || '—')}</td>
                    </tr>`;
                }
                body.innerHTML = html;
            } catch (e) {
                console.error(e);
                body.innerHTML = `<tr><td colspan="11" class="loading">خطأ في الشبكة: ${escapeHtml(e.message)}</td></tr>`;
            }
        }

        // ---------- Bootstrap ----------
        (async () => {
            if (!materialGuid) return;
            await loadBillTypes();

            const saved = loadSavedFilter();
            if (saved) {
                currentRange = saved;
                document.getElementById('dateFrom').value = saved.from;
                document.getElementById('dateTo').value   = saved.to;
                setActiveChip(null);
                loadLedger();
            } else {
                applyPreset('month');   // last 30 days
            }
        })();
    </script>
</body>
</html>