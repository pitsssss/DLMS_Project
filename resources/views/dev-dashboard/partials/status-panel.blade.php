<div class="card card-dev mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>لوحة الحالة | Status Panel</span>
        <span class="badge env-badge text-uppercase">{{ $environment }}</span>
    </div>
    <div class="card-body">
        <div class="row g-2 small">
            @foreach(['citizen_token', 'employee_token', 'admin_token'] as $tokenKey)
                <div class="col-md-4">
                    <strong class="font-en">{{ str_replace('_', ' ', $tokenKey) }}</strong>:
                    <span class="status-pill badge {{ ($status[$tokenKey] ?? '') === 'exists' ? 'badge-exists' : 'badge-missing' }}">
                        {{ $status[$tokenKey] ?? 'missing' }}
                    </span>
                    @if(!empty($status[$tokenKey.'_preview']))
                        <span class="text-muted font-en">({{ $status[$tokenKey.'_preview'] }})</span>
                    @endif
                </div>
            @endforeach

            @php
                $fields = [
                    'application_id' => 'Application ID',
                    'application_number' => 'Application #',
                    'application_status' => 'Application Status',
                    'payment_id' => 'Payment ID',
                    'appointment_id' => 'Appointment ID',
                    'license_id' => 'License ID',
                    'ai_agent_session_id' => 'AI Session',
                    'ai_agent_action_id' => 'AI Action',
                ];
            @endphp

            @foreach($fields as $key => $label)
                <div class="col-md-3 col-6">
                    <strong class="font-en">{{ $label }}</strong>:
                    @if($key === 'application_status' && !empty($status[$key]))
                        @include('dev-dashboard.partials.status-badge', ['status' => $status[$key]])
                    @else
                        <span class="font-en">{{ $status[$key] ?? '—' }}</span>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</div>
