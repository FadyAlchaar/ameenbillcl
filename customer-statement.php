<?php
// customer-statement.php — customer account statement.
// Wraps Alameen's repCPS via dbo.ameenbill_GetCustomerStatement.
require_once 'config.php';
require_once 'auth.php';
requireLogin(true);

// ─── API: customer search ────────────────────────────────────────
if (isset($_GET['search']) && $_GET['search'] == 1) {
    header('Content-Type: application/json');
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';

    if ($q === '') {
        echo json_encode(['customers' => []]);
        exit;
    }

    try {
        $pdo  = getDBConnection();
        $like = '%' . $q . '%';

        // Number in cu000 is CHAR and can carry trailing spaces — CAST
        // normalises it. Also lets us use LIKE safely on it regardless
        // of the underlying column type on this install.
        $stmt = $pdo->prepare("
            SELECT TOP 25
                   GUID, Number, CustomerName, Phone1, Mobile, Debit, Credit
            FROM cu000
            WHERE CustomerName LIKE :s1
               OR CAST(Number   AS NVARCHAR(50)) LIKE :s2
               OR Phone1       LIKE :s3
               OR Mobile       LIKE :s4
            ORDER BY
                CASE
                    WHEN CAST(Number AS NVARCHAR(50)) = :exact1 THEN 0
                    WHEN CustomerName LIKE :starts THEN 1
                    ELSE 2
                END,
                CustomerName
        ");
        $stmt->execute([
            ':s1'     => $like,
            ':s2'     => $like,
            ':s3'     => $like,
            ':s4'     => $like,
            ':exact1' => $q,
            ':starts' => $q . '%',
        ]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'GUID'   => $r['GUID'],
                'Number' => trim((string)$r['Number']),
                'Name'   => $r['CustomerName'],
                'Phone1' => $r['Phone1'],
                'Mobile' => $r['Mobile'],
                'Debit'  => (float)$r['Debit'],
                'Credit' => (float)$r['Credit'],
            ];
        }

        echo json_encode(
            ['customers' => $out],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    } catch (Throwable $e) {
        // Return the actual error so the JS can display it — silently
        // swallowing it is what made this look like "nothing happens".
        error_log('[AmeenBill] customer search: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(
            [
                'customers' => [],
                'error'     => 'Search failed: ' . $e->getMessage(),
            ],
            JSON_UNESCAPED_UNICODE
        );
    }
    exit;
}

// ─── API: customer statement ─────────────────────────────────────
if (isset($_GET['statement']) && $_GET['statement'] == 1) {
    header('Content-Type: application/json');

    $guid = isset($_GET['guid']) ? $_GET['guid'] : '';
    if (!preg_match('/^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/', $guid)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid GUID']);
        exit;
    }

    $fromRaw = $_GET['from'] ?? null;
    $toRaw   = $_GET['to']   ?? null;
    try {
        $from = $fromRaw ? new DateTime($fromRaw) : (new DateTime('today'))->modify('-90 days');
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

    $showItems = isset($_GET['items']) && $_GET['items'] == 1;

    try {
        $pdo = getDBConnection();

        // Customer master (name / code / phones) — separate query so it's
        // available even if the customer has zero movements in the range.
        $cStmt = $pdo->prepare("
            SELECT GUID, Number, CustomerName, LatinName, Phone1, Mobile, EMail
            FROM cu000 WHERE GUID = :guid
        ");
        $cStmt->execute([':guid' => $guid]);
        $customer = $cStmt->fetch(PDO::FETCH_ASSOC);
        if (!$customer) {
            http_response_code(404);
            echo json_encode(['error' => 'Customer not found']);
            exit;
        }

        // Run the wrapper
        $stmt = $pdo->prepare("
            EXEC dbo.ameenbill_GetCustomerStatement
                @CustPtr   = :cust,
                @StartDate = :from,
                @EndDate   = :to,
                @ShowDetails = :details,
                @ShowRunningBalance = 1
        ");
        $stmt->execute([
            ':cust'    => $guid,
            ':from'    => $from->format('Y-m-d') . ' 00:00:00',
            ':to'      => $to->format('Y-m-d')   . ' 23:59:59.997',
            ':details' => 1,   // always request details server-side; UI decides what to show
        ]);

        // ── Consume all result sets; identify each by column signature ──
        $header   = null;
        $entries  = [];
        $items    = [];
        $summary  = [];

        do {
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($rows)) continue;

            $first = $rows[0];
            if (array_key_exists('AccCode', $first) && array_key_exists('PrevBalance', $first)) {
                $header = $first;
            } elseif (array_key_exists('MoveBalance', $first) && array_key_exists('BillGUID', $first)) {
                $entries = array_merge($entries, $rows);
            } elseif (array_key_exists('MaterialName', $first) && array_key_exists('Qty', $first)) {
                $items = array_merge($items, $rows);
            } elseif (array_key_exists('Cnt', $first) && array_key_exists('Type', $first)) {
                $summary = array_merge($summary, $rows);
            }
        } while ($stmt->nextRowset());

        // ── Normalize header ──────────────────────────────────
        if ($header) {
            $header['PrevBalance']   = (float)$header['PrevBalance'];
            $header['Debit']         = (float)$header['Debit'];
            $header['Credit']        = (float)$header['Credit'];
            $header['Discount']      = (float)$header['Discount'];
            $header['Extra']         = (float)$header['Extra'];
            $header['AccCurrencyValue'] = (float)($header['AccCurrencyValue'] ?? 1) ?: 1;
            $header['LastCheckDate'] = isoStamp($header['LastCheckDate'] ?? null);
            $header['ClosingBalance'] =
                $header['PrevBalance']
              + $header['Debit']
              - $header['Credit'];
            $header['CurrencyCode'] =
                !empty($header['AccCurrencyCode'])
                    ? $header['AccCurrencyCode']
                    : '$';
        }

        // ── Normalize ledger entries ──────────────────────────
        $normEntries = [];
        foreach ($entries as $e) {
            $normEntries[] = [
                'GUID'           => $e['GUID']    ?? null,
                'BillGUID'       => $e['BillGUID'] ?? null,
                'Date'           => isoStamp($e['Date'] ?? null),
                'DueDate'        => isoStamp($e['DueDate'] ?? null),
                'Type'           => $e['Type']    ?? null,
                'Number'         => $e['Number']  ?? null,
                'Document'       => $e['Document'] ?? null,
                'ContraAccName'  => $e['ContraAccName'] ?? null,
                'Notes'          => $e['BNotes']  ?? null,
                'Debit'          => (float)($e['Debit']    ?? 0),
                'Credit'         => (float)($e['Credit']   ?? 0),
                'Discount'       => (float)($e['Discount'] ?? 0),
                'Extra'          => (float)($e['Extra']    ?? 0),
                'MoveBalance'    => (float)($e['MoveBalance']    ?? 0),
                'CurrentBalance' => (float)($e['CurrentBalance'] ?? 0),
                'IsCash'         => (int)($e['IsCash'] ?? 0),
                'CostName'       => $e['CostName']  ?? null,
                'BranchName'     => $e['BranchName'] ?? null,
                'ChequeState'    => $e['ChecqueState'] ?? null,
                'ChequeDesc'     => $e['ChequeDesc'] ?? null,
                'Checked'        => (int)($e['Checked'] ?? 0),
            ];
        }

        // ── Normalize line items (only when requested) ────────
        $normItems = [];
        foreach ($items as $it) {
            $normItems[] = [
                'BillGUID'     => $it['BillGUID']     ?? null,
                'MaterialName' => $it['MaterialName'] ?? null,
                'Qty'          => (float)($it['Qty']          ?? 0),
                'Bonus'        => (float)($it['Bonus']        ?? 0),
                'Unit'         => $it['Unit']        ?? null,
                'BiPrice'      => (float)($it['BiPrice']      ?? 0),
                'BiTotalPrice' => (float)($it['BiTotalPrice'] ?? 0),
                'BiDiscount'   => (float)($it['BiDiscount']   ?? 0),
                'BiExtra'      => (float)($it['BiExtra']      ?? 0),
                'StoreName'    => $it['StoreName']   ?? null,
                'CostName'     => $it['CostName']    ?? null,
                'SN'           => $it['SN']          ?? null,
            ];
        }

        echo json_encode([
            'customer' => $customer,
            'header'   => $header,
            'entries'  => $normEntries,
            'items'    => $normItems,
            'summary'  => $summary,
            'range'    => [
                'from' => $from->format('Y-m-d'),
                'to'   => $to->format('Y-m-d'),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        jsonFail($e, 500, 'Could not load statement.');
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>كشف حساب عميل - QuantuSphere Web</title>
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

        /* Customer search bar */
        .cust-search {
            background: var(--surface); border: 2px solid var(--primary);
            border-radius: var(--radius); padding: 14px 18px;
            margin-bottom: 16px;
            display: flex; align-items: center; gap: 12px;
            box-shadow: 0 2px 12px rgba(184,134,60,0.15);
        }
        .cust-search label {
            font-size: 0.85rem; font-weight: 800;
            color: var(--primary-dark); white-space: nowrap;
        }
        .cs-wrap { position: relative; flex: 1; min-width: 0; }
        .cs-wrap input {
            width: 100%; padding: 10px 16px;
            border: 1px solid var(--border); border-radius: 9px;
            background: var(--bg); color: var(--text);
            font-family: inherit; font-size: 0.95rem;
        }
        .cs-wrap input:focus {
            outline: none; border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-light);
        }
        .cs-panel {
            display: none; position: absolute;
            top: calc(100% + 6px); left: 0; right: 0;
            background: var(--surface); border: 1px solid var(--border);
            border-radius: 10px; box-shadow: 0 10px 30px rgba(29,42,53,0.18);
            max-height: 340px; overflow-y: auto;
            z-index: 50; padding: 4px;
        }
        .cs-wrap.open .cs-panel { display: block; }
        .cs-option { padding: 10px 12px; border-radius: 7px; cursor: pointer; }
        .cs-option:hover { background: var(--primary-light); }
        .cs-opt-name { font-size: 0.9rem; font-weight: 700; color: var(--text); }
        .cs-opt-meta {
            font-size: 0.72rem; color: var(--text-muted); margin-top: 3px;
            font-family: var(--font-num); font-variant-numeric: tabular-nums;
        }
        .cs-opt-balance {
            float: left; margin-top: -18px;
            font-family: var(--font-num); font-weight: 700; font-size: 0.82rem;
        }
        .cs-empty { padding: 12px; text-align: center; color: var(--text-muted); font-size: 0.8rem; }

        /* Reset button inside the search bar */
        .reset-btn {
            white-space: nowrap;
            border-color: var(--danger);
            color: var(--danger);
            padding: 8px 14px;
            font-size: 0.82rem;
        }
        .reset-btn:hover {
            background: rgba(162,59,46,0.08);
            border-color: var(--danger);
            color: var(--danger);
        }

        /* Date filter chips */
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
        .toggle-chip {
            display: inline-flex; align-items: center; gap: 6px;
            background: var(--surface); border: 1px solid var(--border);
            padding: 7px 12px; border-radius: 999px;
            font-size: 0.78rem; font-weight: 700; color: var(--text-muted);
            font-family: inherit; cursor: pointer;
            transition: all 0.15s;
        }
        .toggle-chip:hover { border-color: var(--primary); color: var(--primary); }
        .toggle-chip.active { background: var(--accent); border-color: var(--accent); color: white; }
        .spacer { flex: 1; }

        /* Customer header card */
        .cust-header {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 16px 20px;
            margin-bottom: 16px; display: flex; justify-content: space-between;
            align-items: flex-start; gap: 16px; flex-wrap: wrap;
        }
        .cust-header h2 { margin: 0 0 4px; font-size: 1.3rem; font-weight: 900; }
        .cust-header .sub { color: var(--text-muted); font-size: 0.85rem; }
        .cust-header .actions { display: flex; gap: 8px; flex-wrap: wrap; }

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
        .kpi-card.kpi-opening { border-color: var(--text-muted); }
        .kpi-card.kpi-debit   { border-color: var(--danger); }
        .kpi-card.kpi-debit .kpi-value   { color: var(--danger); }
        .kpi-card.kpi-credit  { border-color: var(--accent); }
        .kpi-card.kpi-credit .kpi-value  { color: var(--accent); }
        .kpi-card.kpi-closing { border-color: var(--primary); }
        .kpi-card.kpi-closing .kpi-value { color: var(--primary-dark); font-size: 1.4rem; }

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
        .pos { color: var(--danger); font-weight: 700; }
        .neg { color: var(--accent); font-weight: 700; }
        .zero { color: var(--text-muted); }

        /* Nested item rows under each bill */
        tr.item-row td {
            background: rgba(184,134,60,0.04);
            font-size: 0.78rem;
            color: var(--text-muted);
            padding: 6px 12px 6px 32px;
            border-bottom: 1px dashed var(--border);
        }
        tr.item-row:hover td { background: rgba(184,134,60,0.08); }
        .item-badge {
            display: inline-block; font-size: 0.62rem; font-weight: 700;
            color: var(--primary-dark); background: var(--primary-light);
            padding: 1px 6px; border-radius: 4px; margin-left: 6px;
        }

        .empty, .loading {
            padding: 40px 20px; text-align: center;
            color: var(--text-muted); font-size: 0.9rem;
        }

        /* Sortable column headers */
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

        @media (max-width: 600px) {
            body { padding: 10px; }
            h1 { font-size: 1.1rem; }
            .cust-search { flex-direction: column; align-items: stretch; gap: 8px; }
            .cust-search label { font-size: 0.78rem; }
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
            .kpi-card.kpi-closing { grid-column: 1 / -1; }
            th, td { padding: 8px 6px; font-size: 0.75rem; }
            .chip-bar { padding: 8px 10px; }
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
                <h1>📒 كشف حساب عميل (زبون أو مورد)</h1>
            </div>
        </div>

                <!-- Customer search -->
        <div class="cust-search">
            <label>👤 العميل</label>
            <div class="cs-wrap" id="custSearchWrap">
                <input type="text" id="custSearchInput"
                       placeholder="ابحث بالاسم أو الرقم أو الهاتف..."
                       autocomplete="off" spellcheck="false">
                <div class="cs-panel" id="custSearchPanel"></div>
            </div>
            <button type="button" class="btn reset-btn" id="resetBtn" title="مسح البحث وإعادة الفترة الافتراضية">
                ↺ إعادة تعيين
            </button>
        </div>

        <!-- Date filter -->
        <div class="chip-bar" id="dateBar" style="display:none;">
            <button type="button" class="chip" data-preset="month">هذا الشهر</button>
            <button type="button" class="chip" data-preset="quarter">آخر 3 أشهر</button>
            <button type="button" class="chip" data-preset="year">هذه السنة</button>
            <button type="button" class="chip" data-preset="all">الكل</button>
            <span class="spacer"></span>
            <label>من</label>
            <input type="date" id="dateFrom">
            <label>إلى</label>
            <input type="date" id="dateTo">
            <button type="button" class="btn-primary" id="applyBtn">تطبيق</button>
            <button type="button" class="toggle-chip" id="showItemsBtn" title="عرض أصناف كل فاتورة">
                📦 <span>تفاصيل الأصناف</span>
            </button>
        </div>

        <!-- Empty state (no customer selected yet) -->
        <div id="emptyState" class="empty">
            <div style="font-size:2rem; margin-bottom:8px;">👤</div>
            <div>اكتب اسم العميل أو رقمه أو هاتفه في المربع أعلاه</div>
        </div>

        <!-- Statement view -->
        <div id="stmtView" style="display:none;">
            <div class="cust-header">
                <div>
                    <h2 id="detailName">—</h2>
                    <div class="sub" id="detailSub">—</div>
                </div>
                <div class="actions">
                    <a href="#" class="btn" id="openCustomerBtn" style="display:none;">👤 فتح ملف العميل</a>
                    <a href="#" class="btn" id="printBtn">🖨️ طباعة</a>
                </div>
            </div>

            <div class="kpi-grid">
                <div class="kpi-card kpi-opening">
                    <div class="kpi-label">📥 رصيد افتتاحي</div>
                    <div class="kpi-value" id="kpiOpening">—</div>
                </div>
                <div class="kpi-card kpi-debit">
                    <div class="kpi-label">⬇️ إجمالي المدين</div>
                    <div class="kpi-value" id="kpiDebit">—</div>
                </div>
                <div class="kpi-card kpi-credit">
                    <div class="kpi-label">⬆️ إجمالي الدائن</div>
                    <div class="kpi-value" id="kpiCredit">—</div>
                </div>
                <div class="kpi-card kpi-closing">
                    <div class="kpi-label">📊 الرصيد الختامي</div>
                    <div class="kpi-value" id="kpiClosing">—</div>
                </div>
            </div>

            <div class="section">
                <h2 class="section-title">
                    <span>📒 الحركات</span>
                    <span class="muted" id="entriesCount"></span>
                </h2>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th class="sortable stmt-sortable" data-sort="Date">التاريخ <span class="sort-ind"></span></th>
                                <th class="sortable stmt-sortable" data-sort="Notes">البيان <span class="sort-ind"></span></th>
                                <th class="sortable stmt-sortable" data-sort="ContraAccName">الحساب المقابل <span class="sort-ind"></span></th>
                                <th class="sortable stmt-sortable" data-sort="Debit">مدين <span class="sort-ind"></span></th>
                                <th class="sortable stmt-sortable" data-sort="Credit">دائن <span class="sort-ind"></span></th>
                                <th class="sortable stmt-sortable" data-sort="CurrentBalance">الرصيد التراكمي <span class="sort-ind"></span></th>
                            </tr>
                        </thead>
                        <tbody id="entriesBody">
                            <tr><td colspan="7" class="loading">جاري التحميل...</td></tr>
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

                // ---------- State ----------
        const urlParams = new URLSearchParams(window.location.search);
        let customerGuid = urlParams.get('guid') || null;
        let currentRange = { from: '', to: '' };
        let showItems = false;
        let currentData = null;
        let stmtSort = { key: 'Date', dir: 'asc' };   // default: chronological

        // ---------- Statement table sorting ----------
        function updateStmtSortIndicators() {
            document.querySelectorAll('th.stmt-sortable').forEach(th => {
                const ind = th.querySelector('.sort-ind');
                if (th.dataset.sort === stmtSort.key) {
                    th.classList.add('sort-active');
                    if (ind) ind.textContent = stmtSort.dir === 'asc' ? '▲' : '▼';
                } else {
                    th.classList.remove('sort-active');
                    if (ind) ind.textContent = '';
                }
            });
        }

        document.querySelectorAll('th.stmt-sortable').forEach(th => {
            th.addEventListener('click', () => {
                const key = th.dataset.sort;
                if (stmtSort.key === key) {
                    stmtSort.dir = stmtSort.dir === 'asc' ? 'desc' : 'asc';
                } else {
                    stmtSort.key = key;
                    // Text columns default to ascending; numeric/date to descending
                    stmtSort.dir = (key === 'Notes' || key === 'ContraAccName') ? 'asc' : 'desc';
                }
                updateStmtSortIndicators();
                if (currentData) renderStatement(currentData);
            });
        });

        const FILTER_KEY = 'customerStatementRange';

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

        // ---------- Customer search ----------
        const searchInput = document.getElementById('custSearchInput');
        const searchPanel = document.getElementById('custSearchPanel');
        const searchWrap  = document.getElementById('custSearchWrap');

        let searchDebounce = null;
        let searchAbort    = null;

        function openSearchPanel()  { searchWrap.classList.add('open'); }
        function closeSearchPanel() { searchWrap.classList.remove('open'); }

        async function doSearch(q) {
            if (!q) {
                searchPanel.innerHTML = '<div class="cs-empty">اكتب للبحث...</div>';
                return;
            }

            if (searchAbort) searchAbort.abort();
            searchAbort = new AbortController();

            // Visible loading state — the old version gave no feedback at all
            // while the fetch was in flight, so any delay felt like a broken box.
            searchPanel.innerHTML = '<div class="cs-empty">جاري البحث...</div>';

            try {
                const resp = await fetch('?search=1&q=' + encodeURIComponent(q), {
                    cache: 'no-store',
                    signal: searchAbort.signal,
                });

                if (resp.status === 401) { window.location.href = 'login.php'; return; }

                // Read the text first, then try to parse. That way if the server
                // returned HTML (fatal error page) we can show something useful
                // instead of a JSON-parse crash that gets swallowed.
                const raw = await resp.text();
                let data;
                try {
                    data = JSON.parse(raw);
                } catch (e) {
                    console.error('Non-JSON response from search endpoint:', raw.substring(0, 400));
                    searchPanel.innerHTML =
                        '<div class="cs-empty">استجابة غير صالحة من الخادم — افتح Console للتفاصيل.</div>';
                    return;
                }

                if (data && data.error) {
                    searchPanel.innerHTML =
                        '<div class="cs-empty">خطأ: ' + escapeHtml(data.error) + '</div>';
                    return;
                }

                if (!data || !Array.isArray(data.customers)) {
                    searchPanel.innerHTML = '<div class="cs-empty">استجابة غير متوقعة.</div>';
                    return;
                }

                if (!data.customers.length) {
                    searchPanel.innerHTML = '<div class="cs-empty">لا توجد نتائج.</div>';
                    return;
                }

                let html = '';
                for (const c of data.customers) {
                    const meta = [c.Number, c.Phone1, c.Mobile].filter(Boolean).join(' · ');
                    const bal  = (c.Debit || 0) - (c.Credit || 0);
                    html += `<div class="cs-option"
                                data-guid="${escapeHtml(c.GUID)}"
                                data-name="${escapeHtml(c.Name || '')}">
                        <div class="cs-opt-name">${escapeHtml(c.Name || '-')}</div>
                        ${meta ? `<div class="cs-opt-meta">${escapeHtml(meta)}</div>` : ''}
                        <div class="cs-opt-balance ${balanceClass(bal)}">${fmtNum(bal)}</div>
                    </div>`;
                }
                searchPanel.innerHTML = html;
                searchPanel.querySelectorAll('.cs-option').forEach(el => {
                    el.addEventListener('mousedown', (ev) => {
                        ev.preventDefault();   // keep the input focused so blur doesn't race the click
                        selectCustomer(el.dataset.guid, el.dataset.name);
                    });
                });
            } catch (e) {
                if (e.name === 'AbortError') return;
                console.error('Search fetch failed:', e);
                searchPanel.innerHTML =
                    '<div class="cs-empty">خطأ في الشبكة: ' + escapeHtml(e.message) + '</div>';
            }
        }

        function selectCustomer(guid, name) {
            searchInput.value = name || '';
            closeSearchPanel();
            if (guid === customerGuid) return;   // already loaded

            customerGuid = guid;

            // Keep URL in sync — bookmarkable + browser Back/Forward friendly
            try {
                const url = new URL(window.location.href);
                url.searchParams.set('guid', guid);
                window.history.pushState({ guid }, '', url);
            } catch (e) {}

            ensureDefaultRange();
            loadStatement();
        }

        searchInput.addEventListener('focus', () => {
            openSearchPanel();
            if (!searchPanel.innerHTML.trim()) doSearch(searchInput.value.trim());
        });
        searchInput.addEventListener('input', () => {
            openSearchPanel();
            clearTimeout(searchDebounce);
            const q = searchInput.value.trim();
            searchDebounce = setTimeout(() => doSearch(q), 250);
        });
        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                e.preventDefault();
                closeSearchPanel();
                searchInput.blur();
            } else if (e.key === 'Enter') {
                e.preventDefault();
                clearTimeout(searchDebounce);
                const first = searchPanel.querySelector('.cs-option');
                if (first) selectCustomer(first.dataset.guid, first.dataset.name);
            }
        });
        document.addEventListener('mousedown', (e) => {
            if (searchWrap && !searchWrap.contains(e.target)) closeSearchPanel();
        });

        // ---------- Date range ----------
        function setActiveChip(preset) {
            document.querySelectorAll('.chip[data-preset]').forEach(c => {
                c.classList.toggle('active', c.dataset.preset === preset);
            });
        }

        function ensureDefaultRange() {
            if (currentRange.from && currentRange.to) return;
            const saved = loadSavedFilter();
            if (saved) {
                currentRange = saved;
            } else {
                // Default: January 1st of the current year → today
                const today = new Date();
                const from  = new Date(today.getFullYear(), 0, 1);
                currentRange = { from: fmtDateInput(from), to: fmtDateInput(today) };
                setActiveChip('year');
            }
            document.getElementById('dateFrom').value = currentRange.from;
            document.getElementById('dateTo').value   = currentRange.to;
            document.getElementById('dateBar').style.display = '';
        }

        function applyPreset(preset) {
            const today = new Date();
            let from = new Date(today), to = new Date(today);
            if (preset === 'month') {
                from = new Date(today.getFullYear(), today.getMonth(), 1);
            } else if (preset === 'quarter') {
                from = dateMinusDays(today, 90);
            } else if (preset === 'year') {
                from = new Date(today.getFullYear(), 0, 1);
            } else if (preset === 'all') {
                from = new Date(2000, 0, 1);
            }
            currentRange = { from: fmtDateInput(from), to: fmtDateInput(to) };
            document.getElementById('dateFrom').value = currentRange.from;
            document.getElementById('dateTo').value   = currentRange.to;
            setActiveChip(preset);
                        // Don't persist "all" — the fake 1900/2000 date looks alarming on
            // refresh. Every other preset still saves normally.
            if (preset !== 'all') saveFilter();
            loadStatement();
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
            loadStatement();
        });
        document.getElementById('showItemsBtn').addEventListener('click', (e) => {
            showItems = !showItems;
            e.currentTarget.classList.toggle('active', showItems);
            if (customerGuid) loadStatement();
        });

        // ---------- Reset ----------
        document.getElementById('resetBtn').addEventListener('click', () => {
            // 1. Clear the search box and any open dropdown
            searchInput.value = '';
            searchPanel.innerHTML = '';
            searchWrap.classList.remove('open');

            // 2. Forget the selected customer
            customerGuid = null;

            // 3. Reset the date range to 1/1 of the current year → today
            const today = new Date();
            const yearStart = new Date(today.getFullYear(), 0, 1);
            currentRange = {
                from: fmtDateInput(yearStart),
                to:   fmtDateInput(today),
            };
            document.getElementById('dateFrom').value = currentRange.from;
            document.getElementById('dateTo').value   = currentRange.to;
            setActiveChip('year');   // highlight the "هذه السنة" chip

            // 4. Clear the saved preference so the next visit also defaults
            //    to the year-to-date window (not the previous selection).
            try { localStorage.removeItem(FILTER_KEY); } catch (e) {}

            // 5. Reset item-details toggle
            showItems = false;
            document.getElementById('showItemsBtn').classList.remove('active');

            // 6. Hide the date bar and the statement, show the empty prompt
            document.getElementById('dateBar').style.display  = 'none';
            document.getElementById('stmtView').style.display = 'none';
            document.getElementById('emptyState').style.display = '';

            // 7. Clean the URL (drop ?guid=…)
            try {
                window.history.pushState({}, '', window.location.pathname);
            } catch (e) {}

            // 8. Reset sort indicator so it's ready for the next load
            if (typeof updateStmtSortIndicators === 'function') updateStmtSortIndicators();

            // Focus back on the search box for a fresh start
            searchInput.focus();
        });

        // ---------- Load & render statement ----------
        async function loadStatement() {
            if (!customerGuid) {
                document.getElementById('emptyState').style.display = '';
                document.getElementById('stmtView').style.display = 'none';
                return;
            }
            ensureDefaultRange();

            const body = document.getElementById('entriesBody');
            body.innerHTML = '<tr><td colspan="7" class="loading">جاري التحميل...</td></tr>';
            document.getElementById('emptyState').style.display = 'none';
            document.getElementById('stmtView').style.display = '';

            const qs = new URLSearchParams({
                statement: '1',
                guid: customerGuid,
                from: currentRange.from,
                to: currentRange.to,
            });
            if (showItems) qs.set('items', '1');

            try {
                const resp = await fetch('?' + qs.toString(), { cache: 'no-store' });
                if (resp.status === 401) { window.location.href = 'login.php'; return; }
                const data = await resp.json();
                if (!data || data.error) {
                    body.innerHTML = `<tr><td colspan="7" class="loading">خطأ: ${escapeHtml((data && data.error) || '')}</td></tr>`;
                    return;
                }
                currentData = data;
                renderStatement(data);
            } catch (e) {
                console.error(e);
                body.innerHTML = `<tr><td colspan="7" class="loading">خطأ في الشبكة: ${escapeHtml(e.message)}</td></tr>`;
            }
        }

        function renderStatement(data) {
            const c   = data.customer || {};
            const hdr = data.header   || {};
            const cur = hdr.CurrencyCode || '$';

            document.getElementById('detailName').textContent = c.CustomerName || '(بدون اسم)';
            document.getElementById('detailSub').textContent =
                (c.Number ? 'رقم العميل: ' + c.Number : '') +
                (c.LatinName ? ' · ' + c.LatinName : '') +
                (c.Mobile ? ' · ' + c.Mobile : '') +
                (c.Phone1 ? ' · ' + c.Phone1 : '');

            const openCustomerBtn = document.getElementById('openCustomerBtn');
            openCustomerBtn.href = 'customers.php?guid=' + encodeURIComponent(customerGuid);
            openCustomerBtn.style.display = '';

            document.getElementById('kpiOpening').textContent = fmtNum(hdr.PrevBalance || 0) + ' ' + cur;
            document.getElementById('kpiDebit').textContent   = fmtNum(hdr.Debit || 0) + ' ' + cur;
            document.getElementById('kpiCredit').textContent  = fmtNum(hdr.Credit || 0) + ' ' + cur;
            document.getElementById('kpiClosing').textContent = fmtNum(hdr.ClosingBalance || 0) + ' ' + cur;

            // Group line items by BillGUID for nested rows
            const itemsByBill = {};
            if (showItems && Array.isArray(data.items)) {
                for (const it of data.items) {
                    const key = it.BillGUID || '';
                    if (!itemsByBill[key]) itemsByBill[key] = [];
                    itemsByBill[key].push(it);
                }
            }

            const entries = Array.isArray(data.entries) ? data.entries : [];
            const body = document.getElementById('entriesBody');
            document.getElementById('entriesCount').textContent =
                `(${entries.length} حركة · ${data.range.from} → ${data.range.to})`;

            if (!entries.length) {
                body.innerHTML = '<tr><td colspan="7" class="empty">لا توجد حركات في هذه الفترة.</td></tr>';
                return;
            }

            // Client-side sort on the currently loaded entry set
            const sorted = entries.slice().sort((a, b) => {
                const k = stmtSort.key;
                const dir = stmtSort.dir === 'asc' ? 1 : -1;
                let va, vb;
                if (k === 'Date') {
                    va = a.Date ? Date.parse(a.Date) : 0;
                    vb = b.Date ? Date.parse(b.Date) : 0;
                } else if (k === 'Debit' || k === 'Credit' || k === 'CurrentBalance') {
                    va = a[k] || 0;
                    vb = b[k] || 0;
                } else {
                    va = String(a[k] || '');
                    vb = String(b[k] || '');
                }
                if (va < vb) return -1 * dir;
                if (va > vb) return  1 * dir;
                return 0;
            });

            let html = '';
            let idx = 0;
            for (const e of sorted) {
                idx++;
                const dateStr = e.Date ? new Date(e.Date).toLocaleDateString('en-GB') : '-';
                const docStr  = e.Document ? escapeHtml(String(e.Document)) : '';

                html += `<tr>
                    <td class="num muted">${idx}</td>
                    <td class="num">${dateStr}</td>
                    <td>${escapeHtml(e.Notes || e.ChequeDesc || docStr || '')}</td>
                    <td class="muted">${escapeHtml(e.ContraAccName || '')}</td>
                    <td class="num pos">${e.Debit  ? fmtNum(e.Debit)  : ''}</td>
                    <td class="num neg">${e.Credit ? fmtNum(e.Credit) : ''}</td>
                    <td class="num ${balanceClass(e.CurrentBalance)}"><b>${fmtNum(e.CurrentBalance)}</b></td>
                </tr>`;

                                // Nested items (only when the toggle is on and we have them)
                const billItems = itemsByBill[e.BillGUID];
                if (showItems && billItems && billItems.length) {
                    for (const it of billItems) {
                        const qtyInfo =
                            fmtNum(it.Qty) + ' ' + escapeHtml(it.Unit || '') +
                            (it.Bonus ? ' + ' + fmtNum(it.Bonus) + ' هدية' : '') +
                            (it.StoreName ? ' · ' + escapeHtml(it.StoreName) : '');

                        html += `<tr class="item-row">
                            <td></td>
                            <td></td>
                            <td><span class="item-badge">📦</span> ${escapeHtml(it.MaterialName || '-')}</td>
                            <td class="muted">${qtyInfo}</td>
                            <td class="num">${fmtNum(it.BiTotalPrice)}</td>
                            <td class="num muted">${it.BiDiscount ? '− ' + fmtNum(it.BiDiscount) : ''}</td>
                            <td class="num muted">${it.BiExtra ? '+ ' + fmtNum(it.BiExtra) : ''}</td>
                        </tr>`;
                    }
                }
            }
            body.innerHTML = html;
        }

        // ---------- Print ----------
        document.getElementById('printBtn').addEventListener('click', () => window.print());

        // ---------- Back/forward ----------
        window.addEventListener('popstate', () => {
            const p = new URLSearchParams(window.location.search);
            const guid = p.get('guid');
            if (guid && guid !== customerGuid) {
                customerGuid = guid;
                loadStatement();
            }
        });

                // ---------- Bootstrap ----------
        updateStmtSortIndicators();

        (async () => {
            if (customerGuid) {
                // Preload the search box with the current customer name.
                try {
                    const resp = await fetch('?statement=1&guid=' + encodeURIComponent(customerGuid)
                                              + '&from=' + fmtDateInput(new Date())
                                              + '&to='   + fmtDateInput(new Date())
                                              + '&items=0', { cache: 'no-store' });
                    const data = await resp.json();
                    if (data && data.customer) {
                        searchInput.value = data.customer.CustomerName || '';
                    }
                } catch (e) {}
                ensureDefaultRange();
                loadStatement();
            }
        })();
    </script>
</body>
</html>