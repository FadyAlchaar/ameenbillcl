<?php
// cost-centers.php — Cost center summary + per-center ledger.
// Summary: aggregated from en000. Ledger: via ameenbill_GetCostCenterLedger.
require_once 'config.php';
require_once 'auth.php';
requireLogin(true);

$NULL_GUID = '00000000-0000-0000-0000-000000000000';
$MAX_ROWS  = 3000;

// ─── Parse date range (shared by both endpoints) ─────────────────
function parseRange() {
    $fromRaw = $_GET['from'] ?? null;
    $toRaw   = $_GET['to']   ?? null;
    try {
        $from = $fromRaw ? new DateTime($fromRaw) : (new DateTime('today'))->modify('first day of this month');
        $to   = $toRaw   ? new DateTime($toRaw)   : new DateTime('today');
    } catch (Exception $e) {
        throw new RuntimeException('Invalid date');
    }
    $today = new DateTime('today');
    if ($to > $today) $to = $today;
    if ($from > $to)  $from = clone $to;

    return [
        'from'      => $from,
        'to'        => $to,
        'fromStart' => $from->format('Y-m-d') . ' 00:00:00',
        'toEnd'     => $to->format('Y-m-d')   . ' 23:59:59.997',
    ];
}

// ─── API 1: summary of cost centers in a date range ──────────────
if (isset($_GET['summary']) && $_GET['summary'] == 1) {
    header('Content-Type: application/json');
    try {
        $r = parseRange();
        $pdo = getDBConnection();

        $sql = "SELECT
                    co.GUID  AS CostGUID,
                    co.Code  AS CostCode,
                    co.Name  AS CostName,
                    COUNT(*) AS EntryCount,
                    ISNULL(SUM(en.Debit), 0)  AS TotalDebit,
                    ISNULL(SUM(en.Credit), 0) AS TotalCredit,
                    ISNULL(SUM(en.Debit - en.Credit), 0) AS Net
                FROM en000 en
                INNER JOIN co000 co ON en.CostGUID = co.GUID
                INNER JOIN ce000 ce ON en.ParentGUID = ce.GUID
                WHERE en.Date >= :fromStart AND en.Date < :toEnd
                  AND en.CostGUID IS NOT NULL
                  AND en.CostGUID <> :nil
                  AND ce.IsPosted = 1
                GROUP BY co.GUID, co.Code, co.Name
                HAVING SUM(en.Debit) <> 0 OR SUM(en.Credit) <> 0
                ORDER BY co.Code";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':fromStart' => $r['fromStart'],
            ':toEnd'     => $r['toEnd'],
            ':nil'       => $NULL_GUID,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $centers = [];
        $totalDebit = 0; $totalCredit = 0;
        foreach ($rows as $row) {
            $debit  = (float)$row['TotalDebit'];
            $credit = (float)$row['TotalCredit'];
            $totalDebit  += $debit;
            $totalCredit += $credit;
            $centers[] = [
                'CostGUID'   => $row['CostGUID'],
                'CostCode'   => $row['CostCode'],
                'CostName'   => $row['CostName'],
                'EntryCount' => (int)$row['EntryCount'],
                'TotalDebit' => $debit,
                'TotalCredit'=> $credit,
                'Net'        => $debit - $credit,
            ];
        }

        echo json_encode([
            'from'    => $r['from']->format('Y-m-d'),
            'to'      => $r['to']->format('Y-m-d'),
            'centers' => $centers,
            'totals'  => [
                'count'        => count($centers),
                'totalDebit'   => $totalDebit,
                'totalCredit'  => $totalCredit,
                'net'          => $totalDebit - $totalCredit,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        jsonFail($e, 500, 'Could not load cost center summary.');
    }
    exit;
}

// ─── API 2: ledger for one cost center ───────────────────────────
if (isset($_GET['ledger']) && $_GET['ledger'] == 1 && isset($_GET['guid'])) {
    header('Content-Type: application/json');
    $guid = $_GET['guid'];
    if (!preg_match('/^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/', $guid)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid GUID']);
        exit;
    }

    try {
        $r = parseRange();
        $pdo = getDBConnection();

        $stmt = $pdo->prepare("
            EXEC dbo.ameenbill_GetCostCenterLedger
                @CostGUID  = :cost,
                @StartDate = :from,
                @EndDate   = :to
        ");
        $stmt->execute([
            ':cost' => $guid,
            ':from' => $r['fromStart'],
            ':to'   => $r['toEnd'],
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $truncated = false;
        if (count($rows) > $MAX_ROWS) {
            $rows = array_slice($rows, 0, $MAX_ROWS);
            $truncated = true;
        }

        // Sort chronologically: Date, then entry number. Running balance
        // accumulates Debit - Credit in that order.
        usort($rows, function ($a, $b) {
            $da = $a['Date'] ?? '';  $db = $b['Date'] ?? '';
            if ($da !== $db) return strcmp($da, $db);
            $na = (int)($a['ceNumber'] ?? 0);
            $nb = (int)($b['ceNumber'] ?? 0);
            return $na <=> $nb;
        });

        $entries = [];
        $running = 0.0;
        $sumDebit = 0.0; $sumCredit = 0.0;
        $centerInfo = null;

        foreach ($rows as $x) {
            if ($centerInfo === null && !empty($x['CostCode'])) {
                $centerInfo = [
                    'Code' => $x['CostCode'],
                    'Name' => $x['CostName'],
                ];
            }
            $debit  = (float)$x['Debit'];
            $credit = (float)$x['Credit'];
            $running += $debit - $credit;
            $sumDebit  += $debit;
            $sumCredit += $credit;

            $entries[] = [
                'Date'         => isoStamp($x['Date'] ?? null),
                'PostDate'     => isoStamp($x['PostDate'] ?? null),
                'EntryNumber'  => $x['ceNumber'],
                'EntryStr'     => $x['ceStr'],
                'AccCode'      => $x['AccCode'],
                'AccName'      => $x['AccName'],
                'Debit'        => $debit,
                'Credit'       => $credit,
                'Balance'      => $running,
                'Notes'        => $x['Notes'] ?? '',
                'ContraAccName'=> $x['ContraAccName'],
                'ContraAccCode'=> $x['ContraAccCode'],
            ];
        }

        echo json_encode([
            'center'  => $centerInfo,
            'entries' => $entries,
            'totals'  => [
                'count'        => count($entries),
                'totalDebit'   => $sumDebit,
                'totalCredit'  => $sumCredit,
                'net'          => $sumDebit - $sumCredit,
            ],
            'range'   => [
                'from' => $r['from']->format('Y-m-d'),
                'to'   => $r['to']->format('Y-m-d'),
            ],
            'truncated' => $truncated,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        jsonFail($e, 500, 'Could not load cost center ledger.');
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مراكز التكلفة - QuantuSphere Web</title>
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
        .btn-primary { background: var(--primary); border-color: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); }

        .chip-bar {
            display: flex; align-items: center; flex-wrap: wrap; gap: 8px;
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 10px 14px; margin-bottom: 16px;
        }
        .chip-bar label { font-size: 0.72rem; color: var(--text-muted); font-weight: 700; }
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
        .kpi-card.kpi-primary { border-color: var(--primary); }
        .kpi-card.kpi-primary .kpi-value { color: var(--primary-dark); font-size: 1.4rem; }
        .kpi-card.kpi-debit  { border-color: var(--accent); }
        .kpi-card.kpi-debit .kpi-value { color: var(--accent); }
        .kpi-card.kpi-credit { border-color: var(--danger); }
        .kpi-card.kpi-credit .kpi-value { color: var(--danger); }

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
        .num { font-family: var(--font-num); font-variant-numeric: tabular-nums; }
        .muted { color: var(--text-muted); }

        tr.clickable { position: relative; cursor: pointer; }
        tr.clickable:hover td { background: var(--primary-light); }
        tr.clickable .row-link { color: inherit; text-decoration: none; display: inline; }
        tr.clickable .row-link::after { content: ''; position: absolute; inset: 0; z-index: 1; }
        tr.clickable .row-link:hover { text-decoration: underline; }

        th.sortable { cursor: pointer; user-select: none; white-space: nowrap; }
        th.sortable:hover { color: var(--primary-dark); }
        th.sortable.sort-active { color: var(--primary-dark); }
        .sort-ind { display: inline-block; width: 12px; margin-right: 4px; color: var(--text-muted); font-size: 0.7rem; }
        th.sortable.sort-active .sort-ind { color: var(--primary-dark); font-weight: 900; }

        .code-cell {
            font-family: var(--font-num); font-variant-numeric: tabular-nums;
            color: var(--text-muted); font-size: 0.78rem; letter-spacing: 0.5px;
        }
        .pos { color: var(--danger); font-weight: 700; }
        .neg { color: var(--accent); font-weight: 700; }
        .zero { color: var(--text-muted); }

        .center-header {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 16px 20px; margin-bottom: 16px;
        }
        .center-header h2 { margin: 0 0 4px; font-size: 1.3rem; font-weight: 900; }
        .center-header .sub { color: var(--text-muted); font-size: 0.85rem; }

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
            th, td { padding: 8px 6px; font-size: 0.75rem; }
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
            .kpi-value { font-size: 1rem; }
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
                <h1><span class="tile-icon"><i class="ti ti-briefcase"></i></span>مراكز التكلفة</h1>
            </div>
        </div>

        <!-- ============ SUMMARY VIEW (default) ============ -->
        <div id="summaryView">
            <div class="chip-bar">
                <button type="button" class="chip" data-preset="month">هذا الشهر</button>
                <button type="button" class="chip" data-preset="prevmonth">الشهر الماضي</button>
                <button type="button" class="chip" data-preset="quarter">آخر 3 أشهر</button>
                <button type="button" class="chip" data-preset="year">هذه السنة</button>
                <span class="spacer"></span>
                <label>من</label>
                <input type="date" id="dateFrom">
                <label>إلى</label>
                <input type="date" id="dateTo">
                <button type="button" class="btn-primary" id="applyBtn">تطبيق</button>
            </div>

            <div class="kpi-grid">
                <div class="kpi-card kpi-primary">
                    <div class="kpi-label">💼 مراكز التكلفة النشطة</div>
                    <div class="kpi-value" id="kpiCenters">—</div>
                </div>
                <div class="kpi-card kpi-debit">
                    <div class="kpi-label">⬇️ إجمالي المدين</div>
                    <div class="kpi-value" id="kpiDebit">—</div>
                </div>
                <div class="kpi-card kpi-credit">
                    <div class="kpi-label">⬆️ إجمالي الدائن</div>
                    <div class="kpi-value" id="kpiCredit">—</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">📊 الصافي</div>
                    <div class="kpi-value" id="kpiNet">—</div>
                </div>
            </div>

            <div class="section">
                <h2 class="section-title">
                    <span>📋 ملخص مراكز التكلفة</span>
                    <span class="muted" id="listCount"></span>
                </h2>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th class="sortable list-sortable" data-sort="code">الكود <span class="sort-ind"></span></th>
                                <th class="sortable list-sortable" data-sort="name">اسم مركز التكلفة <span class="sort-ind"></span></th>
                                <th class="sortable list-sortable" data-sort="entries">عدد القيود <span class="sort-ind"></span></th>
                                <th class="sortable list-sortable" data-sort="debit">مدين <span class="sort-ind"></span></th>
                                <th class="sortable list-sortable" data-sort="credit">دائن <span class="sort-ind"></span></th>
                                <th class="sortable list-sortable" data-sort="net">الصافي <span class="sort-ind"></span></th>
                            </tr>
                        </thead>
                        <tbody id="listBody">
                            <tr><td colspan="7" class="loading">جاري التحميل...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ============ DETAIL VIEW (shown when ?guid= present) ============ -->
        <div id="detailView" style="display:none;">
            <div style="margin-bottom: 16px;">
                <a href="cost-centers.php" class="btn">← كل مراكز التكلفة</a>
            </div>

            <div class="center-header">
                <h2 id="centerName">—</h2>
                <div class="sub" id="centerSub">—</div>
            </div>

            <div class="chip-bar">
                <button type="button" class="chip" data-preset="month">هذا الشهر</button>
                <button type="button" class="chip" data-preset="prevmonth">الشهر الماضي</button>
                <button type="button" class="chip" data-preset="quarter">آخر 3 أشهر</button>
                <button type="button" class="chip" data-preset="year">هذه السنة</button>
                <span class="spacer"></span>
                <label>من</label>
                <input type="date" id="detailDateFrom">
                <label>إلى</label>
                <input type="date" id="detailDateTo">
                <button type="button" class="btn-primary" id="detailApplyBtn">تطبيق</button>
            </div>

            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-label">📄 عدد القيود</div>
                    <div class="kpi-value" id="kpiDetailCount">—</div>
                </div>
                <div class="kpi-card kpi-debit">
                    <div class="kpi-label">⬇️ إجمالي المدين</div>
                    <div class="kpi-value" id="kpiDetailDebit">—</div>
                </div>
                <div class="kpi-card kpi-credit">
                    <div class="kpi-label">⬆️ إجمالي الدائن</div>
                    <div class="kpi-value" id="kpiDetailCredit">—</div>
                </div>
                <div class="kpi-card kpi-primary">
                    <div class="kpi-label">📊 الرصيد الصافي</div>
                    <div class="kpi-value" id="kpiDetailNet">—</div>
                </div>
            </div>

            <div id="truncatedWarning" class="warn-banner" style="display:none;">
                ⚠ النتائج مقيدة بـ 3000 قيد. اختر فترة زمنية أقصر لرؤية الباقي.
            </div>

            <div class="section">
                <h2 class="section-title">
                    <span>📄 كشف الحركات</span>
                    <span class="muted" id="detailCount"></span>
                </h2>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>التاريخ</th>
                                <th>رقم القيد</th>
                                <th>الحساب</th>
                                <th>البيان</th>
                                <th>مدين</th>
                                <th>دائن</th>
                                <th>الرصيد التراكمي</th>
                            </tr>
                        </thead>
                        <tbody id="detailBody">
                            <tr><td colspan="8" class="loading">جاري التحميل...</td></tr>
                        </tbody>
                    </table>
                </div>
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
        function balanceClass(v) {
            if (v > 0.005)  return 'pos';
            if (v < -0.005) return 'neg';
            return 'zero';
        }
        function fmtDateInput(d) {
            return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
        }
        function dateMinusDays(date, n) {
            const d = new Date(date); d.setDate(d.getDate() - n); return d;
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
        const urlParams = new URLSearchParams(window.location.search);
        const detailGuid = urlParams.get('guid');
        const isDetail   = !!detailGuid;

        // ---------- State ----------
        const FILTER_KEY = 'costCentersDateRange';
        let summaryRange = { from: '', to: '' };
        let detailRange  = { from: '', to: '' };
        let summarySort  = { key: 'code', dir: 'asc' };
        let summaryData  = [];

        function saveFilter(r) {
            try { localStorage.setItem(FILTER_KEY, JSON.stringify(r)); } catch (e) {}
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

        // ---------- Preset handling ----------
        function presetRange(preset) {
            const today = new Date();
            if (preset === 'month') {
                return { from: fmtDateInput(new Date(today.getFullYear(), today.getMonth(), 1)), to: fmtDateInput(today) };
            }
            if (preset === 'prevmonth') {
                const firstPrev = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                const lastPrev  = new Date(today.getFullYear(), today.getMonth(), 0);
                return { from: fmtDateInput(firstPrev), to: fmtDateInput(lastPrev) };
            }
            if (preset === 'quarter') return { from: fmtDateInput(dateMinusDays(today, 90)), to: fmtDateInput(today) };
            if (preset === 'year')    return { from: fmtDateInput(new Date(today.getFullYear(), 0, 1)), to: fmtDateInput(today) };
            return { from: fmtDateInput(today), to: fmtDateInput(today) };
        }
        function setActiveChip(preset, scope) {
            document.querySelectorAll(scope + ' .chip[data-preset]').forEach(c => {
                c.classList.toggle('active', c.dataset.preset === preset);
            });
        }

        // ---------- SUMMARY view ----------
        function updateSummarySortIndicators() {
            document.querySelectorAll('#summaryView th.list-sortable').forEach(th => {
                const ind = th.querySelector('.sort-ind');
                if (th.dataset.sort === summarySort.key) {
                    th.classList.add('sort-active');
                    if (ind) ind.textContent = summarySort.dir === 'asc' ? '▲' : '▼';
                } else {
                    th.classList.remove('sort-active');
                    if (ind) ind.textContent = '';
                }
            });
        }

        document.querySelectorAll('#summaryView th.list-sortable').forEach(th => {
            th.addEventListener('click', () => {
                const key = th.dataset.sort;
                if (summarySort.key === key) {
                    summarySort.dir = summarySort.dir === 'asc' ? 'desc' : 'asc';
                } else {
                    summarySort.key = key;
                    summarySort.dir = (key === 'code' || key === 'name') ? 'asc' : 'desc';
                }
                updateSummarySortIndicators();
                renderSummary();
            });
        });

        async function loadSummary() {
            const body = document.getElementById('listBody');
            body.innerHTML = '<tr><td colspan="7" class="loading">جاري التحميل...</td></tr>';

            const qs = new URLSearchParams({ summary: '1', from: summaryRange.from, to: summaryRange.to });

            try {
                const resp = await fetch('?' + qs.toString(), { cache: 'no-store' });
                if (resp.status === 401) { window.location.href = 'login.php'; return; }
                const data = await resp.json();
                if (!data || data.error) {
                    body.innerHTML = `<tr><td colspan="7" class="loading">خطأ: ${escapeHtml((data && data.error) || '')}</td></tr>`;
                    return;
                }

                document.getElementById('kpiCenters').textContent = data.totals.count;
                document.getElementById('kpiDebit').textContent   = fmtNum(data.totals.totalDebit);
                document.getElementById('kpiCredit').textContent  = fmtNum(data.totals.totalCredit);
                document.getElementById('kpiNet').textContent     = fmtNum(data.totals.net);

                summaryData = data.centers;
                renderSummary();
            } catch (e) {
                console.error(e);
                body.innerHTML = `<tr><td colspan="7" class="loading">خطأ في الشبكة: ${escapeHtml(e.message)}</td></tr>`;
            }
        }

        function renderSummary() {
            const body = document.getElementById('listBody');
            if (!summaryData.length) {
                body.innerHTML = '<tr><td colspan="7" class="empty">لا توجد حركات على مراكز التكلفة في هذه الفترة.</td></tr>';
                document.getElementById('listCount').textContent = '';
                return;
            }

            const dir = summarySort.dir === 'asc' ? 1 : -1;
            const k = summarySort.key;

            const sorted = summaryData.slice().sort((a, b) => {
                let va, vb;
                if (k === 'code')        { va = String(a.CostCode || ''); vb = String(b.CostCode || ''); }
                else if (k === 'name')   { va = String(a.CostName || ''); vb = String(b.CostName || ''); }
                else if (k === 'entries'){ va = a.EntryCount; vb = b.EntryCount; }
                else if (k === 'debit')  { va = a.TotalDebit; vb = b.TotalDebit; }
                else if (k === 'credit') { va = a.TotalCredit; vb = b.TotalCredit; }
                else if (k === 'net')    { va = a.Net; vb = b.Net; }
                else return 0;

                if (va < vb) return -1 * dir;
                if (va > vb) return  1 * dir;
                return 0;
            });

            let html = '';
            let idx = 0;
            for (const c of sorted) {
                idx++;
                html += `<tr class="clickable">
                    <td class="num muted">${idx}</td>
                    <td class="code-cell">${escapeHtml(c.CostCode || '')}</td>
                    <td><a class="row-link" href="cost-centers.php?guid=${encodeURIComponent(c.CostGUID)}">${escapeHtml(c.CostName || '-')}</a></td>
                    <td class="num">${c.EntryCount}</td>
                    <td class="num pos">${fmtNum(c.TotalDebit)}</td>
                    <td class="num neg">${fmtNum(c.TotalCredit)}</td>
                    <td class="num ${balanceClass(c.Net)}"><b>${fmtNum(c.Net)}</b></td>
                </tr>`;
            }
            body.innerHTML = html;
            document.getElementById('listCount').textContent = `(${sorted.length} مركز)`;
        }

        document.querySelectorAll('#summaryView .chip[data-preset]').forEach(btn => {
            btn.addEventListener('click', () => {
                const r = presetRange(btn.dataset.preset);
                summaryRange = r;
                document.getElementById('dateFrom').value = r.from;
                document.getElementById('dateTo').value   = r.to;
                setActiveChip(btn.dataset.preset, '#summaryView');
                saveFilter(r);
                loadSummary();
            });
        });
        document.getElementById('applyBtn').addEventListener('click', () => {
            const from = document.getElementById('dateFrom').value;
            const to   = document.getElementById('dateTo').value;
            if (!from || !to) return;
            if (from > to) { alert('تاريخ البداية يجب أن يكون قبل تاريخ النهاية.'); return; }
            summaryRange = { from, to };
            setActiveChip(null, '#summaryView');
            saveFilter(summaryRange);
            loadSummary();
        });

        // ---------- DETAIL view ----------
        async function loadDetail() {
            const body = document.getElementById('detailBody');
            body.innerHTML = '<tr><td colspan="8" class="loading">جاري التحميل...</td></tr>';
            document.getElementById('truncatedWarning').style.display = 'none';

            const qs = new URLSearchParams({ ledger: '1', guid: detailGuid, from: detailRange.from, to: detailRange.to });

            try {
                const resp = await fetch('?' + qs.toString(), { cache: 'no-store' });
                if (resp.status === 401) { window.location.href = 'login.php'; return; }
                const data = await resp.json();
                if (!data || data.error) {
                    body.innerHTML = `<tr><td colspan="8" class="loading">خطأ: ${escapeHtml((data && data.error) || '')}</td></tr>`;
                    return;
                }

                const c = data.center || { Code: '—', Name: '—' };
                document.getElementById('centerName').textContent = c.Name || '(بدون اسم)';
                document.getElementById('centerSub').textContent  = 'الكود: ' + (c.Code || '-');

                document.getElementById('kpiDetailCount').textContent  = data.totals.count;
                document.getElementById('kpiDetailDebit').textContent  = fmtNum(data.totals.totalDebit);
                document.getElementById('kpiDetailCredit').textContent = fmtNum(data.totals.totalCredit);
                document.getElementById('kpiDetailNet').textContent    = fmtNum(data.totals.net);

                if (data.truncated) document.getElementById('truncatedWarning').style.display = '';

                const rows = data.entries;
                if (!rows.length) {
                    body.innerHTML = '<tr><td colspan="8" class="empty">لا توجد حركات في هذه الفترة.</td></tr>';
                    document.getElementById('detailCount').textContent = '';
                    return;
                }

                let html = '';
                let idx = 0;
                for (const r of rows) {
                    idx++;
                    const dateStr = r.Date ? new Date(r.Date).toLocaleDateString('en-GB') : '-';
                    const accCell = r.AccCode
                        ? `<span class="code-cell">${escapeHtml(r.AccCode)}</span> ${escapeHtml(r.AccName || '')}`
                        : '—';
                    html += `<tr>
                        <td class="num muted">${idx}</td>
                        <td class="num">${dateStr}</td>
                        <td class="num">${escapeHtml(String(r.EntryNumber || '-'))}</td>
                        <td>${accCell}</td>
                        <td>${escapeHtml(r.Notes || '')}</td>
                        <td class="num pos">${r.Debit ? fmtNum(r.Debit) : ''}</td>
                        <td class="num neg">${r.Credit ? fmtNum(r.Credit) : ''}</td>
                        <td class="num ${balanceClass(r.Balance)}"><b>${fmtNum(r.Balance)}</b></td>
                    </tr>`;
                }
                body.innerHTML = html;
                document.getElementById('detailCount').textContent = `(${rows.length} قيد)`;
            } catch (e) {
                console.error(e);
                body.innerHTML = `<tr><td colspan="8" class="loading">خطأ في الشبكة: ${escapeHtml(e.message)}</td></tr>`;
            }
        }

        document.querySelectorAll('#detailView .chip[data-preset]').forEach(btn => {
            btn.addEventListener('click', () => {
                const r = presetRange(btn.dataset.preset);
                detailRange = r;
                document.getElementById('detailDateFrom').value = r.from;
                document.getElementById('detailDateTo').value   = r.to;
                setActiveChip(btn.dataset.preset, '#detailView');
                loadDetail();
            });
        });
        document.getElementById('detailApplyBtn').addEventListener('click', () => {
            const from = document.getElementById('detailDateFrom').value;
            const to   = document.getElementById('detailDateTo').value;
            if (!from || !to) return;
            if (from > to) { alert('تاريخ البداية يجب أن يكون قبل تاريخ النهاية.'); return; }
            detailRange = { from, to };
            setActiveChip(null, '#detailView');
            loadDetail();
        });

        // ---------- Bootstrap ----------
        (async () => {
            if (isDetail) {
                document.getElementById('summaryView').style.display = 'none';
                document.getElementById('detailView').style.display  = 'block';

                const saved = loadSavedFilter();
                const r = saved || presetRange('month');
                detailRange = r;
                document.getElementById('detailDateFrom').value = r.from;
                document.getElementById('detailDateTo').value   = r.to;
                setActiveChip(saved ? null : 'month', '#detailView');
                loadDetail();
            } else {
                const saved = loadSavedFilter();
                const r = saved || presetRange('month');
                summaryRange = r;
                document.getElementById('dateFrom').value = r.from;
                document.getElementById('dateTo').value   = r.to;
                setActiveChip(saved ? null : 'month', '#summaryView');
                updateSummarySortIndicators();
                loadSummary();
            }
        })();
    </script>
</body>
</html>