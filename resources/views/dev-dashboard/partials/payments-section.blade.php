<section id="payments" class="section-anchor mb-4">
    <h5 class="text-secondary mb-3">المدفوعات | Payments</h5>
    <div class="card card-dev">
        <div class="card-body action-grid">
            @foreach([
                ['application_fee', 'Get Fee'],
                ['list_payments', 'List Payments'],
                ['create_payment', 'Create Payment'],
                ['confirm_mock_payment', 'Confirm Mock Payment'],
                ['payment_status', 'Payment Status'],
            ] as [$action, $label])
                <form method="POST" action="{{ route('dev-dashboard.action') }}" class="d-inline">
                    @csrf
                    <input type="hidden" name="action" value="{{ $action }}">
                    <button type="submit" class="btn btn-sm btn-outline-syrtak">{{ $label }}</button>
                </form>
            @endforeach

            @if(session('checkout_url'))
                <a href="{{ session('checkout_url') }}" target="_blank" rel="noopener" class="btn btn-sm btn-warning font-en">Open Stripe Checkout</a>
            @endif
        </div>
        <div class="card-footer small">
            <span class="badge bg-secondary font-en">{{ $paymentProvider }}</span>
            <span class="text-muted ms-2 font-en">Stripe webhooks are not tested from this dashboard. Use Stripe CLI or Dashboard.</span>
        </div>
    </div>
</section>
