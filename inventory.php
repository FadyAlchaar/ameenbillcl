<?php
// inventory.php - current stock levels per material and per warehouse.
// Source: ms000 (snapshot, one row per material+store), mt000 (material
// master), st000 (warehouse master), gr000 (groups).
require_once 'config.php';
require_once 'auth.php';

$isApiRequest = isset($_GET['list']) || isset($_GET['material']) || isset($_GET['kpi']);
requireLogin($isApiRequest);

// ─── KPI summary ─────────────────────────────────────────────────
if (isset($_GET['kpi']) && $_GET['kpi'] == 1) {
    header('Content-Type: application/json');
    try {
        $pdo = getDBConnection();

        $sql = "SELECT
                    (SELECT COUNT(DISTINCT MatGUID) FROM ms000 WHERE Qty > 0) AS SkuCount,
                    (SELECT ISNULL(SUM(Qty), 0) FROM ms000 WHERE Qty > 0)      AS TotalPieces,
                    (SELECT COUNT(DISTINCT StoreGUID) FROM ms000 WHERE Qty > 0) AS StoreCount,
                    (SELECT COUNT(*) FROM mt000 m
                       LEFT JOIN (SELECT MatGUID, SUM(Qty) AS s FROM ms000 GROUP BY MatGUID) x
                              ON m.GUID = x.MatGUID
                       WHERE m.Low > 0 AND ISNULL(x.s, 0) <= m.Low) AS LowStockCount,
                    (SELECT ISNULL(SUM(ms.Qty * ISNULL(m.LastPrice, 0)), 0)
                       FROM ms000 ms LEFT JOIN mt000 m ON ms.MatGUID = m.GUID
                       WHERE ms.Qty > 0) AS StockValueUSD";

        $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: [];

        echo json_encode([
            'skus'         => (int)($row['SkuCount'] ?? 0),
            'totalPieces'  => (float)($row['TotalPieces'] ?? 0),
            'stores'       => (int)($row['StoreCount'] ?? 0),
            'lowStock'     => (int)($row['LowStockCount'] ?? 0),
            'stockValueUSD'=> (float)($row['StockValueUSD'] ?? 0),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        jsonFail($e, 500, 'Could not load inventory KPI.');
    }
    exit;
}

// ─── Warehouse + group dropdowns (one-time) ──────────────────────
if (isset($_GET['refs']) && $_GET['refs'] == 1) {
    header('Content-Type: application/json');
    try {
        $pdo = getDBConnection();

        $stores = $pdo->query("
            SELECT s.GUID, s.Number, s.Name
            FROM st000 s
            WHERE EXISTS (SELECT 1 FROM ms000 ms WHERE ms.StoreGUID = s.GUID AND ms.Qty > 0)
            ORDER BY CAST(s.Number AS INT)
        ")->fetchAll(PDO::FETCH_ASSOC);

        $groups = $pdo->query("
            SELECT g.GUID, g.Code, g.Name
            FROM gr000 g
            WHERE EXISTS (SELECT 1 FROM mt000 m WHERE m.GroupGUID = g.GUID)
            ORDER BY g.Name
        ")->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['stores' => $stores, 'groups' => $groups], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        jsonFail($e, 500, 'Could not load references.');
    }
    exit;
}

// ─── List: paginated, filterable, sortable ───────────────────────
if (isset($_GET['list']) && $_GET['list'] == 1) {
    header('Content-Type: application/json');

    $view        = $_GET['view']        ?? 'material';   // 'material' | 'group' | 'store'
    $storeGuid   = $_GET['store']       ?? '';
    $groupGuid   = $_GET['group']       ?? '';
    $search      = isset($_GET['search']) && trim($_GET['search']) !== '' ? trim($_GET['search']) : null;
    $lowOnly     = isset($_GET['lowOnly'])   && $_GET['lowOnly'] == 1;
    $offset      = max(0, (int)($_GET['offset'] ?? 0));
    $limit       = 200;

    $sort    = $_GET['sort'] ?? 'name';
    $dir     = ($_GET['dir'] ?? 'asc') === 'asc' ? 'ASC' : 'DESC';

    try {
        $pdo = getDBConnection();

        $where  = [];
        $params = [];

        if ($storeGuid !== '') {
            $where[] = "ms.StoreGUID = :storeGuid";
            $params[':storeGuid'] = $storeGuid;
        }
        if ($groupGuid !== '') {
            $where[] = "m.GroupGUID = :groupGuid";
            $params[':groupGuid'] = $groupGuid;
        }
        if ($search !== null) {
            $where[] = "(m.Name LIKE :s1 OR m.Code LIKE :s2 OR m.BarCode LIKE :s3)";
            $like = '%' . $search . '%';
            $params[':s1'] = $like;
            $params[':s2'] = $like;
            $params[':s3'] = $like;
        }
        $whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

        // Per-view sort map. Uses fully-qualified columns (not aliases) so
        // it works safely with GROUP BY, ORDER BY and OFFSET/FETCH.
        if ($view === 'group') {
            $sortMap = [
                'name'  => 'g.Name',
                'code'  => 'g.Code',
                'qty'   => 'SUM(ms.Qty)',
                'value' => 'SUM(ms.Qty * ISNULL(m.LastPrice, 0))',
            ];
            $orderCol = $sortMap[$sort] ?? 'g.Name';

            // No SELECT TOP — SQL Server rejects TOP together with OFFSET/FETCH.
            $sql = "SELECT
                        g.GUID  AS GroupGUID,
                        g.Name  AS GroupName,
                        g.Code  AS GroupCode,
                        COUNT(DISTINCT m.GUID) AS MaterialCount,
                        SUM(ms.Qty) AS TotalQty,
                        SUM(ms.Qty * ISNULL(m.LastPrice, 0)) AS ValueUSD
                    FROM ms000 ms
                    INNER JOIN mt000 m ON ms.MatGUID = m.GUID
                    INNER JOIN gr000 g ON m.GroupGUID = g.GUID
                    $whereSql
                    GROUP BY g.GUID, g.Name, g.Code
                    HAVING SUM(ms.Qty) > 0
                    ORDER BY $orderCol $dir
                    OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY";

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
                $items[] = [
                    'GroupGUID'     => $r['GroupGUID'],
                    'GroupName'     => $r['GroupName'],
                    'GroupCode'     => $r['GroupCode'],
                    'MaterialCount' => (int)$r['MaterialCount'],
                    'TotalQty'      => (float)$r['TotalQty'],
                    'ValueUSD'      => (float)$r['ValueUSD'],
                ];
            }
            echo json_encode(
                ['items' => $items, 'hasMore' => $hasMore, 'view' => 'group'],
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );
            exit;
        }

        if ($view === 'store') {
            $sortMap = [
                'name'   => 'm.Name',
                'code'   => 'm.Code',
                'qty'    => 'ms.Qty',
                'value'  => '(ms.Qty * ISNULL(m.LastPrice, 0))',
                'store'  => 's.Name',
            ];
            $orderCol = $sortMap[$sort] ?? 'm.Name';

            // No SELECT TOP — same reason as group view above.
            $sql = "SELECT
                        m.GUID           AS MatGUID,
                        m.Name           AS ItemName,
                        m.Code           AS Code,
                        m.BarCode        AS BarCode,
                        m.Unity          AS Unit,
                        m.Low            AS LowLimit,
                        m.LastPrice      AS LastPrice,
                        ms.StoreGUID     AS StoreGUID,
                        s.Name           AS StoreName,
                        s.Number         AS StoreNumber,
                        ms.Qty           AS TotalQty,
                        (ms.Qty * ISNULL(m.LastPrice, 0)) AS ValueUSD,
                        g.Name           AS GroupName
                    FROM ms000 ms
                    INNER JOIN mt000 m ON ms.MatGUID = m.GUID
                    LEFT JOIN  st000 s ON ms.StoreGUID = s.GUID
                    LEFT JOIN  gr000 g ON m.GroupGUID = g.GUID
                    $whereSql
                    ORDER BY $orderCol $dir
                    OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY";

            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) $stmt->bindValue($k, $v);
            $stmt->bindValue(':offset', $offset,    PDO::PARAM_INT);
            $stmt->bindValue(':limit',  $limit + 1, PDO::PARAM_INT);
            $stmt->execute();

            $rows    = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $hasMore = count($rows) > $limit;
            if ($hasMore) array_pop($rows);

            if ($lowOnly) {
                $rows = array_values(array_filter($rows, function ($r) {
                    return (float)$r['LowLimit'] > 0 && (float)$r['TotalQty'] <= (float)$r['LowLimit'];
                }));
            }

            $items = [];
            foreach ($rows as $r) {
                $qty   = (float)$r['TotalQty'];
                $low   = (float)($r['LowLimit'] ?? 0);
                $items[] = [
                    'MatGUID'     => $r['MatGUID'],
                    'ItemName'    => $r['ItemName'],
                    'Code'        => $r['Code'],
                    'BarCode'     => $r['BarCode'],
                    'Unit'        => $r['Unit'],
                    'LowLimit'    => $low,
                    'LastPrice'   => (float)$r['LastPrice'],
                    'TotalQty'    => $qty,
                    'StoreName'   => $r['StoreName'],
                    'StoreNumber' => $r['StoreNumber'],
                    'StoreGUID'   => $r['StoreGUID'],
                    'ValueUSD'    => (float)$r['ValueUSD'],
                    'GroupName'   => $r['GroupName'],
                    'IsLow'       => $low > 0 && $qty <= $low,
                ];
            }
            echo json_encode(
                ['items' => $items, 'hasMore' => $hasMore, 'view' => 'store'],
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );
            exit;
        }

        // Default: material view
        $sortMap = [
            'name'  => 'm.Name',
            'code'  => 'm.Code',
            'qty'   => 'SUM(ms.Qty)',
            'value' => 'SUM(ms.Qty * ISNULL(m.LastPrice, 0))',
        ];
        $orderCol = $sortMap[$sort] ?? 'm.Name';

        $sql = "SELECT
                    m.GUID           AS MatGUID,
                    m.Name           AS ItemName,
                    m.Code           AS Code,
                    m.BarCode        AS BarCode,
                    m.Unity          AS Unit,
                    m.Low            AS LowLimit,
                    m.LastPrice      AS LastPrice,
                    SUM(ms.Qty)      AS TotalQty,
                    COUNT(DISTINCT ms.StoreGUID) AS StoreCount,
                    SUM(ms.Qty * ISNULL(m.LastPrice, 0)) AS ValueUSD,
                    g.Name           AS GroupName
                FROM ms000 ms
                INNER JOIN mt000 m ON ms.MatGUID = m.GUID
                LEFT JOIN  gr000 g ON m.GroupGUID = g.GUID
                $whereSql
                GROUP BY m.GUID, m.Name, m.Code, m.BarCode, m.Unity,
                         m.Low, m.LastPrice, g.Name
                HAVING SUM(ms.Qty) > 0
                ORDER BY $orderCol $dir
                OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':offset', $offset,    PDO::PARAM_INT);
        $stmt->bindValue(':limit',  $limit + 1, PDO::PARAM_INT);
        $stmt->execute();

        $rows    = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);

        if ($lowOnly) {
            $rows = array_values(array_filter($rows, function ($r) {
                return (float)$r['LowLimit'] > 0 && (float)$r['TotalQty'] <= (float)$r['LowLimit'];
            }));
        }

        $items = [];
        foreach ($rows as $r) {
            $qty   = (float)$r['TotalQty'];
            $low   = (float)($r['LowLimit'] ?? 0);
            $items[] = [
                'MatGUID'    => $r['MatGUID'],
                'ItemName'   => $r['ItemName'],
                'Code'       => $r['Code'],
                'BarCode'    => $r['BarCode'],
                'Unit'       => $r['Unit'],
                'LowLimit'   => $low,
                'LastPrice'  => (float)$r['LastPrice'],
                'TotalQty'   => $qty,
                'StoreCount' => (int)$r['StoreCount'],
                'ValueUSD'   => (float)$r['ValueUSD'],
                'GroupName'  => $r['GroupName'],
                'IsLow'      => $low > 0 && $qty <= $low,
            ];
        }
        echo json_encode(
            ['items' => $items, 'hasMore' => $hasMore, 'view' => 'material'],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    } catch (Throwable $e) {
        jsonFail($e, 500, 'Could not load inventory.');
    }
    exit;
}

// ─── Detail: one material + per-warehouse breakdown ──────────────
if (isset($_GET['material']) && $_GET['material'] == 1 && isset($_GET['guid'])) {
    header('Content-Type: application/json');
    $guid = $_GET['guid'];
    if (!preg_match('/^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/', $guid)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid GUID']);
        exit;
    }

    try {
        $pdo = getDBConnection();

        // Material master
        $mStmt = $pdo->prepare("
            SELECT m.GUID, m.Number, m.Name, m.Code, m.BarCode, m.Unity,
                   m.Unit2, m.Unit2Fact, m.Unit3, m.Unit3Fact,
                   m.Low, m.High, m.Whole, m.Retail, m.EndUser,
                   m.LastPrice, m.AvgPrice, m.Qty AS MasterQty,
                   g.Name AS GroupName, g.Code AS GroupCode
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

        // Per-store breakdown
        $sStmt = $pdo->prepare("
            SELECT s.GUID AS StoreGUID, s.Number AS StoreNumber, s.Name AS StoreName,
                   ms.Qty, ms.Book
            FROM ms000 ms
            LEFT JOIN st000 s ON ms.StoreGUID = s.GUID
            WHERE ms.MatGUID = :guid
            ORDER BY ms.Qty DESC, s.Number
        ");
        $sStmt->execute([':guid' => $guid]);
        $stores = $sStmt->fetchAll(PDO::FETCH_ASSOC);

        $totalQty = 0;
        foreach ($stores as &$s) {
            $s['Qty']  = (float)$s['Qty'];
            $s['Book'] = (float)$s['Book'];
            $totalQty += $s['Qty'];
        }
        unset($s);

        echo json_encode([
            'material'  => $material,
            'stores'    => $stores,
            'totalQty'  => $totalQty,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        jsonFail($e, 500, 'Could not load material.');
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>المخزون - بياناتي ويب</title>
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

        /* Filters bar */
        .filters-bar {
            display: flex; align-items: center; flex-wrap: wrap; gap: 10px;
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 12px 14px; margin-bottom: 16px;
        }
        .filters-bar label { font-size: 0.75rem; color: var(--text-muted); font-weight: 700; }
        .filters-bar input[type="text"],
        .filters-bar select {
            padding: 7px 12px; border: 1px solid var(--border);
            border-radius: 9px; font-size: 0.85rem; font-family: inherit;
            background: var(--bg); color: var(--text);
        }
        .filters-bar input[type="text"] { min-width: 200px; }
        .filters-bar input[type="text"]:focus,
        .filters-bar select:focus { outline: none; border-color: var(--primary); }
        .spacer { flex: 1; }

        .toggle-chip {
            display: inline-flex; align-items: center; gap: 6px;
            background: var(--surface); border: 1px solid var(--border);
            padding: 7px 12px; border-radius: 999px;
            font-size: 0.78rem; font-weight: 700; color: var(--text-muted);
            font-family: inherit; cursor: pointer;
            transition: all 0.15s;
        }
        .toggle-chip:hover { border-color: var(--primary); color: var(--primary); }
        .toggle-chip.active { background: var(--primary); border-color: var(--primary); color: white; }
        .toggle-chip.danger-active { background: var(--danger); border-color: var(--danger); color: white; }

                /* View-mode chips: a row of mutually exclusive chips, one active */
        .view-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 16px;
        }
        .view-chips .toggle-chip {
            padding: 8px 16px;
            font-size: 0.82rem;
        }
        .view-chips .toggle-chip.active {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }
        /* KPI */
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
        .kpi-card.kpi-warn { border-color: var(--danger); }
        .kpi-card.kpi-warn .kpi-value { color: var(--danger); }

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

        .load-more {
            display: block; width: 100%; padding: 11px;
            background: var(--bg); border: none;
            border-top: 1px solid var(--border);
            color: var(--primary-dark); font-weight: 700;
            font-size: 0.85rem; font-family: inherit; cursor: pointer;
        }
        .load-more:hover { background: var(--primary-light); }

        .low-badge {
            display: inline-block; font-size: 0.65rem; font-weight: 800;
            color: white; background: var(--danger); padding: 1px 6px;
            border-radius: 4px; margin-right: 4px;
        }
        .zero-qty { color: var(--text-muted); }
        .store-pill {
            display: inline-block; font-size: 0.7rem; font-weight: 700;
            color: var(--primary-dark); background: var(--primary-light);
            padding: 1px 8px; border-radius: 999px;
        }

        /* Detail view */
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
            .filters-bar { padding: 10px; }
            .filters-bar input[type="text"] { min-width: 0; flex: 1; }
            .filters-bar select { flex: 1; min-width: 0; }
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
                <h1>🏭 المخزون</h1>
            </div>
        </div>

        <!-- LIST VIEW -->
        <div id="listView">
            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-label">📦 عدد الأصناف</div>
                    <div class="kpi-value" id="kpiSkus">—</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">🔢 إجمالي القطع</div>
                    <div class="kpi-value" id="kpiPieces">—</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">🏬 عدد المستودعات</div>
                    <div class="kpi-value" id="kpiStores">—</div>
                </div>
                <div class="kpi-card kpi-warn">
                    <div class="kpi-label">⚠️ أصناف قاربت على النفاد</div>
                    <div class="kpi-value" id="kpiLow">—</div>
                </div>
                <div class="kpi-card kpi-primary">
                    <div class="kpi-label">💰 قيمة المخزون (USD)</div>
                    <div class="kpi-value" id="kpiValue">—</div>
                </div>
            </div>

            <div class="filters-bar">
                <input type="text" id="searchInput" placeholder="🔍 ابحث بالاسم أو الكود أو الباركود..." autocomplete="off">
                <label>المستودع</label>
                <select id="storeSelect"><option value="">الكل</option></select>
                <label>المجموعة</label>
                <select id="groupSelect"><option value="">الكل</option></select>
                <span class="spacer"></span>
                <button type="button" class="toggle-chip" id="lowBtn">⚠️ القارب على النفاد</button>
            </div>

            <!-- View mode chips — one per row layout -->
            <div class="view-chips">
                <button type="button" class="toggle-chip active" data-view="material">📦 حسب الصنف</button>
                <button type="button" class="toggle-chip" data-view="group">🏷️ حسب المجموعة</button>
                <button type="button" class="toggle-chip" data-view="store">🏬 حسب المستودع</button>
            </div>

            <div class="section">
                <h2 class="section-title">
                    <span id="listTitle">📦 الأصناف في المخزون</span>
                    <span class="muted" id="listCount"></span>
                </h2>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th class="sortable list-sortable" data-sort="name">الصنف <span class="sort-ind"></span></th>
                                <th class="sortable list-sortable" data-sort="code">الكود <span class="sort-ind"></span></th>
                                <th id="th-store" style="display:none;">المستودع</th>
                                <th class="sortable list-sortable" data-sort="qty">الكمية <span class="sort-ind"></span></th>
                                <th>الوحدة</th>
                                <th class="sortable list-sortable" data-sort="value">القيمة (USD) <span class="sort-ind"></span></th>
                                <th>المجموعة</th>
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
                    <h3>📋 معلومات الصنف</h3>
                    <div id="infoBody"></div>
                </div>
                <div class="info-card">
                    <h3>💰 المخزون الكلي والأسعار</h3>
                    <div id="summaryBody"></div>
                </div>
            </div>

            <div class="section" style="padding: 12px 18px; text-align: center;">
                <a id="movementsLink" href="#" class="btn" style="background:var(--primary); color:white; border-color:var(--primary); font-size:0.9rem; padding:10px 22px;">
                    📄 عرض سجل الحركات الكامل
                </a>
            </div>

            <div class="section">
                <h2 class="section-title">
                    <span>🏬 التوزيع حسب المستودع</span>
                    <span class="muted" id="storesCount"></span>
                </h2>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>المستودع</th>
                                <th>الكمية</th>
                                <th>حجز (Book)</th>
                                <th>المتاح</th>
                            </tr>
                        </thead>
                        <tbody id="storesBody">
                            <tr><td colspan="5" class="loading">جاري التحميل...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        // ---------- Helpers ----------
        const _numFmt = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const _intFmt = new Intl.NumberFormat('en-US');
        function fmtNum(v) { const n = parseFloat(v); return isFinite(n) ? _numFmt.format(n) : '0.00'; }
        function fmtQty(v) {
            const n = parseFloat(v);
            if (!isFinite(n)) return '0';
            return Number.isInteger(n) ? _intFmt.format(n) : _numFmt.format(n);
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
        const urlParams  = new URLSearchParams(window.location.search);
        const detailGuid = urlParams.get('guid');
        const isDetail   = !!detailGuid;

        let listOffset  = 0;
        let listHasMore = true;
        let currentSearch = '';
        let searchTimer  = null;
        let currentView  = 'material';   // 'material' | 'store'
        let currentStore = '';
        let currentGroup = '';
        let lowOnly      = false;
        let listSort     = { key: 'name', dir: 'asc' };

        // ---------- References (dropdowns) ----------
        async function loadRefs() {
            try {
                const resp = await fetch('?refs=1', { cache: 'no-store' });
                if (resp.status === 401) { window.location.href = 'login.php'; return; }
                const data = await resp.json();
                if (!data || data.error) return;

                const storeSel = document.getElementById('storeSelect');
                for (const s of data.stores) {
                    const opt = document.createElement('option');
                    opt.value = s.GUID;
                    opt.textContent = (s.Number ? s.Number + ' - ' : '') + (s.Name || '');
                    storeSel.appendChild(opt);
                }

                const groupSel = document.getElementById('groupSelect');
                for (const g of data.groups) {
                    const opt = document.createElement('option');
                    opt.value = g.GUID;
                    opt.textContent = g.Name || '';
                    groupSel.appendChild(opt);
                }
            } catch (e) { /* silent */ }
        }

        // ---------- KPI ----------
        async function loadKPI() {
            try {
                const resp = await fetch('?kpi=1', { cache: 'no-store' });
                if (resp.status === 401) { window.location.href = 'login.php'; return; }
                const data = await resp.json();
                if (!data || data.error) return;
                document.getElementById('kpiSkus').textContent   = data.skus;
                document.getElementById('kpiPieces').textContent = fmtQty(data.totalPieces);
                document.getElementById('kpiStores').textContent = data.stores;
                document.getElementById('kpiLow').textContent    = data.lowStock;
                document.getElementById('kpiValue').textContent  = fmtNum(data.stockValueUSD) + ' USD';
            } catch (e) { /* silent */ }
        }

                // ---------- Dynamic table headers per view ----------
        function updateTableHeaders() {
            const thead = document.querySelector('#listView thead');
            if (!thead) return;

            if (currentView === 'group') {
                thead.innerHTML = `
                    <tr>
                        <th class="sortable list-sortable" data-sort="name">المجموعة <span class="sort-ind"></span></th>
                        <th class="sortable list-sortable" data-sort="code">الكود <span class="sort-ind"></span></th>
                        <th>عدد الأصناف</th>
                        <th class="sortable list-sortable" data-sort="qty">إجمالي الكمية <span class="sort-ind"></span></th>
                        <th class="sortable list-sortable" data-sort="value">القيمة (USD) <span class="sort-ind"></span></th>
                    </tr>`;
            } else if (currentView === 'store') {
                thead.innerHTML = `
                    <tr>
                        <th class="sortable list-sortable" data-sort="name">الصنف <span class="sort-ind"></span></th>
                        <th class="sortable list-sortable" data-sort="code">الكود <span class="sort-ind"></span></th>
                        <th class="sortable list-sortable" data-sort="store">المستودع <span class="sort-ind"></span></th>
                        <th class="sortable list-sortable" data-sort="qty">الكمية <span class="sort-ind"></span></th>
                        <th>الوحدة</th>
                        <th class="sortable list-sortable" data-sort="value">القيمة (USD) <span class="sort-ind"></span></th>
                        <th>المجموعة</th>
                    </tr>`;
            } else {
                thead.innerHTML = `
                    <tr>
                        <th class="sortable list-sortable" data-sort="name">الصنف <span class="sort-ind"></span></th>
                        <th class="sortable list-sortable" data-sort="code">الكود <span class="sort-ind"></span></th>
                        <th class="sortable list-sortable" data-sort="qty">الكمية <span class="sort-ind"></span></th>
                        <th>الوحدة</th>
                        <th class="sortable list-sortable" data-sort="value">القيمة (USD) <span class="sort-ind"></span></th>
                        <th>المجموعة</th>
                    </tr>`;
            }

            // Re-attach sort listeners to the freshly created headers
            thead.querySelectorAll('th.list-sortable').forEach(th => {
                th.addEventListener('click', () => {
                    const key = th.dataset.sort;
                    if (listSort.key === key) {
                        listSort.dir = listSort.dir === 'asc' ? 'desc' : 'asc';
                    } else {
                        listSort.key = key;
                        listSort.dir = (key === 'name' || key === 'code' || key === 'store') ? 'asc' : 'desc';
                    }
                    updateSortIndicators();
                    loadList(true);
                });
            });
            updateSortIndicators();
        }

        function updateSortIndicators() {
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

        // ---------- List ----------
        function buildQuery(extra) {
            const p = new URLSearchParams(Object.assign({
                list: '1',
                view: currentView,
                offset: listOffset,
                sort: listSort.key,
                dir: listSort.dir,
            }, extra || {}));
            if (currentSearch) p.set('search', currentSearch);
            if (currentStore)  p.set('store',  currentStore);
            if (currentGroup)  p.set('group',  currentGroup);
            if (lowOnly)       p.set('lowOnly', '1');
            return p.toString();
        }

        async function loadList(reset) {
            if (reset) {
                listOffset = 0;
                listHasMore = true;
                const span = (currentView === 'group') ? 5 : (currentView === 'store' ? 7 : 6);
                document.getElementById('listBody').innerHTML =
                    `<tr><td colspan="${span}" class="loading">جاري التحميل...</td></tr>`;
            }

            try {
                const resp = await fetch('?' + buildQuery(), { cache: 'no-store' });
                if (resp.status === 401) { window.location.href = 'login.php'; return; }
                const data = await resp.json();
                if (!data || data.error) {
                    const span = (currentView === 'group') ? 5 : (currentView === 'store' ? 7 : 6);
                    document.getElementById('listBody').innerHTML =
                        `<tr><td colspan="${span}" class="loading">خطأ: ${escapeHtml((data && data.error) || '')}</td></tr>`;
                    return;
                }

                const body = document.getElementById('listBody');
                const span = (currentView === 'group') ? 5 : (currentView === 'store' ? 7 : 6);

                if (reset) body.innerHTML = '';

                if (!data.items.length && reset) {
                    body.innerHTML = `<tr><td colspan="${span}" class="empty">لا توجد نتائج.</td></tr>`;
                } else {
                    const frag = document.createDocumentFragment();

                    if (currentView === 'group') {
                        for (const it of data.items) {
                            const tr = document.createElement('tr');
                            tr.className = 'clickable';
                            // Clicking a group applies the group filter and
                            // switches to material view — the natural drill-down.
                            tr.addEventListener('click', () => {
                                currentGroup = it.GroupGUID;
                                document.getElementById('groupSelect').value = it.GroupGUID;
                                currentView = 'material';
                                document.querySelectorAll('.view-chips .toggle-chip').forEach(b => {
                                    b.classList.toggle('active', b.dataset.view === 'material');
                                });
                                document.getElementById('listTitle').textContent = '📦 الأصناف في المخزون';
                                updateTableHeaders();
                                loadList(true);
                            });
                            tr.innerHTML = `
                                <td>${escapeHtml(it.GroupName || '-')}</td>
                                <td class="num muted">${escapeHtml(it.GroupCode || '')}</td>
                                <td class="num">${it.MaterialCount}</td>
                                <td class="num"><b>${fmtQty(it.TotalQty)}</b></td>
                                <td class="num">${fmtNum(it.ValueUSD)}</td>
                            `;
                            frag.appendChild(tr);
                        }
                    } else if (currentView === 'store') {
                        for (const it of data.items) {
                            const tr = document.createElement('tr');
                            tr.className = 'clickable';
                            const href = 'inventory.php?guid=' + encodeURIComponent(it.MatGUID);
                            const lowBadge = it.IsLow ? `<span class="low-badge">⚠️ منخفض</span>` : '';
                            const qtyClass = it.TotalQty > 0 ? '' : 'zero-qty';
                            tr.innerHTML = `
                                <td>${lowBadge}<a class="row-link" href="${escapeHtml(href)}">${escapeHtml(it.ItemName || '-')}</a></td>
                                <td class="num muted">${escapeHtml(it.Code || '')}</td>
                                <td>${escapeHtml((it.StoreNumber ? it.StoreNumber + ' - ' : '') + (it.StoreName || '-'))}</td>
                                <td class="num ${qtyClass}"><b>${fmtQty(it.TotalQty)}</b></td>
                                <td class="muted">${escapeHtml(it.Unit || '')}</td>
                                <td class="num">${fmtNum(it.ValueUSD)}</td>
                                <td class="muted">${escapeHtml(it.GroupName || '')}</td>
                            `;
                            frag.appendChild(tr);
                        }
                    } else {
                        for (const it of data.items) {
                            const tr = document.createElement('tr');
                            tr.className = 'clickable';
                            const href = 'inventory.php?guid=' + encodeURIComponent(it.MatGUID);
                            const lowBadge = it.IsLow ? `<span class="low-badge">⚠️ منخفض</span>` : '';
                            const qtyClass = it.TotalQty > 0 ? '' : 'zero-qty';
                            const storesHint = (it.StoreCount > 1)
                                ? `<span class="muted" style="font-size:0.7rem; margin-right:6px;">(${it.StoreCount} مستودعات)</span>`
                                : '';
                            tr.innerHTML = `
                                <td>${lowBadge}<a class="row-link" href="${escapeHtml(href)}">${escapeHtml(it.ItemName || '-')}</a>${storesHint}</td>
                                <td class="num muted">${escapeHtml(it.Code || '')}</td>
                                <td class="num ${qtyClass}"><b>${fmtQty(it.TotalQty)}</b></td>
                                <td class="muted">${escapeHtml(it.Unit || '')}</td>
                                <td class="num">${fmtNum(it.ValueUSD)}</td>
                                <td class="muted">${escapeHtml(it.GroupName || '')}</td>
                            `;
                            frag.appendChild(tr);
                        }
                    }
                    body.appendChild(frag);
                }

                listHasMore = !!data.hasMore;
                listOffset += data.items.length;
                document.getElementById('loadMoreBtn').style.display = listHasMore ? 'block' : 'none';
                document.getElementById('listCount').textContent = `(${listOffset}${listHasMore ? '+' : ''})`;
            } catch (e) {
                console.error(e);
                const span = (currentView === 'group') ? 5 : (currentView === 'store' ? 7 : 6);
                document.getElementById('listBody').innerHTML =
                    `<tr><td colspan="${span}" class="loading">خطأ في الشبكة: ${escapeHtml(e.message)}</td></tr>`;
            }
        }

    
        document.getElementById('searchInput').addEventListener('input', (e) => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                currentSearch = e.target.value.trim();
                loadList(true);
            }, 350);
        });

        document.getElementById('storeSelect').addEventListener('change', (e) => {
            currentStore = e.target.value;
            loadList(true);
        });

        document.getElementById('groupSelect').addEventListener('change', (e) => {
            currentGroup = e.target.value;
            loadList(true);
        });

        document.querySelectorAll('.view-chips .toggle-chip').forEach(btn => {
            btn.addEventListener('click', () => {
                const view = btn.dataset.view;
                if (view === currentView) return;
                currentView = view;
                document.querySelectorAll('.view-chips .toggle-chip').forEach(b => {
                    b.classList.toggle('active', b.dataset.view === view);
                });
                const titles = {
                    material: '📦 الأصناف في المخزون',
                    group:    '🏷️ المخزون حسب المجموعة',
                    store:    '🏬 المخزون حسب المستودع',
                };
                document.getElementById('listTitle').textContent = titles[view];
                updateTableHeaders();
                loadList(true);
            });
        });

        document.getElementById('lowBtn').addEventListener('click', (e) => {
            lowOnly = !lowOnly;
            e.currentTarget.classList.toggle('danger-active', lowOnly);
            loadList(true);
        });

        document.getElementById('loadMoreBtn').addEventListener('click', () => {
            if (!listHasMore) return;
            loadList(false);
        });

        // ---------- Detail ----------
        async function loadMaterial(guid) {
            try {
                const resp = await fetch('?material=1&guid=' + encodeURIComponent(guid), { cache: 'no-store' });
                if (resp.status === 401) { window.location.href = 'login.php'; return; }
                const data = await resp.json();
                if (!data || data.error) {
                    document.getElementById('detailName').textContent = 'خطأ';
                    document.getElementById('detailSub').textContent  = (data && data.error) || 'Material not found';
                    return;
                }

                const m = data.material;
                document.getElementById('detailName').textContent = m.Name || '(بدون اسم)';
                document.getElementById('detailSub').textContent  =
                    (m.Code ? 'الكود: ' + m.Code : '') +
                    (m.BarCode ? ' · الباركود: ' + m.BarCode : '') +
                    (m.GroupName ? ' · المجموعة: ' + m.GroupName : '');
                                    // Wire the movements link
                const movLink = document.getElementById('movementsLink');
                if (movLink) movLink.href = 'movements.php?mat=' + encodeURIComponent(guid);
                

                function rows(list) {
                    let html = '';
                    for (const [lbl, val] of list) {
                        if (val === null || val === undefined || val === '' || val === 0) continue;
                        html += `<div class="info-row"><span class="lbl">${lbl}</span><span class="val">${escapeHtml(String(val))}</span></div>`;
                    }
                    return html || '<div class="empty" style="padding:12px 0;">لا توجد بيانات.</div>';
                }

                document.getElementById('infoBody').innerHTML = rows([
                    ['# الرقم',          m.Number],
                    ['🏷️ الكود',          m.Code],
                    ['🔖 الباركود',      m.BarCode],
                    ['📦 الوحدة الأساسية', m.Unity],
                    ['📦 الوحدة الثانية',  m.Unit2 ? `${m.Unit2} (×${m.Unit2Fact || 1})` : ''],
                    ['📦 الوحدة الثالثة',  m.Unit3 ? `${m.Unit3} (×${m.Unit3Fact || 1})` : ''],
                    ['🏷️ المجموعة',       m.GroupName ? `${m.GroupName}${m.GroupCode ? ' (' + m.GroupCode + ')' : ''}` : ''],
                ]);

                const low = parseFloat(m.Low) || 0;
                const isLow = low > 0 && data.totalQty <= low;
                document.getElementById('summaryBody').innerHTML = rows([
                    ['📊 المخزون الكلي',   fmtQty(data.totalQty) + ' ' + (m.Unity || '')],
                    ['⚠️ أدنى حد',         low > 0 ? fmtQty(low) : ''],
                    ['📈 أعلى حد',         m.High && parseFloat(m.High) > 0 ? fmtQty(m.High) : ''],
                    ['💵 سعر الجملة',       m.Whole && parseFloat(m.Whole) > 0 ? fmtNum(m.Whole) : ''],
                    ['🛒 سعر المفرق',       m.Retail && parseFloat(m.Retail) > 0 ? fmtNum(m.Retail) : ''],
                    ['👤 سعر المستهلك',     m.EndUser && parseFloat(m.EndUser) > 0 ? fmtNum(m.EndUser) : ''],
                    ['💰 آخر سعر',          m.LastPrice && parseFloat(m.LastPrice) > 0 ? fmtNum(m.LastPrice) : ''],
                    ['📊 متوسط السعر',      m.AvgPrice && parseFloat(m.AvgPrice) > 0 ? fmtNum(m.AvgPrice) : ''],
                    [isLow ? '⚠️ الحالة' : '✅ الحالة', isLow ? 'قارب على النفاد' : 'متوفر'],
                ]);

                const sb = document.getElementById('storesBody');
                if (!data.stores.length) {
                    sb.innerHTML = '<tr><td colspan="5" class="empty">لا يوجد مخزون مسجل لهذا الصنف.</td></tr>';
                } else {
                    let html = '';
                    let i = 0;
                    for (const s of data.stores) {
                        i++;
                        const available = s.Qty - (s.Book || 0);
                        html += `<tr>
                            <td class="num muted">${i}</td>
                            <td>${escapeHtml((s.StoreNumber ? s.StoreNumber + ' - ' : '') + (s.StoreName || '-'))}</td>
                            <td class="num"><b>${fmtQty(s.Qty)}</b></td>
                            <td class="num muted">${s.Book ? fmtQty(s.Book) : '—'}</td>
                            <td class="num ${available > 0 ? '' : 'zero-qty'}">${fmtQty(available)}</td>
                        </tr>`;
                    }
                    sb.innerHTML = html;
                }
                document.getElementById('storesCount').textContent = `(${data.stores.length} مستودع)`;
            } catch (e) {
                console.error(e);
            }
        }

        // ---------- Bootstrap ----------
        if (isDetail) {
            document.getElementById('listView').style.display   = 'none';
            document.getElementById('detailView').style.display = 'block';
            loadMaterial(detailGuid);
        } else {
            updateTableHeaders();
            loadRefs();
            loadKPI();
            loadList(true);
        }
    </script>
</body>
</html>