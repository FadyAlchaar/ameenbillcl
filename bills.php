<?php
// bills.php - Bill Patterns (bt000) with hierarchical grouping by BillType,
// plus per-pattern detail and the bills that belong to each pattern.
require_once 'config.php';
require_once 'auth.php';

$isApiRequest = isset($_GET['patterns']) || isset($_GET['pattern']) || isset($_GET['bills']);
requireLogin($isApiRequest);

$NULL_GUID = '00000000-0000-0000-0000-000000000000';

// ─── Shared date-range parsing ───────────────────────────────────
function parseRange() {
    $fromRaw = $_GET['from'] ?? null;
    $toRaw   = $_GET['to']   ?? null;

    // Empty strings = all time
    if (($fromRaw === null || $fromRaw === '') && ($toRaw === null || $toRaw === '')) {
        return [
            'from'      => null,
            'to'        => null,
            'fromStart' => null,
            'toEnd'     => null,
        ];
    }

    try {
        $from = $fromRaw ? new DateTime($fromRaw) : new DateTime('today');
        $to   = $toRaw   ? new DateTime($toRaw)   : new DateTime('today');
    } catch (Exception $e) {
        throw new RuntimeException('Invalid date');
    }
    if ($from > $to) throw new RuntimeException('Invalid date range');

    return [
        'from'      => $from->format('Y-m-d'),
        'to'        => $to->format('Y-m-d'),
        'fromStart' => $from->format('Y-m-d') . ' 00:00:00',
        'toEnd'     => (clone $to)->modify('+1 day')->format('Y-m-d') . ' 00:00:00',
    ];
}

// ─── API 1: list patterns + KPIs ─────────────────────────────────
if (isset($_GET['patterns']) && $_GET['patterns'] == 1) {
    header('Content-Type: application/json');
    try {
        $r = parseRange();
        $pdo = getDBConnection();

        // Aggregate bills per pattern. When a range is set, only bills in the
        // range count; when "all time" is selected, fromStart/toEnd are null.
        $billWhere  = '';
        $billParams = [];
        if ($r['fromStart'] !== null) {
            $billWhere = 'WHERE b.Date >= :fromStart AND b.Date < :toEnd';
            $billParams[':fromStart'] = $r['fromStart'];
            $billParams[':toEnd']     = $r['toEnd'];
        }

        $sql = "SELECT
                    t.GUID, t.Name, t.LatinName, t.Abbrev,
                    t.BillType, t.SortNum,
                    t.bPOSBill, t.bBarCodeBill, t.bPrintReceipt, t.bCashBill,
                    t.bIsInput, t.bIsOutput, t.bAutoEntry, t.bNoEntry, t.bNoPost,
                    t.IsPriceIncludeTax, t.bShortEntry,
                    ISNULL(agg.BillCount, 0)   AS BillCount,
                    ISNULL(agg.TotalUSD,  0)   AS TotalUSD,
                    agg.LastBillDate
                FROM bt000 t
                LEFT JOIN (
                    SELECT b.TypeGUID,
                           COUNT(*) AS BillCount,
                           SUM(b.Total - b.TotalDisc + b.TotalExtra) AS TotalUSD,
                           MAX(b.CreateDate) AS LastBillDate
                    FROM bu000 b
                    $billWhere
                    GROUP BY b.TypeGUID
                ) agg ON t.GUID = agg.TypeGUID
                ORDER BY t.BillType, t.SortNum";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($billParams);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $patterns = [];
        foreach ($rows as $row) {
            $patterns[] = [
                'GUID'              => $row['GUID'],
                'Name'              => $row['Name'],
                'LatinName'         => $row['LatinName'],
                'Abbrev'            => $row['Abbrev'],
                'BillType'          => (int)$row['BillType'],
                'SortNum'           => (int)$row['SortNum'],
                'bPOSBill'          => (int)$row['bPOSBill'],
                'bBarCodeBill'      => (int)$row['bBarCodeBill'],
                'bPrintReceipt'     => (int)$row['bPrintReceipt'],
                'bCashBill'         => (int)$row['bCashBill'],
                'bIsInput'          => (int)$row['bIsInput'],
                'bIsOutput'         => (int)$row['bIsOutput'],
                'bAutoEntry'        => (int)$row['bAutoEntry'],
                'bNoEntry'          => (int)$row['bNoEntry'],
                'bNoPost'           => (int)$row['bNoPost'],
                'bShortEntry'       => (int)$row['bShortEntry'],
                'IsPriceIncludeTax' => (int)$row['IsPriceIncludeTax'],
                'BillCount'         => (int)$row['BillCount'],
                'TotalUSD'          => (float)$row['TotalUSD'],
                'LastBillDate'      => isoStamp($row['LastBillDate'] ?? null),
            ];
        }

        // KPI totals
        $kpiSql = "SELECT
                       (SELECT COUNT(*) FROM bt000) AS PatternCount,
                       COUNT(*) AS TotalBills,
                       ISNULL(SUM(b.Total - b.TotalDisc + b.TotalExtra), 0) AS TotalValue
                   FROM bu000 b
                   " . ($r['fromStart'] !== null ? 'WHERE b.Date >= :fromStart AND b.Date < :toEnd' : '');
        $kStmt = $pdo->prepare($kpiSql);
        $kStmt->execute($billParams);
        $kpiRow = $kStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        echo json_encode([
            'from'     => $r['from'],
            'to'       => $r['to'],
            'patterns' => $patterns,
            'kpi'      => [
                'patternCount' => (int)($kpiRow['PatternCount'] ?? 0),
                'totalBills'   => (int)($kpiRow['TotalBills'] ?? 0),
                'totalValue'   => (float)($kpiRow['TotalValue'] ?? 0),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        jsonFail($e, 500, 'Could not load bill patterns.');
    }
    exit;
}

// ─── API 2: single pattern + resolved defaults + recent bills ────
if (isset($_GET['pattern']) && $_GET['pattern'] == 1 && isset($_GET['guid'])) {
    header('Content-Type: application/json');
    $guid = $_GET['guid'];
    if (!preg_match('/^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/', $guid)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid GUID']);
        exit;
    }
    try {
        $pdo = getDBConnection();

        $sql = "SELECT
                    t.GUID, t.Name, t.LatinName, t.Abbrev, t.Abbrev AS AbbrevShort,
                    t.BillType, t.SortNum, t.Color1, t.Color2,
                    t.bPOSBill, t.bBarCodeBill, t.bPrintReceipt, t.bCashBill,
                    t.bIsInput, t.bIsOutput, t.bAutoEntry, t.bNoEntry, t.bNoPost,
                    t.IsPriceIncludeTax, t.bShortEntry, t.bForceCustomer,
                    t.UseExciseTax, t.UseReverseCharges, t.bNoCostFld, t.bNoStatFld,
                    cur.Name AS CurrencyName,
                    st.Name  AS DefaultStoreName, st.Number AS DefaultStoreNumber,
                    aBill.Name  AS BillAccName,
                    aCash.Name  AS CashAccName,
                    aDisc.Name  AS DiscAccName,
                    aExtra.Name AS ExtraAccName,
                    aVAT.Name   AS VATAccName,
                    aCost.Name  AS CostAccName,
                    aStock.Name AS StockAccName,
                    aBonus.Name AS BonusAccName,
                    aBonusContra.Name AS BonusContraAccName,
                    aCust.Name  AS CustAccName,
                    aMain.Name  AS MainAccName,
                    aRev.Name   AS RevChargesAccName,
                    aRevContra.Name AS RevChargesContraAccName,
                    aExcise.Name AS ExciseAccName,
                    aExciseContra.Name AS ExciseContraAccName
                FROM bt000 t
                LEFT JOIN my000 cur ON t.DefCurrencyGUID = cur.GUID
                LEFT JOIN st000 st  ON t.DefStoreGUID    = st.GUID
                LEFT JOIN ac000 aBill        ON t.DefBillAccGUID  = aBill.GUID
                LEFT JOIN ac000 aCash        ON t.DefCashAccGUID  = aCash.GUID
                LEFT JOIN ac000 aDisc        ON t.DefDiscAccGUID  = aDisc.GUID
                LEFT JOIN ac000 aExtra       ON t.DefExtraAccGUID = aExtra.GUID
                LEFT JOIN ac000 aVAT         ON t.DefVATAccGUID   = aVAT.GUID
                LEFT JOIN ac000 aCost        ON t.DefCostAccGUID  = aCost.GUID
                LEFT JOIN ac000 aStock       ON t.DefStockAccGUID = aStock.GUID
                LEFT JOIN ac000 aBonus       ON t.DefBonusAccGUID = aBonus.GUID
                LEFT JOIN ac000 aBonusContra ON t.DefBonusContraAccGUID = aBonusContra.GUID
                LEFT JOIN ac000 aCust        ON t.CustAccGuid    = aCust.GUID
                LEFT JOIN ac000 aMain        ON t.DefMainAccount = aMain.GUID
                LEFT JOIN ac000 aRev         ON t.ReverseChargesAccGUID = aRev.GUID
                LEFT JOIN ac000 aRevContra   ON t.ReverseChargesContraAccGUID = aRevContra.GUID
                LEFT JOIN ac000 aExcise      ON t.ExciseAccGUID = aExcise.GUID
                LEFT JOIN ac000 aExciseContra ON t.ExciseContraAccGUID = aExciseContra.GUID
                WHERE t.GUID = :guid";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':guid' => $guid]);
        $pattern = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pattern) {
            http_response_code(404);
            echo json_encode(['error' => 'Pattern not found']);
            exit;
        }

        // KPI: all-time stats for this pattern
        $kStmt = $pdo->prepare("
            SELECT COUNT(*) AS BillCount,
                   ISNULL(SUM(b.Total - b.TotalDisc + b.TotalExtra), 0) AS TotalUSD,
                   MAX(b.CreateDate) AS LastBillDate
            FROM bu000 b WHERE b.TypeGUID = :guid
        ");
        $kStmt->execute([':guid' => $guid]);
        $kpi = $kStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // Recent bills — first page
        $bStmt = $pdo->prepare("
            SELECT TOP 50
                b.GUID, b.Number, b.Date, b.PayType, b.Cust_Name,
                b.Total, b.TotalDisc, b.TotalExtra, b.CurrencyVal,
                cur.Name AS CurrencyName
            FROM bu000 b
            LEFT JOIN my000 cur ON b.CurrencyGUID = cur.GUID
            WHERE b.TypeGUID = :guid
            ORDER BY b.CreateDate DESC
        ");
        $bStmt->execute([':guid' => $guid]);
        $bills = $bStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($bills as &$bill) {
            $bill['Date']        = isoStamp($bill['Date'] ?? null);
            $bill['Total']       = (float)$bill['Total'];
            $bill['TotalDisc']   = (float)$bill['TotalDisc'];
            $bill['TotalExtra']  = (float)$bill['TotalExtra'];
            $bill['CurrencyVal'] = (float)$bill['CurrencyVal'];
            $bill['Net']         = $bill['Total'] - $bill['TotalDisc'] + $bill['TotalExtra'];
        }
        unset($bill);

        echo json_encode([
            'pattern' => $pattern,
            'kpi'     => [
                'billCount'    => (int)($kpi['BillCount'] ?? 0),
                'totalUSD'     => (float)($kpi['TotalUSD'] ?? 0),
                'lastBillDate' => isoStamp($kpi['LastBillDate'] ?? null),
            ],
            'bills' => $bills,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        jsonFail($e, 500, 'Could not load pattern.');
    }
    exit;
}

// ─── API 3: paginated bills for a pattern (Load More) ────────────
if (isset($_GET['bills']) && $_GET['bills'] == 1 && isset($_GET['guid'])) {
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
        $stmt = $pdo->prepare("
            SELECT b.GUID, b.Number, b.Date, b.PayType, b.Cust_Name,
                   b.Total, b.TotalDisc, b.TotalExtra, b.CurrencyVal,
                   cur.Name AS CurrencyName
            FROM bu000 b
            LEFT JOIN my000 cur ON b.CurrencyGUID = cur.GUID
            WHERE b.TypeGUID = :guid
            ORDER BY b.CreateDate DESC
            OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY
        ");
        $stmt->bindValue(':guid',   $guid);
        $stmt->bindValue(':offset', $offset,    PDO::PARAM_INT);
        $stmt->bindValue(':limit',  $limit + 1, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);

        foreach ($rows as &$b) {
            $b['Date']        = isoStamp($b['Date'] ?? null);
            $b['Total']       = (float)$b['Total'];
            $b['TotalDisc']   = (float)$b['TotalDisc'];
            $b['TotalExtra']  = (float)$b['TotalExtra'];
            $b['CurrencyVal'] = (float)$b['CurrencyVal'];
            $b['Net']         = $b['Total'] - $b['TotalDisc'] + $b['TotalExtra'];
        }
        unset($b);

        echo json_encode(
            ['bills' => $rows, 'hasMore' => $hasMore],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    } catch (Throwable $e) {
        jsonFail($e, 500, 'Could not load bills.');
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>أنماط الفواتير - QuantuSphere Web</title>
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

        /* Buttons */
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

        /* Date chips */
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

        /* KPI strip */
        .kpi-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
        .num { font-family: var(--font-num); font-variant-numeric: tabular-nums; }
        .muted { color: var(--text-muted); }

        /* Tree */
        .group-header td {
            background: var(--primary-light);
            padding: 12px 16px;
            cursor: pointer;
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
            user-select: none;
            font-weight: 700;
        }
        .group-header:hover td { background: rgba(184,134,60,0.18); }
        .group-header .grp-chevron { display: inline-block; width: 18px; color: var(--primary-dark); font-size: 0.85rem; }
        .group-header .grp-icon { margin-left: 6px; font-size: 1.05rem; }
        .group-header .grp-name { margin-left: 6px; font-size: 0.95rem; color: var(--text); }
        .group-header .grp-meta { margin-right: auto; font-size: 0.78rem; color: var(--text-muted); font-weight: 600; margin-left: 12px; }

        .pattern-row { position: relative; cursor: pointer; }
        .pattern-row:hover td { background: var(--primary-light); }
        .pattern-row td:first-child { padding-right: 38px; font-weight: 600; }
        /* Stretch the anchor to cover the whole row, so Ctrl+click,
           middle-click and "open in new tab" work naturally. */
        .pattern-row .row-link { color: inherit; text-decoration: none; display: inline; }
        .pattern-row .row-link::after {
            content: '';
            position: absolute;
            inset: 0;
            z-index: 1;
        }
        .pattern-row .row-link:hover { text-decoration: underline; }
        .pattern-color-dot {
            display: inline-block; width: 10px; height: 10px;
            border-radius: 50%; margin-left: 8px;
            border: 1px solid rgba(0,0,0,0.15); vertical-align: middle;
        }
        .abbrev {
            font-family: var(--font-num); font-variant-numeric: tabular-nums;
            color: var(--text-muted); font-size: 0.78rem;
        }
        .flag-chip {
            display: inline-block; font-size: 0.65rem; font-weight: 700;
            color: var(--primary-dark); background: var(--primary-light);
            padding: 1px 6px; border-radius: 4px; margin-left: 3px;
            white-space: nowrap;
        }
        .flag-chip.danger { color: var(--danger); background: rgba(162,59,46,0.08); }
        .flag-chip.accent { color: var(--accent); background: rgba(47,76,59,0.08); }

        .loading, .empty {
            padding: 30px; text-align: center;
            color: var(--text-muted); font-size: 0.85rem;
        }

        /* Detail view */
        .cust-header {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 16px 20px; margin-bottom: 16px;
            position: relative; overflow: hidden;
        }
        .cust-header h2 { margin: 0 0 4px; font-size: 1.3rem; font-weight: 900; }
        .cust-header .sub { color: var(--text-muted); font-size: 0.85rem; }
        .cust-header .color-stripe {
            position: absolute; top: 0; right: 0; bottom: 0; width: 5px;
        }

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
            padding: 6px 0; font-size: 0.85rem; border-bottom: 1px dashed var(--border);
        }
        .info-row:last-child { border-bottom: none; }
        .info-row .lbl { color: var(--text-muted); font-weight: 600; flex-shrink: 0; }
        .info-row .val { text-align: left; word-break: break-word; }

                /* Search bar above the pattern tree */
        .tree-search-bar {
            position: relative;
            padding: 10px 18px;
            background: var(--bg);
            border-bottom: 1px solid var(--border);
        }
        .tree-search-bar input {
            width: 100%;
            padding: 8px 36px 8px 14px;
            border: 1px solid var(--border);
            border-radius: 999px;
            background: var(--surface);
            color: var(--text);
            font-family: inherit;
            font-size: 0.85rem;
        }
        .tree-search-bar input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-light);
        }
        .tree-search-clear {
            position: absolute;
            left: 26px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 0.9rem;
            font-family: inherit;
            cursor: pointer;
            padding: 2px 8px;
            border-radius: 999px;
        }
        .tree-search-clear:hover { color: var(--danger); background: rgba(162,59,46,0.08); }


        th.sortable { cursor: pointer; user-select: none; white-space: nowrap; }
                /* Bills rows are clickable — they jump to the dashboard */
        .bill-click-row { position: relative; cursor: pointer; transition: background 0.12s; }
        .bill-click-row:hover td { background: var(--primary-light); }
        .bill-click-row .row-link { color: inherit; text-decoration: none; display: inline; }
        .bill-click-row .row-link::after {
            content: '';
            position: absolute;
            inset: 0;
            z-index: 1;
        }
        .bill-click-row .row-link:hover { text-decoration: underline; }

        th.sortable:hover { color: var(--primary-dark); }
        th.sortable.sort-active { color: var(--primary-dark); }
        .sort-ind { display: inline-block; width: 12px; margin-right: 4px; color: var(--text-muted); font-size: 0.7rem; }
        th.sortable.sort-active .sort-ind { color: var(--primary-dark); font-weight: 900; }

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
            th, td { padding: 8px 6px; font-size: 0.75rem; }
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
            .kpi-value { font-size: 1rem; }
            .group-header td { padding: 10px 12px; }
            .group-header .grp-name { font-size: 0.85rem; }
            .group-header .grp-meta { font-size: 0.68rem; }
            .pattern-row td:first-child { padding-right: 24px; }
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
                <h1><span class="tile-icon"><i class="ti ti-receipt"></i></span>أنماط الفواتير</h1>
            </div>
        </div>

        <!-- LIST VIEW -->
        <div id="listView">
            <div class="chip-bar">
                <button type="button" class="chip" data-preset="today">اليوم</button>
                <button type="button" class="chip" data-preset="yesterday">أمس</button>
                <button type="button" class="chip" data-preset="week">هذا الأسبوع</button>
                <button type="button" class="chip" data-preset="month">هذا الشهر</button>
                <button type="button" class="chip" data-preset="all">الكل</button>
                <span class="spacer"></span>
                <label>من</label>
                <input type="date" id="dateFrom">
                <label>إلى</label>
                <input type="date" id="dateTo">
                <button type="button" class="btn-primary" id="applyBtn">تطبيق</button>
            </div>

            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-label">📋 عدد الأنماط</div>
                    <div class="kpi-value" id="kpiPatterns">—</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">📄 عدد الفواتير</div>
                    <div class="kpi-value" id="kpiBills">—</div>
                </div>
                <div class="kpi-card kpi-primary">
                    <div class="kpi-label">💰 إجمالي القيمة</div>
                    <div class="kpi-value" id="kpiValue">—</div>
                </div>
            </div>

            <div class="section">
                <h2 class="section-title">
                    <span>🗂️ الأنماط حسب النوع</span>
                    <span class="muted" id="rangeLabel"></span>
                </h2>
                <div class="tree-search-bar">
                    <input type="text" id="treeSearch"
                           placeholder="🔍 ابحث في الأنماط بالاسم أو الكود..."
                           autocomplete="off" spellcheck="false">
                    <button type="button" class="tree-search-clear" id="treeSearchClear"
                            style="display:none;" title="مسح">✕</button>
                </div>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>النمط</th>
                                <th>الكود</th>
                                <th>عدد الفواتير</th>
                                <th>الإجمالي (USD)</th>
                                <th>آخر فاتورة</th>
                                <th>الأعلام</th>
                            </tr>
                        </thead>
                        <tbody id="treeBody">
                            <tr><td colspan="6" class="loading">جاري التحميل...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- DETAIL VIEW -->
        <div id="detailView" style="display:none;">
            <div class="cust-header" id="detailHeader">
                <div class="color-stripe" id="detailStripe" style="background:var(--primary);"></div>
                <h2 id="detailName">—</h2>
                <div class="sub" id="detailSub">—</div>
            </div>

            <div class="kpi-grid">
                <div class="kpi-card kpi-primary">
                    <div class="kpi-label">💰 إجمالي القيمة (USD)</div>
                    <div class="kpi-value" id="kpiPatternValue">—</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">📄 عدد الفواتير</div>
                    <div class="kpi-value" id="kpiPatternBills">—</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">📅 آخر فاتورة</div>
                    <div class="kpi-value" id="kpiPatternLast" style="font-size:1rem;">—</div>
                </div>
            </div>

            <div class="grid-2">
                <div class="info-card">
                    <h3>📋 معلومات النمط</h3>
                    <div id="infoBody"></div>
                </div>
                <div class="info-card">
                    <h3>⚙️ الإعدادات الافتراضية</h3>
                    <div id="defaultsBody"></div>
                </div>
            </div>

            <div class="info-card" style="margin-bottom:16px;">
                <h3>💰 الحسابات الافتراضية</h3>
                <div id="accountsBody"></div>
            </div>

            <div class="section">
                <h2 class="section-title">
                    <span>📄 آخر الفواتير</span>
                    <span class="muted" id="billsCount"></span>
                </h2>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>رقم الفاتورة</th>
                                <th>التاريخ</th>
                                <th>الزبون</th>
                                <th>طريقة الدفع</th>
                                <th>الصافي</th>
                            </tr>
                        </thead>
                        <tbody id="billsBody">
                            <tr><td colspan="5" class="loading">جاري التحميل...</td></tr>
                        </tbody>
                    </table>
                </div>
                <button type="button" class="load-more" id="billsMoreBtn" style="display:none;">➕ تحميل المزيد</button>
            </div>
        </div>
    </div>

    <script>
        // ---------- Constants ----------
        const BILL_TYPES = {
            0: { icon: '📦', label: 'مشتريات',        color: '#5B6672' },
            1: { icon: '🛒', label: 'مبيعات',          color: '#2F4C3B' },
            2: { icon: '📤', label: 'مرتجع مشتريات',   color: '#A23B2E' },
            3: { icon: '↩️', label: 'مرتجع مبيعات',    color: '#B8863C' },
            4: { icon: '⬇️', label: 'إدخال / مناقلة',  color: '#4C7B60' },
            5: { icon: '⬆️', label: 'إخراج / مناقلة',  color: '#96692C' },
        };
        const FLAG_MAP = [
            { col: 'bPOSBill',          label: 'POS',         cls: 'accent' },
            { col: 'bBarCodeBill',      label: 'باركود',       cls: '' },
            { col: 'bCashBill',         label: 'نقدي',         cls: 'accent' },
            { col: 'bIsInput',          label: 'إدخال',        cls: '' },
            { col: 'bIsOutput',         label: 'إخراج',        cls: '' },
            { col: 'bAutoEntry',        label: 'قيد تلقائي',   cls: '' },
            { col: 'bNoEntry',          label: 'بدون قيد',     cls: 'danger' },
            { col: 'bNoPost',           label: 'بدون ترحيل',   cls: 'danger' },
            { col: 'IsPriceIncludeTax', label: 'شامل الضريبة', cls: '' },
            { col: 'bShortEntry',       label: 'قيد مختصر',    cls: '' },
        ];

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

        // ---------- Routing ----------
        const urlParams  = new URLSearchParams(window.location.search);
        const detailGuid = urlParams.get('guid');
        const isDetail   = !!detailGuid;

        // ---------- State ----------
        const FILTER_KEY = 'billsPatternsFilter';
        let currentFilter = { from: '', to: '' };   // empty = all time
        let patternsData = [];
        let groupsExpanded = {};                    // all groups collapsed by default
        let treeSearchTerm = '';
        let billsOffset = 0;
        let billsHasMore = true;
        let billsLoadingMore = false;

        function saveFilter() {
            try { localStorage.setItem(FILTER_KEY, JSON.stringify(currentFilter)); } catch (e) {}
        }
        function loadSavedFilter() {
            try {
                const s = localStorage.getItem(FILTER_KEY);
                if (!s) return null;
                const p = JSON.parse(s);
                if (p && typeof p.from === 'string' && typeof p.to === 'string') return p;
            } catch (e) {}
            return null;
        }
        function fmtDateInput(d) {
            return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
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
                currentFilter = { from: '', to: '' };
                document.getElementById('dateFrom').value = '';
                document.getElementById('dateTo').value   = '';
                setActiveChip('all');
            } else {
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
                currentFilter = { from: fmtDateInput(from), to: fmtDateInput(to) };
                document.getElementById('dateFrom').value = currentFilter.from;
                document.getElementById('dateTo').value   = currentFilter.to;
                setActiveChip(preset);
            }
            saveFilter();
            loadPatterns();
        }

        document.querySelectorAll('.chip[data-preset]').forEach(btn => {
            btn.addEventListener('click', () => applyPreset(btn.dataset.preset));
        });
        document.getElementById('applyBtn').addEventListener('click', () => {
            const from = document.getElementById('dateFrom').value;
            const to   = document.getElementById('dateTo').value;
            if (from && to && from > to) { alert('تاريخ البداية يجب أن يكون قبل تاريخ النهاية.'); return; }
            currentFilter = { from, to };
            setActiveChip(null);
            saveFilter();
            loadPatterns();
        });

        function buildQuery() {
            const parts = [];
            if (currentFilter.from) parts.push('from=' + encodeURIComponent(currentFilter.from));
            if (currentFilter.to)   parts.push('to='   + encodeURIComponent(currentFilter.to));
            return parts.length ? '&' + parts.join('&') : '';
        }

        // ---------- List rendering ----------
        async function loadPatterns() {
            const body = document.getElementById('treeBody');
            body.innerHTML = '<tr><td colspan="6" class="loading">جاري التحميل...</td></tr>';

            try {
                const resp = await fetch('?patterns=1' + buildQuery(), { cache: 'no-store' });
                if (resp.status === 401) { window.location.href = 'login.php'; return; }
                const data = await resp.json();
                if (!data || data.error) {
                    body.innerHTML = `<tr><td colspan="6" class="loading">خطأ: ${escapeHtml(data.error || '')}</td></tr>`;
                    return;
                }

                document.getElementById('kpiPatterns').textContent = data.kpi.patternCount;
                document.getElementById('kpiBills').textContent    = data.kpi.totalBills;
                document.getElementById('kpiValue').textContent    = fmtNum(data.kpi.totalValue) + ' USD';

                const rangeLabel = document.getElementById('rangeLabel');
                if (data.from && data.to) {
                    rangeLabel.textContent = data.from === data.to ? data.from : (data.from + ' → ' + data.to);
                } else {
                    rangeLabel.textContent = 'كل الفترات';
                }

                patternsData = data.patterns;
                renderTree();
            } catch (e) {
                console.error(e);
                body.innerHTML = `<tr><td colspan="6" class="loading">خطأ في الشبكة: ${escapeHtml(e.message)}</td></tr>`;
            }
        }

                function renderTree() {
            const body = document.getElementById('treeBody');
            if (!patternsData.length) {
                body.innerHTML = '<tr><td colspan="6" class="empty">لا توجد أنماط.</td></tr>';
                return;
            }

            const term = treeSearchTerm.trim().toLowerCase();
            const searching = term.length > 0;

            // When searching, filter patterns by name/abbrev and auto-expand
            // any group that has at least one match. When not searching, use
            // the user's manual expand/collapse state.
            const matches = p => {
                if (!searching) return true;
                const hay = (
                    (p.Name || '') + ' ' +
                    (p.Abbrev || '') + ' ' +
                    (p.LatinName || '')
                ).toLowerCase();
                return hay.indexOf(term) !== -1;
            };

            const groups = {};
            for (const p of patternsData) {
                if (!matches(p)) continue;
                if (!groups[p.BillType]) groups[p.BillType] = [];
                groups[p.BillType].push(p);
            }

            const order = [1, 0, 3, 2, 4, 5];
            let html = '';
            let anyMatch = false;

            for (const bt of order) {
                const patterns = groups[bt];
                if (!patterns || !patterns.length) continue;
                anyMatch = true;

                const meta = BILL_TYPES[bt] || { icon: '📄', label: 'أخرى' };
                const totalBills = patterns.reduce((a, p) => a + p.BillCount, 0);
                const totalUSD   = patterns.reduce((a, p) => a + p.TotalUSD, 0);
                const expanded   = searching || !!groupsExpanded[bt];

                html += `<tr class="group-header" data-bt="${bt}">
                    <td colspan="6">
                        <span class="grp-chevron">${expanded ? '▼' : '▶'}</span>
                        <span class="grp-icon">${meta.icon}</span>
                        <span class="grp-name">${meta.label}</span>
                        <span class="grp-meta">${patterns.length} نمط · ${totalBills} فاتورة · ${fmtNum(totalUSD)} USD</span>
                    </td>
                </tr>`;

                if (expanded) {
                    for (const p of patterns) {
                        html += renderPatternRow(p, meta.color);
                    }
                }
            }

            if (!anyMatch) {
                body.innerHTML = '<tr><td colspan="6" class="empty">لا توجد نتائج مطابقة.</td></tr>';
                return;
            }

            body.innerHTML = html;

            // Group headers toggle collapse — but only when not searching,
            // otherwise it fights the auto-expand.
            body.querySelectorAll('.group-header').forEach(row => {
                row.addEventListener('click', () => {
                    if (searching) return;
                    const bt = parseInt(row.dataset.bt, 10);
                    groupsExpanded[bt] = !groupsExpanded[bt];
                    renderTree();
                });
            });
        }

        function renderPatternRow(p, groupColor) {
            const flags = FLAG_MAP.filter(f => p[f.col] == 1)
                .slice(0, 5)
                .map(f => `<span class="flag-chip ${f.cls}">${escapeHtml(f.label)}</span>`)
                .join('');

            const lastBill = p.LastBillDate ? new Date(p.LastBillDate).toLocaleDateString('en-GB') : '—';
            const dot = p.Color1 && p.Color1 !== 16777215
                ? `<span class="pattern-color-dot" style="background:#${p.Color1.toString(16).padStart(6, '0').slice(-6)};"></span>`
                : '';

            const href = 'bills.php?guid=' + encodeURIComponent(p.GUID);

            return `<tr class="pattern-row" data-guid="${escapeHtml(p.GUID)}">
                <td><a class="row-link" href="${escapeHtml(href)}">${dot}${escapeHtml(p.Name || '-')}</a></td>
                <td class="abbrev">${escapeHtml(p.Abbrev || '')}</td>
                <td class="num">${p.BillCount}</td>
                <td class="num"><b>${fmtNum(p.TotalUSD)}</b></td>
                <td class="num muted">${lastBill}</td>
                <td>${flags || '<span class="muted">—</span>'}</td>
            </tr>`;
        }

        // ---------- Detail view ----------
        async function loadPattern(guid) {
            try {
                const resp = await fetch('?pattern=1&guid=' + encodeURIComponent(guid), { cache: 'no-store' });
                if (resp.status === 401) { window.location.href = 'login.php'; return; }
                const data = await resp.json();
                if (!data || data.error) {
                    document.getElementById('detailName').textContent = 'خطأ';
                    document.getElementById('detailSub').textContent  = (data && data.error) || 'Pattern not found';
                    return;
                }

                const p = data.pattern;
                const meta = BILL_TYPES[p.BillType] || { icon: '📄', label: 'أخرى', color: '#B8863C' };

                document.getElementById('detailName').textContent = p.Name || '(بدون اسم)';
                document.getElementById('detailSub').textContent  =
                    meta.icon + ' ' + meta.label +
                    (p.Abbrev ? ' · الكود: ' + p.Abbrev : '');
                document.getElementById('detailStripe').style.background = meta.color;

                document.getElementById('kpiPatternValue').textContent = fmtNum(data.kpi.totalUSD) + ' USD';
                document.getElementById('kpiPatternBills').textContent = data.kpi.billCount;
                document.getElementById('kpiPatternLast').textContent  =
                    data.kpi.lastBillDate ? new Date(data.kpi.lastBillDate).toLocaleDateString('en-GB') : '—';

                function buildRows(rows) {
                    let html = '';
                    for (const [lbl, val] of rows) {
                        if (val === null || val === undefined || val === '' || val === 0) continue;
                        html += `<div class="info-row"><span class="lbl">${lbl}</span><span class="val">${escapeHtml(String(val))}</span></div>`;
                    }
                    return html || '<div class="empty" style="padding:12px 0;">لا توجد بيانات.</div>';
                }

                // Info card
                const infoRows = [
                    ['🏷️ الكود المختصر',  p.Abbrev],
                    ['📊 نوع النمط',       meta.label],
                ];
                document.getElementById('infoBody').innerHTML = buildRows(infoRows);

                // Defaults card
                const defaultsRows = [
                    ['🏬 المستودع',   p.DefaultStoreName ? `${p.DefaultStoreName} (${p.DefaultStoreNumber || ''})` : ''],
                    ['💱 العملة',      p.CurrencyName],
                    ['🛒 POS',         p.bPOSBill ? 'نعم' : ''],
                    ['🖨️ طباعة إيصال', p.bPrintReceipt ? 'نعم' : ''],
                    ['📊 باركود',      p.bBarCodeBill ? 'نعم' : ''],
                    ['💵 فاتورة نقدية', p.bCashBill ? 'نعم' : ''],
                    ['⚡ قيد تلقائي',  p.bAutoEntry ? 'نعم' : ''],
                    ['🚫 بدون قيد',    p.bNoEntry ? 'نعم' : ''],
                    ['⏸️ بدون ترحيل',  p.bNoPost ? 'نعم' : ''],
                    ['📝 قيد مختصر',   p.bShortEntry ? 'نعم' : ''],
                ];
                document.getElementById('defaultsBody').innerHTML = buildRows(defaultsRows);

                // Default accounts card
                const accRows = [
                    ['💵 حساب الفاتورة',       p.BillAccName],
                    ['💰 حساب الصندوق',         p.CashAccName],
                    ['➖ حساب الحسم',           p.DiscAccName],
                    ['➕ حساب الإضافي',         p.ExtraAccName],
                    ['📈 حساب الضريبة',         p.VATAccName],
                    ['📉 حساب الكلفة',          p.CostAccName],
                    ['📦 حساب المخزون',         p.StockAccName],
                    ['🎁 حساب الاضافات',          p.BonusAccName],
                    ['🎁 حساب الاضافات المقابل',  p.BonusContraAccName],
                    ['👤 حساب الزبون الافتراضي', p.CustAccName],
                    ['🏦 الحساب الرئيسي',        p.MainAccName],
                    ['🔄 حساب الرسوم العكسية',   p.RevChargesAccName],
                    ['🔄 مقابل الرسوم العكسية',  p.RevChargesContraAccName],
                    ['📊 حساب ضريبة الإنتاج',    p.ExciseAccName],
                    ['📊 مقابل ضريبة الإنتاج',   p.ExciseContraAccName],
                ];
                document.getElementById('accountsBody').innerHTML = buildRows(accRows);

                // Bills — first page comes from the detail response
                billsOffset = 0;
                billsHasMore = data.bills.length >= 50;
                renderBills(data.bills, true);
                document.getElementById('billsMoreBtn').style.display = billsHasMore ? 'block' : 'none';
                document.getElementById('billsCount').textContent =
                    data.kpi.billCount > 0 ? `(${data.kpi.billCount} فاتورة)` : '';
            } catch (e) {
                console.error(e);
            }
        }

        function renderBills(bills, reset) {
            const body = document.getElementById('billsBody');
            if (reset) body.innerHTML = '';

            if (!bills.length && reset) {
                body.innerHTML = '<tr><td colspan="5" class="empty">لا توجد فواتير لهذا النمط.</td></tr>';
                return;
            }

            const frag = document.createDocumentFragment();
            for (const bill of bills) {
                const cv   = parseFloat(bill.CurrencyVal) || 1;
                const net  = (parseFloat(bill.Net) || 0) / cv;
                const date = bill.Date ? new Date(bill.Date).toLocaleDateString('en-GB') : '-';
                const href = 'dashboard.php?bill=' + encodeURIComponent(bill.GUID);
                const tr = document.createElement('tr');
                tr.className = 'bill-click-row';
                tr.innerHTML = `
                    <td class="num"><a class="row-link" href="${escapeHtml(href)}">#${escapeHtml(bill.Number)}</a></td>
                    <td class="num">${date}</td>
                    <td>${escapeHtml(bill.Cust_Name || 'مناقلة')}</td>
                    <td>${payTypeLabel(bill.PayType)}</td>
                    <td class="num"><b>${fmtNum(net)}</b></td>
                `;
                frag.appendChild(tr);
            }
            body.appendChild(frag);
        }

        document.getElementById('billsMoreBtn').addEventListener('click', async () => {
            if (!billsHasMore || billsLoadingMore) return;
            billsLoadingMore = true;
            billsOffset += 50;   // first page had 50

            try {
                const resp = await fetch('?bills=1&guid=' + encodeURIComponent(detailGuid) + '&offset=' + billsOffset, { cache: 'no-store' });
                if (resp.status === 401) { window.location.href = 'login.php'; return; }
                const data = await resp.json();
                if (data && Array.isArray(data.bills)) {
                    renderBills(data.bills, false);
                    billsHasMore = !!data.hasMore;
                    document.getElementById('billsMoreBtn').style.display = billsHasMore ? 'block' : 'none';
                }
            } catch (e) {
                console.error(e);
            } finally {
                billsLoadingMore = false;
            }
        });

        // ---------- Helpers ----------
        const PAY_TYPES = { '0': 'نقدي', '1': 'آجل' };
        function payTypeLabel(v) {
            if (v === null || v === undefined || v === '') return '-';
            return PAY_TYPES[String(v).trim()] || ('نوع ' + v);
        }

        // ---------- Bootstrap ----------
        if (isDetail) {
            document.getElementById('listView').style.display   = 'none';
            document.getElementById('detailView').style.display = 'block';
            loadPattern(detailGuid);
                } else {
            // Wire up the tree search
            (function initTreeSearch() {
                const input = document.getElementById('treeSearch');
                const clear = document.getElementById('treeSearchClear');
                if (!input || !clear) return;

                let debounce = null;

                input.addEventListener('input', () => {
                    clearTimeout(debounce);
                    const hasText = input.value.length > 0;
                    clear.style.display = hasText ? 'block' : 'none';
                    debounce = setTimeout(() => {
                        treeSearchTerm = input.value;
                        renderTree();
                    }, 150);
                });

                input.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape') {
                        e.preventDefault();
                        input.value = '';
                        clearTimeout(debounce);
                        treeSearchTerm = '';
                        clear.style.display = 'none';
                        renderTree();
                        input.blur();
                    }
                });

                clear.addEventListener('click', () => {
                    input.value = '';
                    clearTimeout(debounce);
                    treeSearchTerm = '';
                    clear.style.display = 'none';
                    renderTree();
                    input.focus();
                });
            })();

            const saved = loadSavedFilter();
            if (saved) {
                currentFilter = saved;
                document.getElementById('dateFrom').value = saved.from;
                document.getElementById('dateTo').value   = saved.to;
                setActiveChip(null);
                loadPatterns();
            } else {
                applyPreset('month');   // sensible default for this page
            }
        }
    </script>
</body>
</html>