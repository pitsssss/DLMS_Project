<section id="applications" class="section-anchor mb-4">
    <h5 class="text-secondary mb-3">الطلبات | Applications</h5>
    <div class="card card-dev">
        <div class="card-body action-grid">
            @foreach([
                ['license_types', 'Get License Types'],
                ['service_types', 'Get Service Types'],
                ['create_application', 'Create Application'],
                ['list_applications', 'List Applications'],
                ['show_application', 'Show Current Application'],
                ['refresh_application', 'Refresh Application'],
                ['admin_application_status_history', 'Status History (Admin)'],
            ] as [$action, $label])
                <form method="POST" action="{{ route('dev-dashboard.action') }}" class="d-inline">
                    @csrf
                    <input type="hidden" name="action" value="{{ $action }}">
                    <button type="submit" class="btn btn-sm {{ $action === 'create_application' ? 'btn-syrtak' : 'btn-outline-syrtak' }}">{{ $label }}</button>
                </form>
            @endforeach
        </div>
        <div class="card-footer small text-muted font-en">
            Uses session: license_type_id, service_type_id, application_id
        </div>
    </div>
</section>
