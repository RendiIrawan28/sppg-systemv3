<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#071e49">

    <title>{{ config('app.name', 'SPPG System') }}</title>

    @php
        $loginUrl = route('login');
        $dashboardUrl = route('v3.dashboard');
        $hasOfficialLogo = file_exists(public_path('images/logo-bgn.png'));
    @endphp

    <style>
        :root {
            --bgn-blue-950: #071e49;
            --bgn-blue-900: #0b2a60;
            --bgn-blue-800: #123a7a;
            --bgn-blue-100: #b5e0ea;
            --bgn-green: #92d05d;
            --bgn-gold: #d1b06c;
            --ink: #102033;
            --muted: #607187;
            --surface: rgba(255, 255, 255, 0.88);
            --surface-strong: #ffffff;
            --border: rgba(7, 30, 73, 0.14);
            --shadow: 0 28px 80px rgba(7, 30, 73, 0.18);
            --radius-xl: 32px;
            --radius-lg: 24px;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            color: var(--ink);
            background:
                radial-gradient(circle at top left, rgba(181, 224, 234, 0.78), transparent 34rem),
                radial-gradient(circle at 88% 10%, rgba(146, 208, 93, 0.2), transparent 30rem),
                radial-gradient(circle at 50% 100%, rgba(209, 176, 108, 0.16), transparent 28rem),
                linear-gradient(135deg, #f7fbff 0%, #eef7fb 46%, #f7fafc 100%);
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .page-shell {
            position: relative;
            min-height: 100vh;
            overflow: hidden;
            padding: 28px;
        }

        .page-shell::before,
        .page-shell::after {
            content: "";
            position: absolute;
            border-radius: 999px;
            pointer-events: none;
            z-index: 0;
        }

        .page-shell::before {
            width: 520px;
            height: 520px;
            right: -180px;
            top: -170px;
            background: linear-gradient(135deg, rgba(7, 30, 73, 0.2), rgba(181, 224, 234, 0.28));
        }

        .page-shell::after {
            width: 380px;
            height: 380px;
            left: -170px;
            bottom: -160px;
            background: linear-gradient(135deg, rgba(146, 208, 93, 0.18), rgba(209, 176, 108, 0.18));
        }

        .container {
            position: relative;
            z-index: 1;
            width: min(1180px, 100%);
            margin: 0 auto;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 38px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .brand-logo {
            display: grid;
            place-items: center;
            width: 54px;
            height: 54px;
            overflow: hidden;
            border-radius: 18px;
            background: linear-gradient(145deg, var(--bgn-blue-950), var(--bgn-blue-800));
            color: #ffffff;
            box-shadow: 0 14px 30px rgba(7, 30, 73, 0.24);
        }

        .brand-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            padding: 6px;
            background: white;
        }

        .brand-logo span {
            font-size: 15px;
            font-weight: 800;
            letter-spacing: 0.08em;
        }

        .brand-title {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .brand-title strong {
            font-size: 17px;
            letter-spacing: -0.02em;
            color: var(--bgn-blue-950);
        }

        .brand-title small {
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        .topbar-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .soft-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 1px solid var(--border);
            border-radius: 999px;
            padding: 10px 14px;
            background: rgba(255, 255, 255, 0.68);
            color: var(--bgn-blue-950);
            font-size: 13px;
            font-weight: 700;
            backdrop-filter: blur(16px);
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--bgn-green);
            box-shadow: 0 0 0 5px rgba(146, 208, 93, 0.16);
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            border-radius: 999px;
            border: 1px solid transparent;
            padding: 13px 20px;
            font-weight: 800;
            font-size: 14px;
            transition: transform 160ms ease, box-shadow 160ms ease, background 160ms ease;
            cursor: pointer;
        }

        .button:hover {
            transform: translateY(-1px);
        }

        .button-primary {
            color: #ffffff;
            background: linear-gradient(135deg, var(--bgn-blue-950), #0f3f86);
            box-shadow: 0 16px 34px rgba(7, 30, 73, 0.26);
        }

        .button-secondary {
            color: var(--bgn-blue-950);
            background: rgba(255, 255, 255, 0.84);
            border-color: var(--border);
        }

        .hero-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.06fr) minmax(360px, 0.94fr);
            gap: 28px;
            align-items: stretch;
        }

        .hero-card,
        .dashboard-card {
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.64);
            border-radius: var(--radius-xl);
            background: var(--surface);
            box-shadow: var(--shadow);
            backdrop-filter: blur(18px);
        }

        .hero-card {
            padding: clamp(28px, 5vw, 56px);
        }

        .hero-card::before {
            content: "";
            position: absolute;
            inset: 0;
            background:
                linear-gradient(135deg, rgba(7, 30, 73, 0.06), transparent 42%),
                radial-gradient(circle at 88% 18%, rgba(181, 224, 234, 0.54), transparent 18rem);
            pointer-events: none;
        }

        .hero-content {
            position: relative;
            z-index: 1;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            color: var(--bgn-blue-950);
            background: rgba(181, 224, 234, 0.46);
            border: 1px solid rgba(7, 30, 73, 0.1);
            border-radius: 999px;
            padding: 9px 13px;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .eyebrow::before {
            content: "";
            display: block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--bgn-gold);
        }

        h1 {
            max-width: 720px;
            margin: 24px 0 18px;
            color: var(--bgn-blue-950);
            font-size: clamp(38px, 6vw, 72px);
            line-height: 0.98;
            letter-spacing: -0.07em;
        }

        .hero-copy {
            max-width: 640px;
            margin: 0;
            color: #43566f;
            font-size: clamp(16px, 2vw, 19px);
            line-height: 1.7;
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
            margin-top: 30px;
        }

        .quick-stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-top: 34px;
        }

        .stat-card {
            border: 1px solid rgba(7, 30, 73, 0.1);
            border-radius: 22px;
            padding: 18px;
            background: rgba(255, 255, 255, 0.66);
        }

        .stat-card strong {
            display: block;
            color: var(--bgn-blue-950);
            font-size: 24px;
            line-height: 1;
            letter-spacing: -0.04em;
        }

        .stat-card span {
            display: block;
            margin-top: 8px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .dashboard-card {
            display: flex;
            min-height: 100%;
            flex-direction: column;
            padding: 24px;
            color: #ffffff;
            background:
                radial-gradient(circle at 78% 0%, rgba(181, 224, 234, 0.42), transparent 18rem),
                linear-gradient(160deg, var(--bgn-blue-950), #0c3471 64%, #0a2858);
        }

        .dashboard-card::after {
            content: "";
            position: absolute;
            right: -90px;
            bottom: -120px;
            width: 320px;
            height: 320px;
            border-radius: 50%;
            background: rgba(146, 208, 93, 0.18);
        }

        .panel-header {
            position: relative;
            z-index: 1;
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: flex-start;
            margin-bottom: 28px;
        }

        .panel-header h2 {
            margin: 0;
            font-size: 20px;
            letter-spacing: -0.02em;
        }

        .panel-header p {
            margin: 6px 0 0;
            color: rgba(255, 255, 255, 0.72);
            font-size: 13px;
            line-height: 1.5;
        }

        .mini-badge {
            white-space: nowrap;
            border-radius: 999px;
            padding: 8px 11px;
            color: var(--bgn-blue-950);
            background: var(--bgn-blue-100);
            font-size: 12px;
            font-weight: 900;
        }

        .workflow-list {
            position: relative;
            z-index: 1;
            display: grid;
            gap: 13px;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .workflow-item {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            padding: 15px;
            border: 1px solid rgba(255, 255, 255, 0.14);
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.08);
        }

        .workflow-number {
            display: grid;
            place-items: center;
            flex: 0 0 auto;
            width: 34px;
            height: 34px;
            border-radius: 12px;
            color: var(--bgn-blue-950);
            background: linear-gradient(135deg, var(--bgn-blue-100), #ffffff);
            font-size: 13px;
            font-weight: 900;
        }

        .workflow-item strong {
            display: block;
            margin-bottom: 4px;
            font-size: 14px;
        }

        .workflow-item span {
            display: block;
            color: rgba(255, 255, 255, 0.72);
            font-size: 12px;
            line-height: 1.5;
        }

        .module-strip {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            margin-top: 28px;
        }

        .module-card {
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 18px;
            background: rgba(255, 255, 255, 0.76);
            box-shadow: 0 18px 44px rgba(7, 30, 73, 0.08);
        }

        .module-icon {
            display: grid;
            place-items: center;
            width: 40px;
            height: 40px;
            margin-bottom: 14px;
            border-radius: 14px;
            color: var(--bgn-blue-950);
            background: linear-gradient(135deg, rgba(181, 224, 234, 0.9), rgba(146, 208, 93, 0.22));
            font-weight: 900;
        }

        .module-card strong {
            display: block;
            color: var(--bgn-blue-950);
            font-size: 14px;
            margin-bottom: 6px;
        }

        .module-card span {
            display: block;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.55;
        }

        .footer-note {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            margin-top: 24px;
            color: rgba(16, 32, 51, 0.64);
            font-size: 12px;
        }

        @media (max-width: 980px) {
            .hero-grid {
                grid-template-columns: 1fr;
            }

            .module-strip {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 680px) {
            .page-shell {
                padding: 18px;
            }

            .topbar {
                align-items: flex-start;
                flex-direction: column;
                margin-bottom: 22px;
            }

            .topbar-actions,
            .hero-actions,
            .footer-note {
                width: 100%;
                align-items: stretch;
                flex-direction: column;
            }

            .soft-pill {
                justify-content: center;
            }

            .button {
                width: 100%;
            }

            .quick-stats,
            .module-strip {
                grid-template-columns: 1fr;
            }

            .dashboard-card,
            .hero-card {
                border-radius: 24px;
            }
        }
    </style>
</head>
<body>
    <main class="page-shell">
        <div class="container">
            <header class="topbar">
                <a class="brand" href="{{ url('/') }}" aria-label="Beranda {{ config('app.name', 'SPPG System') }}">
                    <div class="brand-logo" aria-hidden="true">
                        @if ($hasOfficialLogo)
                            <img src="{{ asset('images/logo-bgn.png') }}" alt="Logo Badan Gizi Nasional">
                        @else
                            <span>BGN</span>
                        @endif
                    </div>
                    <div class="brand-title">
                        <strong>{{ config('app.name', 'SPPG System') }}</strong>
                        <small>Satuan Pelayanan Pemenuhan Gizi</small>
                    </div>
                </a>

                <div class="topbar-actions">
                    <span class="soft-pill"><span class="status-dot"></span> Sistem Operasional SPPG</span>
                    <a class="button button-secondary" href="{{ auth()->check() ? $dashboardUrl : $loginUrl }}">
                        {{ auth()->check() ? 'Buka Dashboard' : 'Masuk' }}
                    </a>
                </div>
            </header>

            <section class="hero-grid" aria-label="Informasi sistem SPPG">
                <article class="hero-card">
                    <div class="hero-content">
                        <span class="eyebrow">Platform Operasional Terpadu</span>
                        <h1>Kelola layanan gizi harian dengan alur yang rapi.</h1>
                        <p class="hero-copy">
                            Sistem ini membantu unit SPPG mengelola data penerima manfaat, perencanaan menu, kebutuhan bahan, produksi, distribusi, laporan, dan persetujuan dalam satu ruang kerja.
                        </p>

                        <div class="hero-actions">
                            <a class="button button-primary" href="{{ auth()->check() ? $dashboardUrl : $loginUrl }}">
                                {{ auth()->check() ? 'Lanjutkan ke Dashboard' : 'Masuk ke Sistem' }}
                                <span aria-hidden="true">→</span>
                            </a>
                            <a class="button button-secondary" href="#alur-operasional">
                                Lihat Alur Kerja
                            </a>
                        </div>

                        <div class="quick-stats" aria-label="Fokus sistem">
                            <div class="stat-card">
                                <strong>1</strong>
                                <span>Sumber data terpadu</span>
                            </div>
                            <div class="stat-card">
                                <strong>6</strong>
                                <span>Divisi operasional</span>
                            </div>
                            <div class="stat-card">
                                <strong>24/7</strong>
                                <span>Audit & riwayat</span>
                            </div>
                        </div>
                    </div>
                </article>

                <aside class="dashboard-card" id="alur-operasional" aria-label="Alur operasional utama">
                    <div class="panel-header">
                        <div>
                            <h2>Alur kerja utama</h2>
                            <p>Dari pendataan sampai laporan Kepala SPPG.</p>
                        </div>
                        <span class="mini-badge">SPPG</span>
                    </div>

                    <ol class="workflow-list">
                        <li class="workflow-item">
                            <span class="workflow-number">01</span>
                            <div>
                                <strong>Data penerima manfaat</strong>
                                <span>Asisten Lapangan mengelompokkan porsi kecil, porsi besar, dan buffer pelayanan.</span>
                            </div>
                        </li>
                        <li class="workflow-item">
                            <span class="workflow-number">02</span>
                            <div>
                                <strong>Perencanaan menu</strong>
                                <span>Ahli Gizi menyusun siklus, resep, gramasi, nilai gizi, dan estimasi bahan.</span>
                            </div>
                        </li>
                        <li class="workflow-item">
                            <span class="workflow-number">03</span>
                            <div>
                                <strong>Produksi dan distribusi</strong>
                                <span>Dapur, gudang, dan distribusi bekerja dari data menu yang sudah disetujui.</span>
                            </div>
                        </li>
                        <li class="workflow-item">
                            <span class="workflow-number">04</span>
                            <div>
                                <strong>Approval dan laporan</strong>
                                <span>Kepala SPPG memantau persetujuan, revisi, dan laporan operasional harian.</span>
                            </div>
                        </li>
                    </ol>
                </aside>
            </section>

            <section class="module-strip" aria-label="Modul sistem">
                <div class="module-card">
                    <div class="module-icon">AL</div>
                    <strong>Asisten Lapangan</strong>
                    <span>Data instansi, penerima manfaat, porsi, dan rencana distribusi.</span>
                </div>
                <div class="module-card">
                    <div class="module-icon">AG</div>
                    <strong>Ahli Gizi</strong>
                    <span>Siklus menu basah, resep, gramasi, validasi gizi, dan kebutuhan bahan.</span>
                </div>
                <div class="module-card">
                    <div class="module-icon">GD</div>
                    <strong>Gudang & Produksi</strong>
                    <span>Permintaan bahan, persiapan, pengolahan, pemorsian, dan serah terima.</span>
                </div>
                <div class="module-card">
                    <div class="module-icon">KS</div>
                    <strong>Kepala SPPG</strong>
                    <span>Persetujuan, audit, revisi, dan monitoring capaian layanan.</span>
                </div>
            </section>

            <footer class="footer-note">
                <span>© {{ now()->year }} {{ config('app.name', 'SPPG System') }}.</span>
                <span>Warna antarmuka mengikuti nuansa identitas Badan Gizi Nasional.</span>
            </footer>
        </div>
    </main>
</body>
</html>
