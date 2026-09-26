<?php
// index.php — landing page. The live dashboard now lives in dashboard.php.
require_once 'config.php';
require_once 'auth.php';
requireLogin(false);
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
        /* Soft themed glow behind the header */
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
            display: none; position: absolute;
            top: calc(100% + 8px); right: 0;
            width: max-content; max-width: calc(100vw - 24px);
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: 0 10px 30px rgba(29,42,53,0.15);
            padding: 6px; z-index: 300;
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

        /* ---------- Hero ---------- */
        .hero { margin: 0 0 26px; padding: 4px 2px; }
        .hero-greet {
            font-size: 2rem; font-weight: 900;
            letter-spacing: -0.5px; color: var(--text);
            margin: 0 0 6px; line-height: 1.15;
        }
        .hero-greet .wave { display: inline-block; margin-right: 6px; }
        .hero-sub {
            font-size: 0.95rem; color: var(--text-muted); font-weight: 500;
        }
        .hero-date { font-weight: 700; color: var(--text); }

        /* ---------- Tiles grid ---------- */
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
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 175px;
            padding: 20px;
            border-radius: 18px;
            background: var(--tile-bg, var(--primary));
            color: #fff;
            text-decoration: none;
            overflow: hidden;
            isolation: isolate;
            box-shadow: 0 4px 16px rgba(29,42,53,0.14);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        /* Soft highlight in the top corner */
        .tile::before {
            content: '';
            position: absolute;
            top: -55%; right: -30%;
            width: 100%; height: 200%;
            background: radial-gradient(circle at 30% 30%, rgba(255,255,255,0.24), transparent 62%);
            pointer-events: none;
            z-index: -1;
        }
        /* Grounding shadow at the bottom */
        .tile::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, transparent 55%, rgba(0,0,0,0.14));
            pointer-events: none;
            z-index: -1;
        }
        .tile:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 30px rgba(29,42,53,0.28);
        }
        .tile:active { transform: translateY(-1px); }
        .tile:focus-visible {
            outline: 3px solid var(--primary);
            outline-offset: 3px;
        }

        .tile-top {
            display: flex; align-items: flex-start;
            justify-content: space-between; gap: 8px;
        }
        .tile-icon {
            font-size: 2.6rem;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            text-shadow: 0 2px 6px rgba(0,0,0,0.22);
        }
        .tile-icon i {
            font-size: inherit;
            line-height: 1;
        }
        .tile-badge {
            display: inline-flex; align-items: center; gap: 5px;
            background: rgba(255,255,255,0.22);
            color: #fff;
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
        @keyframes livePulse {
            0%, 100% { opacity: 1; }
            50%      { opacity: 0.35; }
        }

        .tile-body { margin-top: 14px; }
        .tile-title {
            font-size: 1.15rem; font-weight: 800;
            letter-spacing: -0.2px; margin-bottom: 3px; line-height: 1.2;
        }
        .tile-sub {
            font-size: 0.76rem; font-weight: 500;
            opacity: 0.9; line-height: 1.45;
        }

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
                            <a href="index.php" class="menu-item"><span class="tile-icon"><i class="ti ti-home"></i></span><span>الرئيسية</span></a>
                            <a href="dashboard.php" class="menu-item"><span class="tile-icon"><i class="ti ti-layout-dashboard"></i></span><span>لوحة المتابعة</span></a>
                            <a href="stats.php" class="menu-item"><span class="tile-icon"><i class="ti ti-chart-bar"></i></span><span>الإحصائيات</span></a>
                            <a href="products.php" class="menu-item"><span class="tile-icon"><i class="ti ti-package"></i></span><span>الأصناف</span></a>
                            <a href="inventory.php" class="menu-item"><span class="tile-icon"><i class="ti ti-building-warehouse"></i></span><span>المخزون</span></a>
                            <a href="movements.php" class="menu-item"><span class="tile-icon"><i class="ti ti-file-text"></i></span><span>حركة المواد</span></a>
                            <a href="serial-movements.php" class="menu-item"><span class="tile-icon"><i class="ti ti-barcode"></i></span><span>حركة الأرقام التسلسلية</span></a>
                            <a href="customers.php" class="menu-item"><span class="tile-icon"><i class="ti ti-users"></i></span><span>الزبائن</span></a>
                            <a href="salesmen.php" class="menu-item"><span class="tile-icon"><i class="ti ti-user"></i></span><span>البائعون</span></a>
                            <a href="accounts.php" class="menu-item"><span class="tile-icon"><i class="ti ti-wallet"></i></span><span>الحسابات</span></a>
                            <a href="bills.php" class="menu-item"><span class="tile-icon"><i class="ti ti-receipt"></i></span><span>أنماط الفواتير</span></a>
                            <a href="cost-centers.php" class="menu-item"><span class="tile-icon"><i class="ti ti-briefcase"></i></span><span>مراكز التكلفة</span></a>
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
                                <span class="menu-item-icon"><i class="ti ti-logout"></i></span>
                                <span>تسجيل الخروج</span>
                            </a>
                        </div>
                    </div>
                </div>
                <h1>🏠 QuantuSphere Web</h1>
            </div>
        </div>

        <div class="hero">
            <div class="hero-greet">مرحباً بك<span class="wave">👋</span></div>
            <div class="hero-sub">اختر قسماً للبدء — <span class="hero-date" id="todayDate">—</span></div>
        </div>

        <div class="tiles">
            <!-- Live dashboard — featured tile with a pulsing "مباشر" badge -->
            <a href="dashboard.php" class="tile" style="--tile-bg: linear-gradient(135deg, #C9A050 0%, #8B6428 100%);">
                <div class="tile-top">
                    <span class="tile-icon"><i class="ti ti-layout-dashboard"></i></span>
                    <span class="tile-badge">مباشر</span>
                </div>
                <div class="tile-body">
                    <div class="tile-title">لوحة المتابعة</div>
                    <div class="tile-sub">المتابعة الحية للفواتير والحركات</div>
                </div>
            </a>

            <a href="stats.php" class="tile" style="--tile-bg: linear-gradient(135deg, #14b8a6 0%, #0d766e 100%);">
                <div class="tile-top"><span class="tile-icon"><i class="ti ti-chart-line"></i></span></div>
                <div class="tile-body">
                    <div class="tile-title">الإحصائيات</div>
                    <div class="tile-sub">تحليل المبيعات حسب الفترة والعملة</div>
                </div>
            </a>

            <a href="products.php" class="tile" style="--tile-bg: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);">
                <div class="tile-top"><span class="tile-icon"><i class="ti ti-package"></i></span></div>
                <div class="tile-body">
                    <div class="tile-title">الأصناف</div>
                    <div class="tile-sub">الأكثر مبيعاً وتاريخ المبيعات</div>
                </div>
            </a>

            <a href="inventory.php" class="tile" style="--tile-bg: linear-gradient(135deg, #4C7B60 0%, #2F4C3B 100%);">
                <div class="tile-top"><span class="tile-icon"><i class="ti ti-building-warehouse"></i></span></div>
                <div class="tile-body">
                    <div class="tile-title">المخزون</div>
                    <div class="tile-sub">الأرصدة الحالية حسب المستودع</div>
                </div>
            </a>
            
            <a href="serial-movements.php" class="tile" style="--tile-bg: linear-gradient(135deg, #6366f1 0%, #4338ca 100%);">
                <div class="tile-top"><span class="tile-icon"><i class="ti ti-barcode"></i></span></div>
                <div class="tile-body">
                    <div class="tile-title">الأرقام التسلسلية</div>
                    <div class="tile-sub">تتبّع IMEI / SN للحركات</div>
                </div>
            </a>

            <a href="customers.php" class="tile" style="--tile-bg: linear-gradient(135deg, #f43f5e 0%, #be123c 100%);">
                <div class="tile-top"><span class="tile-icon"><i class="ti ti-users"></i></span></div>
                <div class="tile-body">
                    <div class="tile-title">الزبائن</div>
                    <div class="tile-sub">دليل الزبائن والأرصدة والعناوين</div>
                </div>
            </a>

            <a href="salesmen.php" class="tile" style="--tile-bg: linear-gradient(135deg, #f59e0b 0%, #b45309 100%);">
                <div class="tile-top"><span class="tile-icon"><i class="ti ti-user-star"></i></span></div>
                <div class="tile-body">
                    <div class="tile-title">البائعون</div>
                    <div class="tile-sub">ترتيب البائعين ومبيعاتهم</div>
                </div>
            </a>

            <a href="accounts.php" class="tile" style="--tile-bg: linear-gradient(135deg, #10b981 0%, #047857 100%);">
                <div class="tile-top"><span class="tile-icon"><i class="ti ti-report-money"></i></span></div>
                <div class="tile-body">
                    <div class="tile-title">الحسابات</div>
                    <div class="tile-sub">دليل الحسابات وكشوف الحركات</div>
                </div>
            </a>

            <a href="bills.php" class="tile" style="--tile-bg: linear-gradient(135deg, #a855f7 0%, #7e22ce 100%);">
                <div class="tile-top"><span class="tile-icon"><i class="ti ti-receipt"></i></span></div>
                <div class="tile-body">
                    <div class="tile-title">أنماط الفواتير</div>
                    <div class="tile-sub">إعدادات الفواتير وحساباتها</div>
                </div>
            </a>

            <a href="cost-centers.php" class="tile" style="--tile-bg: linear-gradient(135deg, #64748b 0%, #334155 100%);">
                <div class="tile-top"><span class="tile-icon"><i class="ti ti-briefcase"></i></span></div>
                <div class="tile-body">
                    <div class="tile-title">مراكز التكلفة</div>
                    <div class="tile-sub">ملخص وكشوف مراكز التكلفة</div>
                </div>
            </a>
        </div>
    </div>

    <script>
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

        // ---------- Hamburger menu ----------
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

        // ---------- Today's date in Arabic (Latin digits) ----------
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
    </script>
</body>
</html>