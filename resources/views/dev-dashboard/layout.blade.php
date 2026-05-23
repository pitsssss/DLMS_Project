<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SYRTAK | DLMS API Testing Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --syrtak-green-dark: #002623;
            --syrtak-green: #054239;
            --syrtak-green-light: #428177;
            --syrtak-gold: #988561;
            --syrtak-gold-light: #B9A779;
            --syrtak-cream: #EDEBE0;
            --syrtak-umber: #260F14;
            --syrtak-neutral-dark: #161616;
            --syrtak-neutral: #3D3A3B;
            --syrtak-bg: #F8F8F6;
            --syrtak-surface: #F1F3F2;
        }

        body {
            font-family: 'Cairo', 'Inter', sans-serif;
            background: var(--syrtak-bg);
            color: var(--syrtak-neutral-dark);
        }

        .font-en { font-family: 'Inter', sans-serif; direction: ltr; text-align: left; }

        .dev-header {
            background: linear-gradient(135deg, var(--syrtak-green-dark), var(--syrtak-green));
            color: #fff;
            border-bottom: 4px solid var(--syrtak-gold);
        }

        .dev-sidebar {
            background: #fff;
            border-inline-end: 1px solid #dde3e1;
            min-height: calc(100vh - 88px);
        }

        .dev-sidebar .nav-link {
            color: var(--syrtak-neutral);
            border-radius: .5rem;
            margin-bottom: .25rem;
            font-size: .92rem;
        }

        .dev-sidebar .nav-link:hover,
        .dev-sidebar .nav-link.active {
            background: var(--syrtak-surface);
            color: var(--syrtak-green-dark);
            font-weight: 600;
        }

        .card-dev {
            border: 1px solid #e2e8e6;
            border-radius: .75rem;
            box-shadow: 0 1px 2px rgba(0, 38, 35, .06);
        }

        .card-dev .card-header {
            background: var(--syrtak-surface);
            border-bottom: 1px solid #e2e8e6;
            font-weight: 600;
            color: var(--syrtak-green-dark);
        }

        .btn-syrtak {
            background: var(--syrtak-green);
            border-color: var(--syrtak-green);
            color: #fff;
        }

        .btn-syrtak:hover {
            background: var(--syrtak-green-dark);
            border-color: var(--syrtak-green-dark);
            color: #fff;
        }

        .btn-outline-syrtak {
            border-color: var(--syrtak-green-light);
            color: var(--syrtak-green);
        }

        .btn-outline-syrtak:hover {
            background: var(--syrtak-green-light);
            color: #fff;
        }

        .badge-exists { background: #198754; }
        .badge-missing { background: #6B1F2A; }

        .json-viewer {
            background: #161616;
            color: #EDEBE0;
            border-radius: .5rem;
            padding: 1rem;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: .8rem;
            max-height: 480px;
            overflow: auto;
            white-space: pre-wrap;
            word-break: break-word;
            direction: ltr;
            text-align: left;
        }

        .status-pill {
            font-size: .75rem;
            padding: .2rem .55rem;
            border-radius: 999px;
        }

        .env-badge {
            background: var(--syrtak-gold);
            color: var(--syrtak-umber);
        }

        .section-anchor { scroll-margin-top: 1rem; }

        .action-grid .btn { margin: .15rem; }
    </style>
    @stack('styles')
</head>
<body>
    @yield('content')
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    @stack('scripts')
</body>
</html>
