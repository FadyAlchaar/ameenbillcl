<?php
// products.php - top products and per-product sales history.
require_once 'config.php';
require_once 'auth.php';

$isApiRequest = isset($_GET['list']) || isset($_GET['product']);
requireLogin($isApiRequest);

// ─── API: top products for a date range ──────────────────────────
if (isset($_GET['list']) && $_GET['list'] == 1) {
    header('Content-Type: application/json');

    $fromRaw = $_GET['from'] ?? null;
    $toRaw   = $_GET['to']   ?? null;
    $sort    = $_GET['sort'] ?? 'revenue';       // revenue | qty | bills | name
    $dir     = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
    $limit   = max(10, min(500, (int)($_GET['limit'] ?? 50)));

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

    // Whitelisted ORDER BY — never interpolate user input.
    $sortMap = [
        'revenue' => 'RevenueUSD',
        'qty'     => 'TotalBaseQty',
        'bills'   => 'BillCount',
        'name'    => 'ItemName',
    ];
    $orderCol = $sortMap[$sort] ?? 'RevenueUSD';

    try {
        $pdo = getDBConnection();

        // Revenue is summed in USD:
        //   line_gross = (Qty / unityFactor) * Price     -- both in USD
        //   line_net   = line_gross - Discount + Extra   -- all in USD
        // Qty is summed in base units for a coherent total across mixed
        // sale units (a line sold by صندوق contributes its piece count).
        $sql = "SELECT TOP ($limit)
                    m.GUID,
                    m.Name AS ItemName,
                    m.Code,
                    m.Unity AS BaseUnit,
                    SUM(d.Qty) AS TotalBaseQty,
                    COUNT(DISTINCT d.ParentGUID) AS BillCount,
                    SUM(
                        (d.Qty / CASE d.Unity
                                    WHEN 2 THEN NULLIF(m.Unit2Fact, 0)
                                    WHEN 3 THEN NULLIF(m.Unit3Fact, 0)
                                    ELSE 1
                                 END) * d.Price
                        - d.Discount + d.Extra
                    ) AS RevenueUSD
                FROM bi000 d
                INNER JOIN bu000 b ON d.ParentGUID = b.GUID
                LEFT JOIN mt000 m ON d.MatGUID = m.GUID
                WHERE b.Date >= :fromStart AND b.Date < :toEnd
                  AND m.GUID IS NOT NULL
                GROUP BY m.GUID, m.Name, m.Code, m.Unity
                ORDER BY $orderCol $dir";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':fromStart' => $fromStart, ':toEnd' => $toEnd]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $products = [];
        foreach ($rows as $r) {
            $products[] = [
                'GUID'         => $r['GUID'],
                'ItemName'     => $r['ItemName'],
                'Code'         => $r['Code'],
                'BaseUnit'     => $r['BaseUnit'],
                'TotalBaseQty' => (float)$r['TotalBaseQty'],
                'BillCount'    => (int)$r['BillCount'],
                'RevenueUSD'   => (float)$r['RevenueUSD'],
            ];
        }

                // Overall summary for the KPI strip on top of the table
        $summarySql = "SELECT
                          COUNT(DISTINCT d.MatGUID) AS ItemCount,
                          COUNT(DISTINCT d.ParentGUID) AS BillCount,
                          SUM(
                              (d.Qty / CASE d.Unity
                                          WHEN 2 THEN NULLIF(m.Unit2Fact, 0)
                                          WHEN 3 THEN NULLIF(m.Unit3Fact, 0)
                                          ELSE 1
                                       END) * d.Price
                              - d.Discount + d.Extra
                          ) AS RevenueUSD
                       FROM bi000 d
                       INNER JOIN bu000 b ON d.ParentGUID = b.GUID
                       LEFT JOIN mt000 m ON d.MatGUID = m.GUID
                       WHERE b.Date >= :fromStart AND b.Date < :toEnd";
        $sStmt = $pdo->prepare($summarySql);
        $sStmt->execute([':fromStart' => $fromStart, ':toEnd' => $toEnd]);
        $sumRow = $sStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // Per-currency breakdown. The line's raw value is in USD (base
        // currency, as stored by Alameen in bi000.Price). Dividing by the
        // bill's CurrencyVal yields the amount the customer actually paid
        // in their own currency.
        $curSql = "SELECT
                       cur.Name AS CurrencyName,
                       ISNULL(SUM(
                           (d.Qty / CASE d.Unity
                                       WHEN 2 THEN NULLIF(m.Unit2Fact, 0)
                                       WHEN 3 THEN NULLIF(m.Unit3Fact, 0)
                                       ELSE 1
                                    END) * d.Price
                           - d.Discount + d.Extra
                       ), 0) AS NetUSD,
                       ISNULL(SUM(
                           ((d.Qty / CASE d.Unity
                                        WHEN 2 THEN NULLIF(m.Unit2Fact, 0)
                                        WHEN 3 THEN NULLIF(m.Unit3Fact, 0)
                                        ELSE 1
                                     END) * d.Price
                           - d.Discount + d.Extra) / NULLIF(b.CurrencyVal, 0)
                       ), 0) AS NetOriginal
                   FROM bi000 d
                   INNER JOIN bu000 b ON d.ParentGUID = b.GUID
                   LEFT JOIN mt000 m ON d.MatGUID = m.GUID
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
            'products'   => $products,
            'currencies' => $currencies,
            'summary'    => [
                'itemCount'  => (int)($sumRow['ItemCount']  ?? 0),
                'billCount'  => (int)($sumRow['BillCount']  ?? 0),
                'revenueUSD' => (float)($sumRow['RevenueUSD'] ?? 0),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        jsonFail($e, 500, 'Could not load products.');
    }
    exit;
}

// ─── API: single product + last 20 sales ─────────────────────────
if (isset($_GET['product']) && $_GET['product'] == 1) {
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
            SELECT
                m.Number, m.Name, m.Code, m.BarCode, m.CodedCode, m.Unity, m.Spec, m.Qty,
                m.Whole, m.Half, m.Retail, m.EndUser, m.Export, m.Vendor,
                m.LastPrice, m.BonusOne, m.LastPriceDate,
                m.Unit2, m.Unit2Fact, m.Unit3, m.Unit3Fact, m.Unit2FactFlag, m.Unit3FactFlag,
                m.BarCode2, m.BarCode3,
                m.Whole2, m.Half2, m.Retail2, m.EndUser2, m.Export2, m.Vendor2, m.MaxPrice2, m.LastPrice2,
                m.Whole3, m.Half3, m.Retail3, m.EndUser3, m.Export3, m.Vendor3, m.MaxPrice3, m.LastPrice3,
                m.GUID, m.GroupGUID, m.CurrencyGUID, m.DefUnit,
                m.LastPriceCurVal, m.LastPriceWithDiscAndExtra, m.VAT,
                g.Name AS GroupName,
                g.Code AS GroupCode,
                cur.Name AS CurrencyName
            FROM mt000 m
            LEFT JOIN gr000 g   ON m.GroupGUID   = g.GUID
            LEFT JOIN my000 cur ON m.CurrencyGUID = cur.GUID
            WHERE m.GUID = :guid
        ");
        $stmt->execute([':guid' => $guid]);
        $prod = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$prod) {
            http_response_code(404);
            echo json_encode(['error' => 'Product not found']);
            exit;
        }

        $salesStmt = $pdo->prepare("
            SELECT TOP 20
                b.Number AS BillNumber,
                b.Date   AS BillDate,
                b.Cust_Name,
                d.Qty, d.Unity, d.Price, d.Discount, d.Extra, d.CurrencyVal,
                cur.Name AS CurrencyName
            FROM bi000 d
            INNER JOIN bu000 b ON d.ParentGUID = b.GUID
            LEFT JOIN my000 cur ON b.CurrencyGUID = cur.GUID
            WHERE d.MatGUID = :matGuid
            ORDER BY b.CreateDate DESC
        ");
        $salesStmt->execute([':matGuid' => $guid]);
        $sales = $salesStmt->fetchAll(PDO::FETCH_ASSOC);

        $u2f = (float)($prod['Unit2Fact'] ?? 0);
        $u3f = (float)($prod['Unit3Fact'] ?? 0);

        foreach ($sales as &$s) {
            $s['BillDate']    = isoStamp($s['BillDate'] ?? null);
            $s['Qty']         = (float)$s['Qty'];
            $s['Price']       = (float)$s['Price'];
            $s['Discount']    = (float)$s['Discount'];
            $s['Extra']       = (float)$s['Extra'];
            $s['CurrencyVal'] = (float)$s['CurrencyVal'];

            // Display unit and display quantity for this line
            $u = (int)$s['Unity'];
            if ($u === 2 && $u2f > 0) {
                $s['DisplayQty']  = $s['Qty'] / $u2f;
                $s['DisplayUnit'] = $prod['Unit2'] ?: 'وحدة 2';
            } elseif ($u === 3 && $u3f > 0) {
                $s['DisplayQty']  = $s['Qty'] / $u3f;
                $s['DisplayUnit'] = $prod['Unit3'] ?: 'وحدة 3';
            } else {
                $s['DisplayQty']  = $s['Qty'];
                $s['DisplayUnit'] = $prod['Unity'] ?: 'وحدة';
            }

            // Line net in USD (the raw storage currency)
            $s['LineNetUSD'] = $s['DisplayQty'] * $s['Price'] - $s['Discount'] + $s['Extra'];
        }
        unset($s);

        echo json_encode([
            'product' => $prod,
            'sales'   => $sales,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        jsonFail($e, 500, 'Could not load product.');
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الأصناف - QuantuSphere Web</title>
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

                /* Per-currency breakdown listed under the revenue headline. Empty
           when only one currency was used, so the card stays compact. */
        .kpi-currency-list {
            margin-top: 10px;
            padding-top: 8px;
            border-top: 1px dashed var(--border);
        }
        .kpi-currency-list:empty { display: none; }
        .kc-label {
            font-size: 0.68rem;
            color: var(--text-muted);
            font-weight: 700;
            margin-bottom: 4px;
        }
        .kc-row {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            padding: 2px 0;
            font-size: 0.72rem;
        }
        .kc-name { color: var(--text-muted); }
        .kc-amount {
            font-family: var(--font-num);
            font-variant-numeric: tabular-nums;
            color: var(--text);
            font-weight: 700;
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

        th.sortable { cursor: pointer; user-select: none; white-space: nowrap; }
        th.sortable:hover { color: var(--primary-dark); }
        th.sortable.sort-active { color: var(--primary-dark); }
        .sort-ind { display: inline-block; width: 12px; margin-right: 4px; color: var(--text-muted); font-size: 0.7rem; }
        th.sortable.sort-active .sort-ind { color: var(--primary-dark); font-weight: 900; }

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

        .cust-header {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 16px 20px; margin-bottom: 16px;
        }
        .cust-header h2 { margin: 0 0 4px; font-size: 1.3rem; font-weight: 900; }
        .cust-header .sub { color: var(--text-muted); font-size: 0.85rem; }

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
                <h1><span class="tile-icon"><i class="ti ti-package"></i></span> لوحة المواد</h1>
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
                    <div class="kpi-label">📦 أصناف مباعة</div>
                    <div class="kpi-value" id="kpiItems">—</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">📄 عدد الفواتير</div>
                    <div class="kpi-value" id="kpiBills">—</div>
                </div>
            </div>

            <div class="section">
                <h2 class="section-title">🏆 الأصناف الأعلى مبيعاً</h2>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th class="sortable list-sortable" data-sort="name">الصنف <span class="sort-ind"></span></th>
                                <th class="sortable list-sortable" data-sort="qty">الكمية المباعة <span class="sort-ind"></span></th>
                                <th class="sortable list-sortable" data-sort="bills">عدد الفواتير <span class="sort-ind"></span></th>
                                <th class="sortable list-sortable" data-sort="revenue">الإيرادات (USD) <span class="sort-ind"></span></th>
                            </tr>
                        </thead>
                        <tbody id="listBody">
                            <tr><td colspan="5" class="loading">جاري التحميل...</td></tr>
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
                    <h3>📋 معلومات أساسية</h3>
                    <div id="infoBody"></div>
                </div>
                <div class="info-card">
                    <h3>📦 الوحدات والكمية</h3>
                    <div id="unitsBody"></div>
                </div>
            </div>

            <div class="section">
                <h2 class="section-title">💵 الأسعار</h2>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>الوحدة</th>
                                <th>عامل التحويل <span class="muted" style="font-size:0.7rem;font-weight:600;">(بالنسبة للوحدة الأساسية)</span></th>
                                <th>جملة</th>
                                <th>نصف جملة</th>
                                <th>مفرق</th>
                                <th>مستهلك</th>
                                <th>تصدير</th>
                                <th>حد أقصى</th>
                                <th>آخر سعر</th>
                            </tr>
                        </thead>
                        <tbody id="pricesBody"></tbody>
                    </table>
                </div>
            </div>

            <div class="section">
                <h2 class="section-title">
                    📄 آخر المبيعات
                    <span id="salesSummary" class="muted" style="font-size:0.8rem;font-weight:600;margin-right:8px;"></span>
                </h2>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th class="sortable sales-sortable" data-sort="BillNumber">رقم الفاتورة <span class="sort-ind"></span></th>
                                <th class="sortable sales-sortable" data-sort="BillDate">التاريخ <span class="sort-ind"></span></th>
                                <th class="sortable sales-sortable" data-sort="Cust_Name">الزبون <span class="sort-ind"></span></th>
                                <th class="sortable sales-sortable" data-sort="DisplayQty">الكمية <span class="sort-ind"></span></th>
                                <th class="sortable sales-sortable" data-sort="DisplayUnit">الوحدة <span class="sort-ind"></span></th>
                                <th class="sortable sales-sortable" data-sort="Price">سعر الوحدة <span class="sort-ind"></span></th>
                                <th class="sortable sales-sortable" data-sort="Discount">الخصم <span class="sort-ind"></span></th>
                                <th class="sortable sales-sortable" data-sort="Extra">إضافي <span class="sort-ind"></span></th>
                                <th class="sortable sales-sortable" data-sort="LineNetUSD">الصافي (USD) <span class="sort-ind"></span></th>
                            </tr>
                        </thead>
                        <tbody id="salesBody">
                            <tr><td colspan="9" class="loading">جاري التحميل...</td></tr>
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
        function fmtQty(v) {
            const n = parseFloat(v);
            if (!isFinite(n)) return '0';
            // Whole numbers without decimals; fractional with 2
            return Number.isInteger(n) ? _numFmt.format(n).replace(/\.00$/, '')
                                       : n.toFixed(2);
        }
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

        // Persist the chosen date range across navigations (e.g. opening a
        // product detail and pressing Back). Cleared only by "إعادة تعيين".
        const FILTER_KEY = 'productsDateRange';

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
            loadProducts();
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
            loadProducts();
        });

        document.getElementById('resetFilterBtn').addEventListener('click', () => {
            clearSavedFilter();
            document.getElementById('dateFrom').value = '';
            document.getElementById('dateTo').value   = '';
            applyPreset('today');   // back to the default view
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
                loadProducts();
            });
        });

        async function loadProducts() {
            const params = new URLSearchParams({ list: '1' });
            if (rangeFrom) params.set('from', rangeFrom);
            if (rangeTo)   params.set('to',   rangeTo);
            params.set('sort', listSort.key);
            params.set('dir',  listSort.dir);

            const body = document.getElementById('listBody');
            body.innerHTML = '<tr><td colspan="5" class="loading">جاري التحميل...</td></tr>';

            try {
                const resp = await fetch('?' + params.toString(), { cache: 'no-store' });
                if (resp.status === 401) { window.location.href = 'login.php'; return; }
                const data = await resp.json();
                if (!data || data.error) {
                    body.innerHTML = `<tr><td colspan="5" class="loading">خطأ: ${escapeHtml(data.error || '')}</td></tr>`;
                    return;
                }

                document.getElementById('kpiRevenue').textContent = fmtNum(data.summary.revenueUSD) + ' USD';
                document.getElementById('kpiItems').textContent   = data.summary.itemCount;
                document.getElementById('kpiBills').textContent   = data.summary.billCount;

                renderKpiCurrencies(data.currencies);

                if (!data.products.length) {
                    body.innerHTML = '<tr><td colspan="5" class="empty">لا توجد بيانات في هذه الفترة.</td></tr>';
                    return;
                }

                let html = '';
                data.products.forEach((p, i) => {
                    html += `<tr class="clickable" onclick="window.location.href='products.php?guid=${encodeURIComponent(p.GUID)}'">
                        <td class="num muted">${i + 1}</td>
                        <td>${escapeHtml(p.ItemName || '-')}</td>
                        <td class="num">${fmtQty(p.TotalBaseQty)} <span class="muted" style="font-size:0.75rem;">${escapeHtml(p.BaseUnit || '')}</span></td>
                        <td class="num">${p.BillCount}</td>
                        <td class="num"><b>${fmtNum(p.RevenueUSD)}</b></td>
                    </tr>`;
                });
                body.innerHTML = html;
                } catch (e) {
                console.error(e);
                body.innerHTML = `<tr><td colspan="5" class="loading">خطأ في الشبكة: ${escapeHtml(e.message)}</td></tr>`;
            }
        }

        function renderKpiCurrencies(currencies) {
            const el = document.getElementById('kpiCurrencyList');
            if (!el) return;

            if (!Array.isArray(currencies) || currencies.length === 0) {
                el.innerHTML = '';
                return;
            }

            // If everything came in as the base currency, the breakdown just
            // duplicates the headline — hide it and keep the card compact.
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

        
                // ---------- Sales table: sorting ----------
        let currentSales = [];
        let salesSort = { key: 'BillDate', dir: 'desc' };

        function renderSales() {
            const sb = document.getElementById('salesBody');
            if (!currentSales.length) {
                sb.innerHTML = '<tr><td colspan="9" class="empty">لا توجد مبيعات لهذا الصنف.</td></tr>';
                return;
            }

            const dir = salesSort.dir === 'asc' ? 1 : -1;
            const k   = salesSort.key;

            const sorted = currentSales.slice().sort((a, b) => {
                let va, vb;
                if (k === 'BillDate') {
                    va = new Date(a.BillDate || 0).getTime();
                    vb = new Date(b.BillDate || 0).getTime();
                } else if (k === 'BillNumber') {
                    va = parseFloat(a.BillNumber) || 0;
                    vb = parseFloat(b.BillNumber) || 0;
                } else if (['DisplayQty','Price','Discount','Extra','LineNetUSD'].includes(k)) {
                    va = parseFloat(a[k]) || 0;
                    vb = parseFloat(b[k]) || 0;
                } else {
                    va = String(a[k] ?? '');
                    vb = String(b[k] ?? '');
                }
                if (va < vb) return -1 * dir;
                if (va > vb) return  1 * dir;
                return 0;
            });

            let sh = '';
            for (const s of sorted) {
                const date = s.BillDate ? new Date(s.BillDate).toLocaleDateString('en-GB') : '-';
                sh += `<tr>
                    <td class="num">#${escapeHtml(s.BillNumber)}</td>
                    <td class="num">${date}</td>
                    <td>${escapeHtml(s.Cust_Name || 'مناقلة')}</td>
                    <td class="num">${fmtQty(s.DisplayQty)}</td>
                    <td>${escapeHtml(s.DisplayUnit || '-')}</td>
                    <td class="num">${fmtNum(s.Price)}</td>
                    <td class="num">${fmtNum(s.Discount)}</td>
                    <td class="num">${fmtNum(s.Extra)}</td>
                    <td class="num"><b>${fmtNum(s.LineNetUSD)}</b></td>
                </tr>`;
            }
            sb.innerHTML = sh;
        }

        function updateSalesSortIndicators() {
            document.querySelectorAll('#detailView th.sales-sortable').forEach(th => {
                const ind = th.querySelector('.sort-ind');
                if (th.dataset.sort === salesSort.key) {
                    th.classList.add('sort-active');
                    if (ind) ind.textContent = salesSort.dir === 'asc' ? '▲' : '▼';
                } else {
                    th.classList.remove('sort-active');
                    if (ind) ind.textContent = '';
                }
            });
        }

        document.querySelectorAll('#detailView th.sales-sortable').forEach(th => {
            th.addEventListener('click', () => {
                const key = th.dataset.sort;
                if (salesSort.key === key) {
                    salesSort.dir = salesSort.dir === 'asc' ? 'desc' : 'asc';
                } else {
                    salesSort.key = key;
                    salesSort.dir = 'asc';
                }
                updateSalesSortIndicators();
                renderSales();
            });
        });

        // ---------- DETAIL VIEW ----------
        async function loadProduct(guid) {
            try {
                const resp = await fetch('?product=1&guid=' + encodeURIComponent(guid), { cache: 'no-store' });
                if (resp.status === 401) { window.location.href = 'login.php'; return; }
                const data = await resp.json();
                if (!data || data.error) {
                    document.getElementById('detailName').textContent = 'خطأ';
                    document.getElementById('detailSub').textContent  = data.error || 'Product not found';
                    return;
                }

                const p = data.product;

                document.getElementById('detailName').textContent = p.Name || '(بدون اسم)';
                document.getElementById('detailSub').textContent =
                    'الرمز: ' + (p.Code || '-')
                    + (p.LatinName ? ' · ' + p.LatinName : '')
                    + (p.BarCode ? ' · الباركود: ' + p.BarCode : '');

                                // ---------- Row builder for info cards ----------
                function buildInfoRows(rows) {
                    let html = '';
                    for (const [lbl, val] of rows) {
                        if (val === null || val === undefined || val === '' || val === 0) continue;
                        html += `<div class="info-row"><span class="lbl">${lbl}</span><span class="val">${escapeHtml(String(val))}</span></div>`;
                    }
                    return html || '<div class="empty" style="padding:12px 0;">لا توجد بيانات.</div>';
                }

                // DefUnit is a numeric index: 1=Unity, 2=Unit2, 3=Unit3.
                // Translate it to the actual unit name from the matching column.
                function defUnitName(m) {
                    const d = String(m.DefUnit ?? '1').trim();
                    if (d === '2') return m.Unit2 || '(الوحدة الثانية)';
                    if (d === '3') return m.Unit3 || '(الوحدة الثالثة)';
                    return m.Unity || '(الوحدة الأساسية)';
                }

                const num0 = v => { const n = parseFloat(v); return isFinite(n) ? n : 0; };
                const fmtPrice = v => num0(v) > 0 ? fmtNum(v) : '—';

                // ---------- Basic info card ----------
                document.getElementById('infoBody').innerHTML = buildInfoRows([
                    ['# الرقم',           p.Number],
                    ['🏷️ الكود',          p.Code],
                    ['🏷️ كود إضافي',     p.CodedCode],
                    ['🔖 الباركود',       p.BarCode],
                    ['🔖 الباركود 2',     p.BarCode2],
                    ['🔖 الباركود 3',     p.BarCode3],
                    ['📝 المواصفات',      p.Spec],
                    ['🏷️ المجموعة',       p.GroupName ? `${p.GroupName}${p.GroupCode ? ' (' + p.GroupCode + ')' : ''}` : ''],
                    ['💱 العملة',         p.CurrencyName],
                    ['💰 آخر سعر بتاريخ', p.LastPriceDate ? new Date(p.LastPriceDate).toLocaleDateString('en-GB') : ''],
                ]);

                // ---------- Units card ----------
                // Factors are always relative to the BASE unit (Unity / column 1),
                // which is Alameen's storage convention regardless of DefUnit.
                const baseUnitName = p.Unity || 'الوحدة الأساسية';
                document.getElementById('unitsBody').innerHTML = buildInfoRows([
                    ['📦 الوحدة الأساسية',    p.Unity],
                    ['📦 الوحدة الثانية',     p.Unit2 ? `${p.Unit2}  (1 ${p.Unit2} = ${p.Unit2Fact || 1} ${baseUnitName})` : ''],
                    ['📦 الوحدة الثالثة',     p.Unit3 ? `${p.Unit3}  (1 ${p.Unit3} = ${p.Unit3Fact || 1} ${baseUnitName})` : ''],
                    ['⭐ الوحدة الافتراضية',   defUnitName(p)],
                    ['🔢 الكمية بالمخزون',    p.Qty ? `${fmtNum(p.Qty)} ${baseUnitName}` : ''],
                    ['✅ تفعيل الوحدة 2',     p.Unit2FactFlag ? 'نعم' : 'لا'],
                    ['✅ تفعيل الوحدة 3',     p.Unit3FactFlag ? 'نعم' : 'لا'],
                ]);

                // ---------- Prices table: one row per unit ----------
                const priceRows = [
                    {
                        unit: p.Unity || 'أساسية',
                        factor: '1',
                        whole: p.Whole, half: p.Half, retail: p.Retail,
                        enduser: p.EndUser, export: p.Export,
                        max: '—', last: p.LastPrice,
                    },
                ];
                if (num0(p.Unit2Fact) > 0 || p.Unit2) {
                    priceRows.push({
                        unit: p.Unit2 || 'الوحدة 2',
                        factor: p.Unit2Fact || '—',
                        whole: p.Whole2, half: p.Half2, retail: p.Retail2,
                        enduser: p.EndUser2, export: p.Export2,
                        max: p.MaxPrice2, last: p.LastPrice2,
                    });
                }
                if (num0(p.Unit3Fact) > 0 || p.Unit3) {
                    priceRows.push({
                        unit: p.Unit3 || 'الوحدة 3',
                        factor: p.Unit3Fact || '—',
                        whole: p.Whole3, half: p.Half3, retail: p.Retail3,
                        enduser: p.EndUser3, export: p.Export3,
                        max: p.MaxPrice3, last: p.LastPrice3,
                    });
                }

                let phtml = '';
                for (const r of priceRows) {
                    phtml += `<tr>
                        <td>${escapeHtml(r.unit)}</td>
                        <td class="num">${escapeHtml(String(r.factor))}</td>
                        <td class="num">${fmtPrice(r.whole)}</td>
                        <td class="num">${fmtPrice(r.half)}</td>
                        <td class="num">${fmtPrice(r.retail)}</td>
                        <td class="num">${fmtPrice(r.enduser)}</td>
                        <td class="num">${fmtPrice(r.export)}</td>
                        <td class="num">${r.max === '—' ? '—' : fmtPrice(r.max)}</td>
                        <td class="num"><b>${fmtPrice(r.last)}</b></td>
                    </tr>`;
                }
                document.getElementById('pricesBody').innerHTML = phtml;

                                // Sales table — store, then render with sorting
                currentSales = Array.isArray(data.sales) ? data.sales.slice() : [];
                salesSort = { key: 'BillDate', dir: 'desc' };   // reset to default on load
                updateSalesSortIndicators();
                renderSales();
                document.getElementById('salesSummary').textContent =
                    currentSales.length > 0 ? `(آخر ${currentSales.length} عملية)` : '';
            } catch (e) {
                console.error(e);
            }
        }

                // ---------- Bootstrap ----------
        if (isDetail) {
            document.getElementById('listView').style.display   = 'none';
            document.getElementById('detailView').style.display = 'block';
            loadProduct(detailGuid);
        } else {
            updateListSortIndicators();

            const saved = loadSavedFilter();
            if (saved) {
                // Restore whatever the user last chose, so navigating back
                // from a product detail keeps the same date window.
                rangeFrom = saved.from;
                rangeTo   = saved.to;
                document.getElementById('dateFrom').value = rangeFrom;
                document.getElementById('dateTo').value   = rangeTo;
                setActiveChip(null);
                loadProducts();
            } else {
                applyPreset('today');
            }
        }
    </script>
</body>
</html>