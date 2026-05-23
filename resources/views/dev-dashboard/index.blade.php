@extends('dev-dashboard.layout')

@section('content')
<header class="dev-header py-3 px-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h1 class="h4 mb-0">سيرتك | <span class="font-en">SYRTAK</span></h1>
            <p class="mb-0 small opacity-75 font-en">DLMS API Testing Dashboard — Internal Developer Tool</p>
        </div>
        <div class="text-end font-en small">
            <div>API: <code class="text-white">{{ $apiBaseUrl }}</code></div>
            <form method="POST" action="{{ $devRoutes['reset'] }}" class="d-inline mt-1">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-light">Clear Session</button>
            </form>
        </div>
    </div>
</header>

<div class="container-fluid">
    <div class="row">
        <nav class="col-lg-2 dev-sidebar py-3 px-2">
            <ul class="nav flex-column">
                @foreach([
                    ['overview', 'نظرة عامة'],
                    ['response', 'الاستجابة'],
                    ['auth', 'المصادقة'],
                    ['applications', 'الطلبات'],
                    ['documents', 'الوثائق'],
                    ['payments', 'المدفوعات'],
                    ['appointments', 'المواعيد'],
                    ['licenses', 'الرخص'],
                    ['notifications', 'الإشعارات'],
                    ['ai-agent', 'الوكيل الذكي'],
                    ['scenarios', 'سيناريوهات'],
                ] as [$id, $label])
                    <li class="nav-item">
                        <a class="nav-link" href="#{{ $id }}">{{ $label }}</a>
                    </li>
                @endforeach
            </ul>
            <hr>
            <p class="small text-danger px-2">لا تستخدم في الإنتاج<br><span class="font-en">Never use in production</span></p>
        </nav>

        <main class="col-lg-10 py-3 px-3">
            @include('dev-dashboard.partials.status-panel')
            @include('dev-dashboard.partials.response-viewer')

            <section id="overview" class="section-anchor mb-4">
                <h5 class="text-secondary mb-3">نظرة عامة | Overview</h5>
                <div class="card card-dev">
                    <div class="card-body action-grid">
                        <form method="POST" action="{{ $devRoutes['action'] }}" class="d-inline">
                            @csrf
                            <input type="hidden" name="action" value="ping">
                            <button type="submit" class="btn btn-syrtak">Ping API</button>
                        </form>
                        <form method="POST" action="{{ $devRoutes['action'] }}" class="d-inline">
                            @csrf
                            <input type="hidden" name="action" value="refresh_application">
                            <button type="submit" class="btn btn-outline-syrtak">Refresh Application</button>
                        </form>
                        <form method="POST" action="{{ $devRoutes['reset'] }}" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-outline-danger">Reset Dashboard Session</button>
                        </form>
                    </div>
                    <div class="card-footer small font-en text-muted">
                        Stores tokens and IDs in Laravel session after each successful API call.
                    </div>
                </div>
            </section>

            @include('dev-dashboard.partials.auth-section')
            @include('dev-dashboard.partials.applications-section')
            @include('dev-dashboard.partials.documents-section')
            @include('dev-dashboard.partials.payments-section')
            @include('dev-dashboard.partials.appointments-tests-section')
            @include('dev-dashboard.partials.licenses-fines-section')
            @include('dev-dashboard.partials.notifications-reports-section')
            @include('dev-dashboard.partials.ai-agent-section')

            <section id="scenarios" class="section-anchor mb-5">
                <h5 class="text-secondary mb-3">سيناريوهات جاهزة | One-click Scenarios</h5>
                <div class="card card-dev">
                    <div class="card-body action-grid">
                        @foreach([
                            ['prepare_citizen', 'Prepare Citizen'],
                            ['prepare_new_application', 'New Application Draft'],
                            ['approve_all_documents', 'Approve All Documents'],
                            ['complete_mock_payment', 'Complete Mock Payment'],
                            ['pass_current_test', 'Pass Current Test'],
                            ['issue_license', 'Issue License'],
                            ['ai_create_application', 'AI Create Application'],
                        ] as [$scenario, $label])
                            <form method="POST" action="{{ $devRoutes['action'] }}" class="d-inline">
                                @csrf
                                <input type="hidden" name="action" value="scenario_{{ $scenario }}">
                                <button type="submit" class="btn btn-sm btn-syrtak">{{ $label }}</button>
                            </form>
                        @endforeach
                    </div>
                    <div class="card-footer small text-muted">
                        Scenarios stop on first failed step and show all executed steps in the response viewer.
                    </div>
                </div>
            </section>
        </main>
    </div>
</div>
@endsection
