<?php

namespace App\Modules\Payments\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Requests\ConfirmApplicationPaymentRequest;
use App\Modules\Payments\Requests\StoreApplicationPaymentRequest;
use App\Modules\Payments\Resources\PaymentResource;
use App\Modules\Payments\Services\ApplicationPaymentService;
use App\Modules\Payments\Services\PaymentProviderManager;
use Illuminate\Http\Request;

class ApplicationPaymentController extends Controller
{
    public function showFee(Request $request, int $application, ApplicationPaymentService $payments)
    {
        $result = $payments->getFeeForApplication($request->user(), $application);
        $fee = $result['fee'];
        $applicationModel = $result['application'];

        return $this->successResponse([
            'application_id' => $applicationModel->id,
            'application_number' => $applicationModel->application_number,
            'application_status' => $applicationModel->status->value,
            'fee' => [
                'id' => $fee->id,
                'name' => $fee->name,
                'code' => $fee->code,
                'amount' => $fee->amount,
                'currency' => $fee->currency,
            ],
        ], 'Application fee retrieved successfully.');
    }

    public function index(Request $request, int $application, ApplicationPaymentService $payments)
    {
        $list = $payments->listForApplication($request->user(), $application);

        return $this->successResponse(
            PaymentResource::collection($list)->resolve(),
            'Payments retrieved successfully.'
        );
    }

    public function status(Request $request, int $application, int $payment, ApplicationPaymentService $payments)
    {
        $data = $payments->getPaymentStatus($request->user(), $application, $payment);

        return $this->successResponse($data, 'Payment status retrieved successfully.');
    }

    public function store(
        StoreApplicationPaymentRequest $request,
        int $application,
        ApplicationPaymentService $payments,
        PaymentProviderManager $providers
    ) {
        $metadata = $request->validated('metadata') ?? null;

        $result = $payments->createPendingPayment(
            $request->user(),
            $application,
            is_array($metadata) ? $metadata : null
        );

        $payment = $result['payment'];

        if ($providers->isStripe()) {
            return $this->successResponse([
                'payment' => (new PaymentResource($payment))->resolve(),
                'provider' => 'stripe',
                'checkout_url' => $result['checkout_url'] ?? null,
                'publishable_key' => $result['publishable_key'] ?? config('payment.stripe.publishable_key'),
            ], 'Stripe checkout session created successfully.');
        }

        return $this->successResponse(
            new PaymentResource($payment),
            'Payment initiated. Confirm when funds are transferred (mock).'
        );
    }

    public function confirm(
        ConfirmApplicationPaymentRequest $request,
        int $application,
        int $payment,
        ApplicationPaymentService $payments
    ) {
        $model = $payments->confirmMockPayment($request->user(), $application, $payment);
        $model->loadMissing('application');

        return $this->successResponse(
            [
                'payment' => (new PaymentResource($model))->resolve(),
                'application' => [
                    'id' => $model->application->id,
                    'status' => $model->application->status->value,
                ],
            ],
            'Payment confirmed successfully. You can book an appointment when slots are available.'
        );
    }
}
