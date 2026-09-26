<?php
// stats.php - statistics dashboard.
require_once 'config.php';
require_once 'auth.php';

$isApiRequest = isset($_GET['kpi']);
requireLogin($isApiRequest);

// ─── API: KPI data for a date range ──────────────────────────────
if (isset($_GET['kpi']) && $_GET['kpi'] == 1) {
    header('Content-Type: application/json');

    $fromRaw = isset($_GET['from']) ? $_GET['from'] : null;
    $toRaw   = isset($_GET['to'])   ? $_GET['to']   : null;

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

    try {
        $pdo = getDBConnection();

        // ── Overall totals + pay-type split ──────────────────────
        $sql = "SELECT
                    COUNT(*) AS cnt,
                    ISNULL(SUM(b.Total - b.TotalDisc + b.TotalExtra), 0) AS net_total,
                    SUM(CASE WHEN b.PayType = 0 THEN 1 ELSE 0 END) AS cash_count,
                    SUM(CASE WHEN b.PayType = 1 THEN 1 ELSE 0 END) AS credit_count,
                    ISNULL(SUM(CASE WHEN b.PayType = 0 THEN b.Total - b.TotalDisc + b.TotalExtra ELSE 0 END), 0) AS cash_total,
                    ISNULL(SUM(CASE WHEN b.PayType = 1 THEN b.Total - b.TotalDisc + b.TotalExtra ELSE 0 END), 0) AS credit_total
                FROM bu000 b
                WHERE b.Date >= :fromStart AND b.Date < :toEnd";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':fromStart' => $fromStart, ':toEnd' => $toEnd]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $count = (int)($row['cnt'] ?? 0);
        $total = (float)($row['net_total'] ?? 0);
        $avg   = $count > 0 ? $total / $count : 0.0;

        // ── Per-currency breakdown ───────────────────────────────
        // bu000.Total is stored in the base currency (USD). net_original is
        // what the customer actually paid in their own currency.
        $sqlCur = "SELECT
                        cur.Name AS CurrencyName,
                        COUNT(*) AS cnt,
                        ISNULL(SUM(b.Total - b.TotalDisc + b.TotalExtra), 0) AS net_usd,
                        ISNULL(SUM((b.Total - b.TotalDisc + b.TotalExtra) / NULLIF(b.CurrencyVal, 0)), 0) AS net_original
                    FROM bu000 b
                    LEFT JOIN my000 cur ON b.CurrencyGUID = cur.GUID
                    WHERE b.Date >= :fromStart AND b.Date < :toEnd
                    GROUP BY b.CurrencyGUID, cur.Name
                    ORDER BY net_usd DESC";
        $stmtCur = $pdo->prepare($sqlCur);
        $stmtCur->execute([':fromStart' => $fromStart, ':toEnd' => $toEnd]);
        $currencies = $stmtCur->fetchAll(PDO::FETCH_ASSOC);

                // ── Hourly breakdown for the last day in the range ──────────
        // The chart only makes sense for a single day, so we show the
        // "to" date regardless of how wide the selected range is.
        $sparkDay  = $to->format('Y-m-d');
        $sparkFrom = $sparkDay . ' 00:00:00';
        $sparkTo   = (clone $to)->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

        $hourSql = "SELECT
                        DATEPART(hour, b.CreateDate) AS H,
                        COUNT(*) AS Cnt,
                        ISNULL(SUM(b.Total - b.TotalDisc + b.TotalExtra), 0) AS Net
                    FROM bu000 b
                    WHERE b.CreateDate >= :sparkFrom AND b.CreateDate < :sparkTo
                    GROUP BY DATEPART(hour, b.CreateDate)
                    ORDER BY H";
        $hStmt = $pdo->prepare($hourSql);
        $hStmt->execute([':sparkFrom' => $sparkFrom, ':sparkTo' => $sparkTo]);
        $hourRows = $hStmt->fetchAll(PDO::FETCH_ASSOC);

        // Build a full 24-slot array so the client gets every hour, even
        // the ones with zero activity.
        $hourly = [];
        $byHour = [];
        foreach ($hourRows as $hr) {
            $byHour[(int)$hr['H']] = [
                'count' => (int)$hr['Cnt'],
                'total' => (float)$hr['Net'],
            ];
        }
        for ($h = 0; $h < 24; $h++) {
            $hourly[] = [
                'h'     => $h,
                'count' => $byHour[$h]['count'] ?? 0,
                'total' => $byHour[$h]['total'] ?? 0,
            ];
        }

        echo json_encode([
            'from'       => $from->format('Y-m-d'),
            'to'         => $to->format('Y-m-d'),
            'count'      => $count,
            'total'      => $total,
            'avg'        => $avg,
            'cash'       => ['count' => (int)($row['cash_count'] ?? 0),   'total' => (float)($row['cash_total'] ?? 0)],
            'credit'     => ['count' => (int)($row['credit_count'] ?? 0), 'total' => (float)($row['credit_total'] ?? 0)],
            'currencies' => array_map(function ($c) {
                return [
                    'name'         => $c['CurrencyName'] ?: 'غير معروفة',
                    'count'        => (int)$c['cnt'],
                    'net_usd'      => (float)$c['net_usd'],
                    'net_original' => (float)$c['net_original'],
                ];
            }, $currencies),
            'hourly' => [
                'date'  => $sparkDay,
                'hours' => $hourly,
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        jsonFail($e, 500, 'Could not load KPI.');
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة الإحصائيات</title>
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
                --bg: #12181F;
                --surface: #1B232C;
                --text: #EDE6D6;
                --text-muted: #8C97A3;
                --border: #2B3542;
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
            --shadow-md: 0 2px 10px rgba(0,0,0,0.4);
            color-scheme: dark;
        }
        :root[data-theme="bright"] {
            --bg: #FFFFFF;
            --surface: #FFFFFF;
            --text: #1D2A35;
            --text-muted: #5B6672;
            --border: #E2E5E9;
            --shadow-sm: 0 1px 3px rgba(20,32,43,0.06);
            color-scheme: light;
        }
        :root[data-theme="classic"] {
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --primary-light: #eef2ff;
            --accent: #06b6d4;
            --danger: #ef4444;
            --bg: #f4f5fa;
            --surface: #ffffff;
            --text: #1e1b2e;
            --text-muted: #6b7280;
            --border: #e5e7eb;
            --radius: 14px;
            color-scheme: light;
        }

        body, .kpi-card, .btn, .chip, input, table, th, td {
            transition: background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease;
        }
        body {
            font-family: 'Tajawal', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--bg);
            color: var(--text);
            margin: 0;
            padding: 16px;
            min-height: 100vh;
        }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-thumb { background: #C9C0AC; border-radius: 8px; }
        ::-webkit-scrollbar-thumb:hover { background: #B8863C; }

        .page { max-width: 1400px; margin: 0 auto; }

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        h1 {
            font-size: 1.4rem;
            font-weight: 900;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
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

        .btn, .btn-primary, .chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--surface);
            border: 1px solid var(--border);
            padding: 7px 12px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--text-muted);
            font-family: inherit;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s;
        }
        .btn:hover { border-color: var(--primary); color: var(--primary); }
        .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }
        .btn-primary:hover { background: var(--primary-dark); }

        .date-filter {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 12px 16px;
            margin-bottom: 16px;
        }
        .date-filter label {
            font-size: 0.75rem;
            color: var(--text-muted);
            font-weight: 700;
        }
        .date-filter input[type="date"] {
            padding: 6px 10px;
            border: 1px solid var(--border);
            border-radius: 9px;
            font-size: 0.8rem;
            font-family: inherit;
            background: var(--bg);
            color: var(--text);
        }
        .date-filter input[type="date"]:focus {
            outline: none;
            border-color: var(--primary);
        }
        .chip.active {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }
        .spacer { flex: 1; }

        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }
        .kpi-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 14px 16px;
            text-align: right;
        }
        .kpi-label {
            font-size: 0.75rem;
            color: var(--text-muted);
            font-weight: 700;
            margin-bottom: 6px;
        }
        .kpi-value {
            font-family: var(--font-num);
            font-variant-numeric: tabular-nums;
            font-size: 1.35rem;
            font-weight: 700;
            color: var(--text);
            line-height: 1.2;
        }
        .kpi-sub {
            font-size: 0.7rem;
            color: var(--text-muted);
            margin-top: 4px;
            font-variant-numeric: tabular-nums;
        }
        .kpi-primary { border-color: var(--primary); }
        .kpi-primary .kpi-value { color: var(--primary-dark); font-size: 1.6rem; }
        .kpi-cash    .kpi-value { color: var(--accent); }
        .kpi-credit  .kpi-value { color: var(--primary-dark); }

        .section {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            margin-bottom: 16px;
            overflow: hidden;
        }

                /* Hourly sparkline */
        .sparkline-wrap {
            padding: 16px 18px 10px;
        }
        .sparkline-wrap svg {
            display: block;
            width: 100%;
            height: auto;
        }
        .spark-bar {
            fill: var(--primary);
            opacity: 0.75;
            transition: opacity 0.15s;
        }
        .spark-bar:hover { opacity: 1; }
        .spark-baseline {
            stroke: var(--border);
            stroke-width: 1;
        }
        .spark-label {
            fill: var(--text-muted);
            font-size: 10px;
            font-family: var(--font-num);
            font-variant-numeric: tabular-nums;
        }
        .spark-peak-bar {
            fill: var(--primary-dark);
            opacity: 1;
        }

        .section-title {
            margin: 0;
            padding: 12px 18px;
            font-size: 0.95rem;
            font-weight: 700;
            background: var(--header-bg);
            color: var(--header-fg);
            border-bottom: 2px solid var(--primary);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }
        th {
            background: var(--bg);
            color: var(--text);
            font-weight: 700;
            padding: 10px 12px;
            border-bottom: 1px solid var(--border);
            text-align: right;
            white-space: nowrap;
        }
        td {
            padding: 10px 12px;
            border-bottom: 1px solid var(--border);
            text-align: right;
            color: var(--text);
            font-variant-numeric: tabular-nums;
        }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover td { background: var(--primary-light); }
        .num { font-family: var(--font-num); }
        .loading, .empty {
            padding: 30px;
            text-align: center;
            color: var(--text-muted);
            font-size: 0.85rem;
        }

        @media (max-width: 600px) {
            body { padding: 10px; }
            h1 { font-size: 1.1rem; }
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
            .kpi-primary { grid-column: 1 / -1; }
            .kpi-value { font-size: 1.15rem; }
            .kpi-primary .kpi-value { font-size: 1.35rem; }
            th, td { padding: 8px 6px; font-size: 0.75rem; }
            .date-filter { padding: 10px; }
            .date-filter input[type="date"] { flex: 1; min-width: 0; }
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
                                <span class="menu-item-icon" id="themeToggleIcon"><i class="ti ti-moon"></i></span>
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
                <h1><span class="tile-icon"><i class="ti ti-chart-bar"></i></span> لوحة الإحصائيات</h1>
            </div>
        </div>

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
        </div>

        <div class="kpi-grid">
            <div class="kpi-card kpi-primary">
                <div class="kpi-label"><i class="ti ti-dollar-sign"></i> إجمالي المبيعات</div>
                <div class="kpi-value" id="kpiTotal">—</div>
                <div class="kpi-sub" id="kpiRange">—</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label"><i class="ti ti-file-text"></i> عدد الفواتير</div>
                <div class="kpi-value" id="kpiCount">—</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label"><i class="ti ti-chart-bar"></i> متوسط الفاتورة</div>
                <div class="kpi-value" id="kpiAvg">—</div>
            </div>
            <div class="kpi-card kpi-cash">
                <div class="kpi-label"><i class="ti ti-money"></i> نقدي</div>
                <div class="kpi-value" id="kpiCash">—</div>
                <div class="kpi-sub" id="kpiCashSub">—</div>
            </div>
            <div class="kpi-card kpi-credit">
                <div class="kpi-label"><i class="ti ti-credit-card"></i> آجل</div>
                <div class="kpi-value" id="kpiCredit">—</div>
                <div class="kpi-sub" id="kpiCreditSub">—</div>
            </div>
        </div>

        <div class="section">
            <h2 class="section-title">
                🕐 المبيعات حسب الساعة
                <span id="sparklineDate" class="muted" style="font-size:0.8rem;font-weight:600;margin-right:8px;"></span>
                <span id="sparklinePeak" class="muted" style="font-size:0.75rem;font-weight:600;margin-right:8px;"></span>
            </h2>
            <div class="sparkline-wrap" id="sparklineContainer">
                <div class="loading">جاري التحميل...</div>
            </div>
        </div>

        <div class="section">
            <h2 class="section-title">💱 توزيع العملات</h2>
            <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>العملة</th>
                            <th>عدد الفواتير</th>
                            <th>بالعملة الأصلية</th>
                            <th>ما يعادل USD</th>
                            <th>النسبة</th>
                        </tr>
                    </thead>
                    <tbody id="currencyBody">
                        <tr><td colspan="5" class="loading">جاري التحميل...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // ---------- Number formatting ----------
        const _numFmt = new Intl.NumberFormat('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
        function fmtNum(v) {
            const n = parseFloat(v);
            return isFinite(n) ? _numFmt.format(n) : '0.00';
        }
        function escapeHtml(str) {
            const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
            return String(str === null || str === undefined ? '' : str).replace(/[&<>"']/g, m => map[m]);
        }

        // ---------- Theme toggle (same cycle as index.php) ----------
        (function initThemeToggle() {
            const btn  = document.getElementById('themeToggleBtn');
            const icon = document.getElementById('themeToggleIcon');
            if (!btn || !icon) return;

            const THEMES = ['warm', 'bright', 'dark', 'classic'];
            const THEME_META = {
                warm:    { icon: '📜', label: 'دافئ' },
                bright:  { icon: '☀️', label: 'فاتح' },
                dark:    { icon: '🌙', label: 'داكن' },
                classic: { icon: '🔷', label: 'الأصلي' },
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
                btn.title = `الوضع الحالي: ${THEME_META[cur].label}`;
            }
            updateIcon();

            btn.addEventListener('click', () => {
                const cur  = effectiveTheme();
                const next = THEMES[(THEMES.indexOf(cur) + 1) % THEMES.length];
                document.documentElement.setAttribute('data-theme', next);
                try { localStorage.setItem('dashboardTheme', next); } catch (e) {}
                updateIcon();
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

        // ---------- Date helpers ----------
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

        // ---------- State ----------
        let rangeFrom = null;
        let rangeTo   = null;

        const $ = id => document.getElementById(id);

        function setActiveChip(preset) {
            document.querySelectorAll('.chip').forEach(c => {
                c.classList.toggle('active', c.dataset.preset === preset);
            });
        }

        function applyPreset(preset) {
            const today = new Date();
            let from = new Date(today);
            let to   = new Date(today);

            if (preset === 'yesterday') {
                from = dateMinusDays(today, 1);
                to   = new Date(from);
            } else if (preset === 'week') {
                const dow = (today.getDay() + 6) % 7;   // Monday-start
                from = dateMinusDays(today, dow);
                to   = new Date(today);
            } else if (preset === 'month') {
                from = new Date(today.getFullYear(), today.getMonth(), 1);
                to   = new Date(today);
            }
            // 'today' → defaults already set above

            rangeFrom = fmtDateInput(from);
            rangeTo   = fmtDateInput(to);
            $('dateFrom').value = rangeFrom;
            $('dateTo').value   = rangeTo;
            setActiveChip(preset);
            loadKPI();
        }

        document.querySelectorAll('.chip[data-preset]').forEach(btn => {
            btn.addEventListener('click', () => applyPreset(btn.dataset.preset));
        });

        $('applyBtn').addEventListener('click', () => {
            const from = $('dateFrom').value;
            const to   = $('dateTo').value;
            if (!from || !to) return;
            if (from > to) { alert('تاريخ البداية يجب أن يكون قبل تاريخ النهاية.'); return; }
            rangeFrom = from;
            rangeTo   = to;
            setActiveChip(null);
            loadKPI();
        });

        // ---------- KPI loading ----------
        async function loadKPI() {
            const params = new URLSearchParams({ kpi: '1' });
            if (rangeFrom) params.set('from', rangeFrom);
            if (rangeTo)   params.set('to',   rangeTo);

            try {
                const resp = await fetch('?' + params.toString(), { cache: 'no-store' });
                if (resp.status === 401) { window.location.href = 'login.php'; return; }
                const data = await resp.json();
                if (!data || data.error) return;

                $('kpiCount').textContent  = data.count;
                $('kpiTotal').textContent  = fmtNum(data.total) + ' USD';
                $('kpiAvg').textContent    = fmtNum(data.avg)   + ' USD';
                $('kpiCash').textContent   = fmtNum(data.cash.total) + ' USD';
                $('kpiCredit').textContent = fmtNum(data.credit.total) + ' USD';
                $('kpiCashSub').textContent   = data.cash.count + ' فاتورة';
                $('kpiCreditSub').textContent = data.credit.count + ' فاتورة';

                const range = data.from === data.to
                    ? data.from
                    : data.from + ' → ' + data.to;
                $('kpiRange').textContent = range;

                renderCurrencies(data.currencies, data.total);
                renderSparkline(data.hourly);
            } catch (e) {
                console.error('KPI load failed:', e);
            }
        }

        function renderSparkline(hourly) {
            const container = document.getElementById('sparklineContainer');
            const dateEl    = document.getElementById('sparklineDate');
            const peakEl    = document.getElementById('sparklinePeak');
            if (!container) return;

            if (!hourly || !Array.isArray(hourly.hours) || hourly.hours.length === 0) {
                container.innerHTML = '<div class="empty">لا توجد بيانات.</div>';
                if (dateEl) dateEl.textContent = '';
                if (peakEl) peakEl.textContent = '';
                return;
            }

            const slots = hourly.hours;
            const maxTotal = Math.max(1, ...slots.map(s => s.total));

            // The peak hour — highlighted with a darker bar and called out
            // in the section header. Ties go to the earliest hour.
            let peakHour = -1, peakVal = 0;
            for (const s of slots) {
                if (s.total > peakVal) { peakVal = s.total; peakHour = s.h; }
            }

            if (dateEl) dateEl.textContent = '— ' + (hourly.date || '');
            if (peakEl && peakHour >= 0 && peakVal > 0) {
                peakEl.textContent = `· ذروة المبيعات الساعة ${String(peakHour).padStart(2,'0')}:00 (${fmtNum(peakVal)} USD)`;
            } else if (peakEl) {
                peakEl.textContent = '';
            }

            // viewBox coordinate space — W stays constant, the SVG scales to
            // fit its container via CSS width:100%. Bars keep their relative
            // proportions on any screen size.
            const W = 960, H = 150;
            const padTop = 14, padBottom = 26, padLeft = 10, padRight = 10;
            const chartH = H - padTop - padBottom;
            const slotW  = (W - padLeft - padRight) / 24;
            const barW   = slotW - 3;

            let svg = `<svg viewBox="0 0 ${W} ${H}" preserveAspectRatio="none">`;

            // Baseline
            svg += `<line class="spark-baseline"
                        x1="${padLeft}" y1="${padTop + chartH}"
                        x2="${W - padRight}" y2="${padTop + chartH}" />`;

            // Bars
            for (const s of slots) {
                const x  = padLeft + s.h * slotW + 1.5;
                const bh = s.total > 0 ? Math.max(3, (s.total / maxTotal) * chartH) : 0;
                const y  = padTop + chartH - bh;
                const isPeak = (s.h === peakHour && s.total > 0);

                const tip = `الساعة ${String(s.h).padStart(2,'0')}:00` +
                            ` — ${fmtNum(s.total)} USD` +
                            (s.count ? ` (${s.count} فاتورة)` : ' (لا مبيعات)');

                if (bh > 0) {
                    svg += `<rect class="spark-bar${isPeak ? ' spark-peak-bar' : ''}"
                                x="${x}" y="${y}"
                                width="${barW}" height="${bh}"
                                rx="2">
                                <title>${escapeHtml(tip)}</title>
                            </rect>`;
                }
            }

            // Hour labels — every 6 hours plus 23
            for (const h of [0, 6, 12, 18, 23]) {
                const x = padLeft + h * slotW + slotW / 2;
                svg += `<text class="spark-label"
                            x="${x}" y="${H - 6}"
                            text-anchor="middle">${String(h).padStart(2,'0')}</text>`;
            }

            svg += '</svg>';
            container.innerHTML = svg;
        }

        function renderCurrencies(currencies, grandTotal) {
            const body = $('currencyBody');
            if (!Array.isArray(currencies) || currencies.length === 0) {
                body.innerHTML = '<tr><td colspan="5" class="empty">لا توجد بيانات في هذه الفترة.</td></tr>';
                return;
            }
            let html = '';
            for (const c of currencies) {
                const pct = grandTotal > 0 ? (c.net_usd / grandTotal) * 100 : 0;
                html += `<tr>
                    <td>${escapeHtml(c.name)}</td>
                    <td class="num">${c.count}</td>
                    <td class="num">${fmtNum(c.net_original)}</td>
                    <td class="num">${fmtNum(c.net_usd)}</td>
                    <td class="num">${pct.toFixed(1)}%</td>
                </tr>`;
            }
            body.innerHTML = html;
        }

        // ---------- Auto-refresh every 60s ----------
        setInterval(() => {
            if (!document.hidden) loadKPI();
        }, 60000);

        // ---------- Bootstrap ----------
        applyPreset('today');
    </script>
</body>
</html>