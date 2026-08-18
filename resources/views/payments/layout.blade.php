{{-- SYRTAK payment return shell — mobile-first, display only --}}
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#002623">
    <title>@yield('title') — {{ __('messages.payments.return.brand_full') }}</title>
    <style>
        :root {
            --syrtak-forest: #054239;
            --syrtak-deep: #002623;
            --syrtak-gold: #B9A779;
            --syrtak-gold-dark: #988561;
            --syrtak-sand: #EDEBE0;
            --syrtak-ink: #161616;
            --syrtak-muted: #3D3A3B;
            --syrtak-card: #ffffff;
            --syrtak-success-soft: #e8f2ef;
            --syrtak-process-soft: #f3f1e8;
            --syrtak-cancel-soft: #f4f2ee;
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            min-height: 100%;
        }

        body {
            min-height: 100vh;
            min-height: 100dvh;
            background:
                radial-gradient(ellipse 120% 80% at 50% -20%, rgba(185, 167, 121, 0.22), transparent 55%),
                linear-gradient(165deg, #edebe0 0%, #e4e1d4 48%, #d9d5c6 100%);
            color: var(--syrtak-ink);
            font-family: "Segoe UI", Tahoma, "Noto Sans Arabic", "Arabic UI Text", system-ui, -apple-system, sans-serif;
            -webkit-font-smoothing: antialiased;
            line-height: 1.55;
        }

        .page {
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 24px 16px 32px;
        }

        .card {
            width: 100%;
            max-width: 420px;
            background: var(--syrtak-card);
            border: 1px solid var(--syrtak-gold);
            border-radius: 16px;
            box-shadow: 0 12px 40px rgba(0, 38, 35, 0.12);
            overflow: hidden;
        }

        .brand {
            background: linear-gradient(160deg, #054239 0%, #002623 72%);
            padding: 22px 20px 18px;
            text-align: center;
            border-bottom: 3px solid var(--syrtak-gold-dark);
        }

        .brand img {
            display: block;
            margin: 0 auto 10px;
            width: 56px;
            height: 56px;
            object-fit: contain;
        }

        .brand-name {
            margin: 0;
            font-size: 1.35rem;
            font-weight: 700;
            color: #fff;
            letter-spacing: 0.02em;
        }

        .brand-sub {
            margin: 4px 0 0;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--syrtak-sand);
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        .body {
            padding: 28px 22px 26px;
            text-align: center;
        }

        .icon-wrap {
            width: 64px;
            height: 64px;
            margin: 0 auto 18px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .icon-wrap--success { background: var(--syrtak-success-soft); color: var(--syrtak-forest); }
        .icon-wrap--processing { background: var(--syrtak-process-soft); color: var(--syrtak-forest); }
        .icon-wrap--cancel { background: var(--syrtak-cancel-soft); color: var(--syrtak-muted); }

        .icon-wrap svg {
            width: 32px;
            height: 32px;
            display: block;
        }

        h1 {
            margin: 0 0 12px;
            font-size: 1.35rem;
            font-weight: 700;
            color: var(--syrtak-deep);
            line-height: 1.35;
        }

        .lead {
            margin: 0 0 10px;
            font-size: 1rem;
            font-weight: 600;
            color: var(--syrtak-forest);
        }

        .copy {
            margin: 0 0 8px;
            font-size: 0.95rem;
            color: var(--syrtak-muted);
        }

        .instruction {
            margin: 18px 0 0;
            padding: 14px 14px;
            background: var(--syrtak-sand);
            border-radius: 10px;
            border: 1px solid rgba(185, 167, 121, 0.45);
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--syrtak-deep);
        }

        .footer {
            margin-top: 22px;
            padding-top: 16px;
            border-top: 1px solid #ebe8dc;
            font-size: 0.78rem;
            color: var(--syrtak-muted);
        }

        @media (min-width: 480px) {
            .body { padding: 32px 28px 28px; }
            h1 { font-size: 1.45rem; }
        }
    </style>
</head>
<body>
    <main class="page">
        <article class="card" role="status" aria-live="polite">
            <header class="brand">
                <img
                    src="{{ asset('branding/syrtak-license-logo.png') }}"
                    alt=""
                    width="56"
                    height="56"
                    decoding="async"
                >
                <p class="brand-name">{{ __('messages.payments.return.brand_ar') }}</p>
                <p class="brand-sub">{{ __('messages.payments.return.brand_en') }}</p>
            </header>
            <div class="body">
                @yield('content')
                <p class="footer">{{ __('messages.payments.return.security_note') }}</p>
            </div>
        </article>
    </main>
</body>
</html>
