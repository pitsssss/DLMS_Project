<section id="notifications" class="section-anchor mb-4">
    <h5 class="text-secondary mb-3">الإشعارات والتقارير | Notifications & Reports</h5>
    <div class="card card-dev">
        <div class="card-body action-grid">
            @foreach([
                ['list_notifications', 'Notifications'],
                ['mark_notification_read', 'Mark Read'],
                ['reports_overview', 'Reports Overview'],
                ['audit_logs', 'Audit Logs'],
                ['admin_application_status_history', 'App Status History'],
            ] as [$action, $label])
                <form method="POST" action="{{ $devRoutes['action'] }}" class="d-inline">
                    @csrf
                    <input type="hidden" name="action" value="{{ $action }}">
                    <button type="submit" class="btn btn-sm btn-outline-syrtak">{{ $label }}</button>
                </form>
            @endforeach
            <form method="POST" action="{{ $devRoutes['action'] }}" class="d-inline">
                @csrf
                <input type="hidden" name="action" value="list_notifications">
                <input type="hidden" name="unread_only" value="1">
                <button type="submit" class="btn btn-sm btn-secondary font-en">Unread Only</button>
            </form>
        </div>
    </div>
</section>
