<?php

namespace App\Modules\Payments\Controllers;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Support\RequestLocaleResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Public Stripe Checkout return pages — display only. Never mutate Payment/Fine.
 */
class PaymentReturnController extends Controller
{
    public function __construct(
        private readonly RequestLocaleResolver $locales,
    ) {}

    public function success(Request $request): Response
    {
        $locale = $this->applyDisplayLocale($request);
        $payment = $this->findPaymentBySessionId($request->query('session_id'));

        if ($payment !== null && $payment->status === PaymentStatus::Completed) {
            return $this->pageResponse('payments.success', [
                'locale' => $locale,
                'dir' => $locale === 'ar' ? 'rtl' : 'ltr',
                'state' => 'success',
            ]);
        }

        $variant = 'confirming';
        if ($payment === null) {
            $variant = 'inconclusive';
        } elseif ($payment->status === PaymentStatus::UnderVerification) {
            $variant = 'verifying';
        } elseif ($payment->status === PaymentStatus::Failed) {
            $variant = 'inconclusive';
        } elseif ($payment->status === PaymentStatus::Pending) {
            $variant = 'confirming';
        } else {
            $variant = 'inconclusive';
        }

        return $this->pageResponse('payments.processing', [
            'locale' => $locale,
            'dir' => $locale === 'ar' ? 'rtl' : 'ltr',
            'state' => 'processing',
            'variant' => $variant,
        ]);
    }

    public function processing(Request $request): Response
    {
        $locale = $this->applyDisplayLocale($request);

        return $this->pageResponse('payments.processing', [
            'locale' => $locale,
            'dir' => $locale === 'ar' ? 'rtl' : 'ltr',
            'state' => 'processing',
            'variant' => 'confirming',
        ]);
    }

    public function cancel(Request $request): Response
    {
        $locale = $this->applyDisplayLocale($request);

        return $this->pageResponse('payments.cancel', [
            'locale' => $locale,
            'dir' => $locale === 'ar' ? 'rtl' : 'ltr',
            'state' => 'cancelled',
        ]);
    }

    private function applyDisplayLocale(Request $request): string
    {
        $lang = strtolower(trim((string) $request->query('lang', '')));

        if ($this->locales->isSupported($lang)) {
            app()->setLocale($lang);

            return $lang;
        }

        $fallback = $this->locales->defaultLocale();
        app()->setLocale($fallback);

        return $fallback;
    }

    private function findPaymentBySessionId(mixed $sessionId): ?Payment
    {
        if (! is_string($sessionId)) {
            return null;
        }

        $sessionId = trim($sessionId);
        if ($sessionId === '' || strlen($sessionId) > 255) {
            return null;
        }

        // Opaque Stripe Checkout Session id shape: cs_… (test or live).
        if (! preg_match('/^cs_[A-Za-z0-9_]+$/', $sessionId)) {
            return null;
        }

        return Payment::query()
            ->where('provider', 'stripe')
            ->where('provider_reference', $sessionId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function pageResponse(string $view, array $data): Response
    {
        return response()
            ->view($view, $data)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }
}
