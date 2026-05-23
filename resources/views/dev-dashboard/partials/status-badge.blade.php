@php
    $map = [
        'draft' => 'secondary',
        'documents_under_review' => 'info',
        'documents_rejected' => 'danger',
        'payment_pending' => 'warning',
        'payment_completed' => 'success',
        'appointment_pending' => 'warning',
        'in_testing' => 'primary',
        'waiting_retest' => 'warning',
        'approved' => 'success',
        'license_issued' => 'success',
        'rejected' => 'danger',
        'cancelled' => 'dark',
        'administrative_review' => 'info',
        'pending' => 'warning',
        'awaiting_confirmation' => 'warning',
        'confirmed' => 'info',
        'executed' => 'success',
        'failed' => 'danger',
        'completed' => 'success',
        'under_verification' => 'info',
    ];
    $class = $map[$status] ?? 'secondary';
@endphp
<span class="badge bg-{{ $class }} font-en">{{ $status }}</span>
