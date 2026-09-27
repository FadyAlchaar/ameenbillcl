<?php
// index.php — landing page. Tiles driven by tiles.php + per-browser override.
require_once 'config.php';
require_once 'auth.php';
requireLogin(false);

// Load install-level defaults
$tileDefaults = [];
$tilesFile = __DIR__ . '/tiles.php';
if (is_readable($tilesFile)) {
    $loaded = require $tilesFile;
    if (is_array($loaded)) $tileDefaults = $loaded;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QuantuSphere Web</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;900&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap" rel="stylesheet">
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
            --bg: #FFFFFF; --surface: #FFFFFF; --text: #1D2A35;
            --text-muted: #5B6672; --border: #E2E5E9;
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
            margin: 0; padding: 20px 16px 40px; min-height: 100vh;
            position: relative;
        }
        body::before {
            content: '';
            position: fixed;
            top: -240px; left: 50%;
            transform: translateX(-50%);
            width: 900px; height: 500px;
            background: radial-gradient(ellipse at center, var(--primary-light), transparent 65%);
            pointer-events: none;
            z-index: -1;
        }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-thumb { background: #C9C0AC; border-radius: 8px; }
        ::-webkit-scrollbar-thumb:hover { background: #B8863C; }

        .page { max-width: 1500px; margin: 0 auto; }

        .page-header {
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px; margin-bottom: 22px; flex-wrap: wrap;
        }
        h1 {
            font-size: 1.4rem; font-weight: 900; margin: 0;
            display: flex; align-items: center; gap: 8px;
        }
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

        /* Hero */
        .hero { margin: 0 0 22px; padding: 4px 2px; }
        .hero-greet {
            font-size: 2rem; font-weight: 900;
            letter-spacing: -0.5px; color: var(--text);
            margin: 0 0 6px; line-height: 1.15;
        }
        .hero-greet .wave { display: inline-block; margin-right: 6px; }
        .hero-sub { font-size: 0.95rem; color: var(--text-muted); font-weight: 500; }
        .hero-date { font-weight: 700; color: var(--text); }

        /* Toolbar above tiles */
        .tiles-toolbar {
            display: flex; justify-content: flex-start;
            margin: 0 0 14px;
        }
        .tiles-settings-btn {
            display: inline-flex; align-items: center; gap: 8px;
            background: var(--surface); border: 1px solid var(--border);
            color: var(--text-muted);
            padding: 8px 14px; border-radius: 999px;
            font-family: inherit; font-size: 0.8rem; font-weight: 700;
            cursor: pointer; transition: all 0.15s;
        }
        .tiles-settings-btn:hover {
            border-color: var(--primary); color: var(--primary);
        }
        .tiles-settings-btn i { font-size: 1.05rem; }

        /* Tiles grid */
        .tiles {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
            gap: 16px;
        }
        @media (max-width: 560px) {
            .tiles { grid-template-columns: repeat(2, 1fr); gap: 12px; }
        }

        .tile {
            position: relative;
            display: flex; flex-direction: column;
            justify-content: space-between;
            min-height: 175px; padding: 20px;
            border-radius: 18px;
            background: var(--tile-bg, var(--primary));
            color: #fff; text-decoration: none;
            overflow: hidden; isolation: isolate;
            box-shadow: 0 4px 16px rgba(29,42,53,0.14);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .tile::before {
            content: '';
            position: absolute;
            top: -55%; right: -30%;
            width: 100%; height: 200%;
            background: radial-gradient(circle at 30% 30%, rgba(255,255,255,0.24), transparent 62%);
            pointer-events: none; z-index: -1;
        }
        .tile::after {
            content: '';
            position: absolute; inset: 0;
            background: linear-gradient(180deg, transparent 55%, rgba(0,0,0,0.14));
            pointer-events: none; z-index: -1;
        }
        .tile:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 30px rgba(29,42,53,0.28);
        }
        .tile:active { transform: translateY(-1px); }
        .tile:focus-visible { outline: 3px solid var(--primary); outline-offset: 3px; }

        .tile-top {
            display: flex; align-items: flex-start;
            justify-content: space-between; gap: 8px;
        }
        .tile-icon {
            font-size: 2.6rem; line-height: 1;
            display: inline-flex; align-items: center; justify-content: center;
            color: #fff;
            text-shadow: 0 2px 6px rgba(0,0,0,0.22);
        }
        .tile-icon i { font-size: inherit; line-height: 1; }

        .tile-badge {
            display: inline-flex; align-items: center; gap: 5px;
            background: rgba(255,255,255,0.22); color: #fff;
            font-size: 0.66rem; font-weight: 800;
            padding: 3px 9px; border-radius: 999px;
            border: 1px solid rgba(255,255,255,0.35);
            backdrop-filter: blur(4px);
            letter-spacing: 0.3px;
        }
        .tile-badge::before {
            content: '';
            display: inline-block;
            width: 6px; height: 6px; border-radius: 50%;
            background: #fff;
            animation: livePulse 1.6s ease-in-out infinite;
        }
        @keyframes livePulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.35; } }

        .tile-body { margin-top: 14px; }
        .tile-title { font-size: 1.15rem; font-weight: 800; letter-spacing: -0.2px; margin-bottom: 3px; line-height: 1.2; }
        .tile-sub { font-size: 0.76rem; font-weight: 500; opacity: 0.9; line-height: 1.45; }

        .tiles-empty {
            grid-column: 1 / -1;
            padding: 40px 20px; text-align: center;
            background: var(--surface); border: 1px dashed var(--border);
            border-radius: 12px; color: var(--text-muted); font-size: 0.9rem;
        }

        /* ── Settings modal ─────────────────────────────────────── */
        .tiles-modal-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(29,42,53,0.55);
            backdrop-filter: blur(3px);
            z-index: 500;
            align-items: center; justify-content: center;
            padding: 20px;
        }
        .tiles-modal-overlay.open { display: flex; animation: modalFade 0.15s ease-out; }
        @keyframes modalFade { from { opacity: 0; } to { opacity: 1; } }

        .tiles-modal {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            max-width: 520px; width: 100%;
            max-height: 85vh;
            display: flex; flex-direction: column;
            box-shadow: 0 20px 60px rgba(0,0,0,0.35);
            animation: modalSlide 0.18s ease-out;
        }
        @keyframes modalSlide {
            from { transform: translateY(-8px); opacity: 0; }
            to   { transform: translateY(0);    opacity: 1; }
        }

        .tiles-modal-head {
            display: flex; align-items: center; justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 2px solid var(--primary);
            background: var(--header-bg); color: var(--header-fg);
            border-radius: 12px 12px 0 0;
            font-weight: 700; font-size: 0.95rem;
        }
        .tiles-modal-close {
            background: none; border: none; color: var(--header-fg);
            font-size: 1.1rem; cursor: pointer; padding: 2px 8px;
            border-radius: 6px; font-family: inherit;
            opacity: 0.7; transition: opacity 0.15s;
        }
        .tiles-modal-close:hover { opacity: 1; }

        .tiles-modal-body {
            flex: 1; overflow-y: auto;
            padding: 14px 18px;
        }

        .tile-check {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 12px;
            border-radius: 9px; cursor: pointer;
            transition: background 0.12s;
            user-select: none;
        }
        .tile-check:hover { background: var(--primary-light); }
        .tile-check input {
            appearance: none; -webkit-appearance: none;
            width: 20px; height: 20px; flex-shrink: 0;
            border: 2px solid var(--border); border-radius: 5px;
            background: var(--surface); cursor: pointer;
            position: relative; transition: all 0.12s;
        }
        .tile-check input:checked {
            background: var(--primary); border-color: var(--primary);
        }
        .tile-check input:checked::after {
            content: '';
            position: absolute;
            left: 5px; top: 1px;
            width: 6px; height: 11px;
            border: solid white; border-width: 0 2px 2px 0;
            transform: rotate(45deg);
        }
        .tile-check-icon {
            width: 32px; height: 32px; flex-shrink: 0;
            display: inline-flex; align-items: center; justify-content: center;
            border-radius: 9px;
            font-size: 1.15rem; color: #fff;
            box-shadow: inset 0 0 0 1px rgba(0,0,0,0.06);
        }
        .tile-check-text { flex: 1; }
        .tile-check-name { font-size: 0.9rem; font-weight: 700; color: var(--text); }
        .tile-check-sub  { font-size: 0.72rem; color: var(--text-muted); margin-top: 2px; }

        .tiles-modal-foot {
            display: flex; align-items: center; gap: 10px;
            padding: 14px 20px;
            border-top: 1px solid var(--border);
            background: var(--bg);
            border-radius: 0 0 12px 12px;
        }
        .tiles-modal-foot .spacer { flex: 1; }
        .tiles-modal-reset {
            background: none; border: 1px solid var(--border);
            color: var(--text-muted); padding: 7px 14px;
            border-radius: 999px; font-family: inherit; font-size: 0.78rem;
            font-weight: 700; cursor: pointer; transition: all 0.15s;
        }
        .tiles-modal-reset:hover { border-color: var(--danger); color: var(--danger); }
        .tiles-modal-save {
            background: var(--primary); border: 1px solid var(--primary);
            color: white; padding: 7px 18px; border-radius: 999px;
            font-family: inherit; font-size: 0.82rem; font-weight: 700;
            cursor: pointer; transition: background 0.15s;
        }
        .tiles-modal-save:hover { background: var(--primary-dark); }

        @media (max-width: 560px) {
            body { padding: 14px 12px 30px; }
            h1 { font-size: 1.15rem; }
            .hero-greet { font-size: 1.5rem; }
            .hero-sub { font-size: 0.85rem; }
            .tile { padding: 14px; min-height: 145px; border-radius: 14px; }
            .tile-icon { font-size: 2rem; }
            .tile-title { font-size: 1rem; }
            .tile-sub { font-size: 0.7rem; }
            .tile-badge { font-size: 0.6rem; padding: 2px 7px; }
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
                            <a href="index.php" class="menu-item"><span class="menu-item-icon"><i class="ti ti-home"></i></span><span>الرئيسية</span></a>
                            <a href="dashboard.php" class="menu-item"><span class="menu-item-icon"><i class="ti ti-layout-dashboard"></i></span><span>لوحة المتابعة</span></a>
                            <a href="stats.php" class="menu-item"><span class="menu-item-icon"><i class="ti ti-chart-bar"></i></span><span>الإحصائيات</span></a>
                            <a href="products.php" class="menu-item"><span class="menu-item-icon"><i class="ti ti-package"></i></span><span>الأصناف</span></a>
                            <a href="inventory.php" class="menu-item"><span class="menu-item-icon"><i class="ti ti-building-warehouse"></i></span><span>المخزون</span></a>
                            <a href="movements.php" class="menu-item"><span class="menu-item-icon"><i class="ti ti-file-text"></i></span><span>حركة المواد</span></a>
                            <a href="serial-movements.php" class="menu-item"><span class="menu-item-icon"><i class="ti ti-barcode"></i></span><span>حركة الأرقام التسلسلية</span></a>
                            <a href="customers.php" class="menu-item"><span class="menu-item-icon"><i class="ti ti-users"></i></span><span>الزبائن</span></a>
                            <a href="salesmen.php" class="menu-item"><span class="menu-item-icon"><i class="ti ti-user-star"></i></span><span>البائعون</span></a>
                            <a href="accounts.php" class="menu-item"><span class="menu-item-icon"><i class="ti ti-wallet"></i></span><span>الحسابات</span></a>
                            <a href="bills.php" class="menu-item"><span class="menu-item-icon"><i class="ti ti-receipt"></i></span><span>أنماط الفواتير</span></a>
                            <a href="cost-centers.php" class="menu-item"><span class="menu-item-icon"><i class="ti ti-briefcase"></i></span><span>مراكز التكلفة</span></a>
                            <a href="customer-statement.php" class="menu-item"><span class="menu-item-icon"><i class="ti ti-file-description"></i></span><span>كشف حساب العميل</span></a>
                            <a href="exchange-rates.php" class="menu-item"><span class="menu-item-icon"><i class="ti ti-currency-exchange"></i></span><span>أسعار الصرف</span></a>
                        </div>
                        <div class="menu-section">
                            <div class="menu-section-title">التفضيلات</div>
                            <button type="button" class="menu-item" id="themeToggleBtn">
                                <span class="menu-item-icon" id="themeToggleIcon"><i class="ti ti-sun"></i></span>
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
                <h1><i class="ti ti-home"></i> QuantuSphere Web</h1>
            </div>
        </div>

        <div class="hero">
            <div class="hero-greet">مرحباً بك<span class="wave">👋</span></div>
            <div class="hero-sub">اختر قسماً للبدء — <span class="hero-date" id="todayDate">—</span></div>
        </div>

        <div class="tiles-toolbar">
            <button type="button" class="tiles-settings-btn" id="tilesSettingsBtn">
                <i class="ti ti-adjustments"></i>
                <span>تخصيص الأقسام</span>
            </button>
        </div>

        <div class="tiles" id="tilesGrid"></div>
    </div>

    <!-- Settings modal -->
    <div class="tiles-modal-overlay" id="tilesModal" role="dialog" aria-hidden="true">
        <div class="tiles-modal">
            <div class="tiles-modal-head">
                <span>⚙️ تخصيص الأقسام</span>
                <button type="button" class="tiles-modal-close" id="tilesModalClose" title="إغلاق (Esc)">✕</button>
            </div>
            <div class="tiles-modal-body" id="tilesModalBody"></div>
            <div class="tiles-modal-foot">
                <button type="button" class="tiles-modal-reset" id="tilesModalReset">↺ استعادة الافتراضي</button>
                <span class="spacer"></span>
                <button type="button" class="tiles-modal-save" id="tilesModalSave">حفظ</button>
            </div>
        </div>
    </div>

    <script>
        // ── Tile definitions (single source of truth) ──────────────
        const TILE_DEFS = [
            { key: 'dashboard', href: 'dashboard.php', icon: 'ti ti-layout-dashboard',
              title: 'لوحة المتابعة', sub: 'المتابعة الحية للفواتير والحركات',
              bg: 'linear-gradient(135deg, #C9A050 0%, #8B6428 100%)', badge: 'مباشر' },
            { key: 'stats', href: 'stats.php', icon: 'ti ti-chart-line',
              title: 'الإحصائيات', sub: 'تحليل المبيعات حسب الفترة والعملة',
              bg: 'linear-gradient(135deg, #14b8a6 0%, #0d766e 100%)' },
            { key: 'products', href: 'products.php', icon: 'ti ti-package',
              title: 'الأصناف', sub: 'الأكثر مبيعاً وتاريخ المبيعات',
              bg: 'linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%)' },
            { key: 'inventory', href: 'inventory.php', icon: 'ti ti-building-warehouse',
              title: 'المخزون', sub: 'الأرصدة الحالية حسب المستودع',
              bg: 'linear-gradient(135deg, #4C7B60 0%, #2F4C3B 100%)' },
            { key: 'movements', href: 'movements.php', icon: 'ti ti-file-text',
              title: 'حركة المواد', sub: 'سجل الإدخال والإخراج لكل صنف',
              bg: 'linear-gradient(135deg, #0ea5e9 0%, #0369a1 100%)' },
            { key: 'serial-movements', href: 'serial-movements.php', icon: 'ti ti-barcode',
              title: 'الأرقام التسلسلية', sub: 'تتبّع IMEI / SN للحركات',
              bg: 'linear-gradient(135deg, #6366f1 0%, #4338ca 100%)' },
            { key: 'customers', href: 'customers.php', icon: 'ti ti-users',
              title: 'الزبائن', sub: 'دليل الزبائن والأرصدة والعناوين',
              bg: 'linear-gradient(135deg, #f43f5e 0%, #be123c 100%)' },
            { key: 'salesmen', href: 'salesmen.php', icon: 'ti ti-user-star',
              title: 'مندوبو المبيعات', sub: 'ترتيب البائعين ومبيعاتهم',
              bg: 'linear-gradient(135deg, #f59e0b 0%, #b45309 100%)' },
            { key: 'accounts', href: 'accounts.php', icon: 'ti ti-report-money',
              title: 'الحسابات', sub: 'دليل الحسابات وكشوف الحركات',
              bg: 'linear-gradient(135deg, #10b981 0%, #047857 100%)' },
            { key: 'bills', href: 'bills.php', icon: 'ti ti-receipt',
              title: 'أنماط الفواتير', sub: 'إعدادات الفواتير وحساباتها',
              bg: 'linear-gradient(135deg, #a855f7 0%, #7e22ce 100%)' },
            { key: 'cost-centers', href: 'cost-centers.php', icon: 'ti ti-briefcase',
              title: 'مراكز التكلفة', sub: 'ملخص وكشوف مراكز التكلفة',
              bg: 'linear-gradient(135deg, #64748b 0%, #334155 100%)' },
            { key: 'customer-statement', href: 'customer-statement.php', icon: 'ti ti-file-description',
              title: 'كشف حساب العميل', sub: 'ملخص وكشوف حسابات العملاء',
              bg: 'linear-gradient(135deg, #8b6488 0%, #55334c 100%)' },
            { key: 'exchange-rates', href: 'exchange-rates.php', icon: 'ti ti-currency-exchange',
              title: 'أسعار الصرف', sub: 'ملخص وكشوف أسعار الصرف',
              bg: 'linear-gradient(135deg, #658b64 0%, #33553f 100%)' },
        ];

        // Install-level defaults from tiles.php
        const TILE_DEFAULTS = <?= json_encode($tileDefaults, JSON_UNESCAPED_UNICODE) ?>;

        const TILES_LS_KEY = 'dashboardVisibleTiles';

        // Resolve which tiles are visible right now:
        //   1. localStorage override (per browser), if present
        //   2. tiles.php defaults (install-level), if key exists
        //   3. true (fallback — no config entry = show)
        function resolveVisible() {
            let override = null;
            try {
                const raw = localStorage.getItem(TILES_LS_KEY);
                if (raw) override = JSON.parse(raw);
            } catch (e) {}
            const out = {};
            for (const t of TILE_DEFS) {
                if (override && Object.prototype.hasOwnProperty.call(override, t.key)) {
                    out[t.key] = !!override[t.key];
                } else if (Object.prototype.hasOwnProperty.call(TILE_DEFAULTS, t.key)) {
                    out[t.key] = !!TILE_DEFAULTS[t.key];
                } else {
                    out[t.key] = true;
                }
            }
            return out;
        }

        function renderTiles(visible) {
            const grid = document.getElementById('tilesGrid');
            const shown = TILE_DEFS.filter(t => visible[t.key]);
            if (!shown.length) {
                grid.innerHTML = '<div class="tiles-empty">لا توجد أقسام مفعّلة. اضغط "تخصيص الأقسام" لإعادة تفعيلها.</div>';
                return;
            }
            let html = '';
            for (const t of shown) {
                const badge = t.badge ? `<span class="tile-badge">${t.badge}</span>` : '';
                html += `
                    <a href="${t.href}" class="tile" style="--tile-bg: ${t.bg};">
                        <div class="tile-top">
                            <span class="tile-icon"><i class="${t.icon}"></i></span>
                            ${badge}
                        </div>
                        <div class="tile-body">
                            <div class="tile-title">${t.title}</div>
                            <div class="tile-sub">${t.sub}</div>
                        </div>
                    </a>`;
            }
            grid.innerHTML = html;
        }

        // ── Settings modal ────────────────────────────────────────
        const modal     = document.getElementById('tilesModal');
        const modalBody = document.getElementById('tilesModalBody');

        function openModal() {
            // Build rows once per open so the checkbox state reflects
            // the current visibility each time.
            const visible = resolveVisible();
            let html = '';
            for (const t of TILE_DEFS) {
                const checked = visible[t.key] ? 'checked' : '';
                html += `
                    <label class="tile-check">
                        <input type="checkbox" data-key="${t.key}" ${checked}>
                        <span class="tile-check-icon" style="background: ${t.bg};">
                            <i class="${t.icon}"></i>
                        </span>
                        <span class="tile-check-text">
                            <span class="tile-check-name">${t.title}</span>
                            <span class="tile-check-sub">${t.sub}</span>
                        </span>
                    </label>`;
            }
            modalBody.innerHTML = html;
            modal.classList.add('open');
            modal.setAttribute('aria-hidden', 'false');
        }

        function closeModal() {
            modal.classList.remove('open');
            modal.setAttribute('aria-hidden', 'true');
        }

        document.getElementById('tilesSettingsBtn').addEventListener('click', openModal);
        document.getElementById('tilesModalClose').addEventListener('click', closeModal);
        modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
        });

        document.getElementById('tilesModalSave').addEventListener('click', () => {
            const checks = modalBody.querySelectorAll('input[type="checkbox"]');
            const next = {};
            checks.forEach(cb => { next[cb.dataset.key] = cb.checked; });
            try { localStorage.setItem(TILES_LS_KEY, JSON.stringify(next)); } catch (e) {}
            renderTiles(next);
            closeModal();
        });

        document.getElementById('tilesModalReset').addEventListener('click', () => {
            // Forget the per-browser override; keep the modal open so the
            // user sees the checkboxes snap back to install defaults.
            try { localStorage.removeItem(TILES_LS_KEY); } catch (e) {}
            const visible = resolveVisible();
            modalBody.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                cb.checked = !!visible[cb.dataset.key];
            });
        });

        // ── Theme toggle ──────────────────────────────────────────
        (function initThemeToggle() {
            const btn  = document.getElementById('themeToggleBtn');
            const icon = document.getElementById('themeToggleIcon');
            if (!btn || !icon) return;
            const THEMES = ['warm', 'bright', 'dark', 'classic'];
            const ICONS  = {
                warm:    '<i class="ti ti-sun"></i>',
                bright:  '<i class="ti ti-brightness-up"></i>',
                dark:    '<i class="ti ti-moon"></i>',
                classic: '<i class="ti ti-palette"></i>',
            };
            function explicit() {
                const v = document.documentElement.getAttribute('data-theme');
                return THEMES.includes(v) ? v : null;
            }
            function effective() {
                return explicit() || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'warm');
            }
            function update() { icon.innerHTML = ICONS[effective()]; }
            update();
            btn.addEventListener('click', () => {
                const cur  = effective();
                const next = THEMES[(THEMES.indexOf(cur) + 1) % THEMES.length];
                document.documentElement.setAttribute('data-theme', next);
                try { localStorage.setItem('dashboardTheme', next); } catch (e) {}
                update();
            });
        })();

        // ── Hamburger ─────────────────────────────────────────────
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

        // ── Today's date ──────────────────────────────────────────
        (function setTodayDate() {
            const el = document.getElementById('todayDate');
            if (!el) return;
            try {
                const fmt = new Intl.DateTimeFormat('ar-EG-u-nu-latn', {
                    weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
                });
                el.textContent = fmt.format(new Date());
            } catch (e) {
                el.textContent = new Date().toLocaleDateString('en-GB');
            }
        })();

        // ── Bootstrap ─────────────────────────────────────────────
        renderTiles(resolveVisible());
    </script>
</body>
</html>