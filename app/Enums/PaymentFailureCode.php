<?php

namespace App\Enums;

use App\Support\Msg;

enum PaymentFailureCode: string
{
    case CheckoutCreationFailed = 'checkout_creation_failed';
    case SessionExpired = 'session_expired';
    case AsyncPaymentFailed = 'async_payment_failed';
    case AmountMismatch = 'amount_mismatch';
    case CurrencyMismatch = 'currency_mismatch';
    case ProviderReferenceConflict = 'provider_reference_conflict';
    case ProviderUnavailable = 'provider_unavailable';
    case VerificationFailed = 'verification_failed';
    case ObligationAlreadySettled = 'obligation_already_settled';
    case WorkflowConflict = 'workflow_conflict';
    case ProviderCurrencyUnsupported = 'provider_currency_unsupported';

    public function label(): string
    {
        return Msg::get('payments.failure_codes.'.$this->value);
    }
}
