<?php

namespace App\Modules\Payments\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Requests\ConfirmFinePaymentRequest;
use App\Modules\Payments\Requests\StoreFinePaymentRequest;
use App\Modules\Payments\Resources\PaymentResource;
use App\Modules\Payments\Services\FinePaymentService;
use App\Modules\Payments\Services\PaymentProviderManager;
use Illuminate\Http\Request;

class FinePaymentController extends Controller
{
    public function store(
        StoreFinePaymentRequest $request,
        int $fine,
        FinePaymentService $payments,
        PaymentProviderManager $providers
    ) {
        $metadata = $request->validated('metadata') ?? null;

        $result = $payments->createPendingPayment(
            $request->user(),
            $fine,
            is_array($metadata) ? $metadata : null
        );

        $payment = $result['payment'];

        if ($providers->isStripe()) {
            return $this->successResponse([
                'payment' => (new PaymentResource($payment))->resolve(),
                'provider' => 'stripe',
                'checkout_url' => $result['checkout_url'] ?? null,
                'publishable_key' => $result['publishable_key'] ?? config('payment.stripe.publishable_key'),
            ], 'messages.payments.stripe_session');
        }

        return $this->successResponse(
            new PaymentResource($payment),
            'messages.payments.initiated_mock'
        );
    }

    public function status(Request $request, int $fine, int $payment, FinePaymentService $payments)
    {
        $data = $payments->getPaymentStatus($request->user(), $fine, $payment);

        return $this->successResponse($data, 'messages.payments.status');
    }

    public function confirm(
        ConfirmFinePaymentRequest $request,
        int $fine,
        int $payment,
        FinePaymentService $payments
    ) {
        $model = $payments->confirmMockPayment($request->user(), $fine, $payment);
        $model->loadMissing('fine');

        return $this->successResponse(
            [
                'payment' => (new PaymentResource($model))->resolve(),
                'fine' => [
                    'id' => $model->fine->id,
                    'status' => $model->fine->status->value,
                    'paid_at' => $model->fine->paid_at?->toIso8601String(),
                ],
            ],
            'messages.fines.payment_confirmed'
        );
    }
}
