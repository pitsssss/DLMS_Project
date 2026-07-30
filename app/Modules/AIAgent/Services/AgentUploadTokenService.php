<?php

namespace App\Modules\AIAgent\Services;

use App\Exceptions\ApiException;
use App\Modules\AIAgent\Enums\DocumentFlowState;
use App\Modules\AIAgent\Models\AIAgentSession;
use Illuminate\Support\Str;

class AgentUploadTokenService
{
    public function issuePlainToken(): string
    {
        return Str::random(64);
    }

    public function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * @param  array<string, mixed>  $documentFlow
     * @return array{application_id: int, required_document_id: int, label: string}
     */
    public function assertActiveToken(array $documentFlow, string $plainToken, AIAgentSession $session): array
    {
        $state = DocumentFlowState::tryFrom((string) ($documentFlow['state'] ?? ''))
            ?? DocumentFlowState::Idle;

        if (! $state->allowsFileUpload()) {
            throw new ApiException(
                'لا يُتوقع رفع ملف في هذه المرحلة من مسار الوثائق.',
                422,
                [],
                [],
                'FILE_UPLOAD_NOT_EXPECTED'
            );
        }

        $status = (string) ($documentFlow['upload_token_status'] ?? '');
        $storedHash = (string) ($documentFlow['upload_token_hash'] ?? '');
        $expiresAt = (string) ($documentFlow['upload_token_expires_at'] ?? '');

        if ($storedHash === '' || $status === '') {
            throw new ApiException(
                'رمز رفع الوثيقة غير صالح.',
                422,
                [],
                [],
                'INVALID_UPLOAD_TOKEN'
            );
        }

        if ($status === 'processing') {
            throw new ApiException(
                'جارٍ معالجة رفع سابق لنفس الرمز. يرجى الانتظار قليلًا ثم المحاولة مجددًا.',
                422,
                [],
                [],
                'UPLOAD_TOKEN_ALREADY_PROCESSING'
            );
        }

        if ($status === 'consumed') {
            throw new ApiException(
                'تم استخدام رمز رفع الوثيقة مسبقًا.',
                422,
                [],
                [],
                'UPLOAD_TOKEN_ALREADY_USED'
            );
        }

        if ($status !== 'active') {
            throw new ApiException(
                'رمز رفع الوثيقة غير صالح.',
                422,
                [],
                [],
                'INVALID_UPLOAD_TOKEN'
            );
        }

        if ($expiresAt !== '' && now()->greaterThan($expiresAt)) {
            throw new ApiException(
                'انتهت صلاحية رمز رفع الوثيقة. يرجى اختيار الوثيقة مجددًا.',
                422,
                [],
                [],
                'UPLOAD_TOKEN_EXPIRED'
            );
        }

        if (! hash_equals($storedHash, $this->hash($plainToken))) {
            throw new ApiException(
                'رمز رفع الوثيقة غير صالح.',
                422,
                [],
                [],
                'INVALID_UPLOAD_TOKEN'
            );
        }

        $applicationId = (int) ($documentFlow['application_id'] ?? 0);
        $requiredDocumentId = (int) ($documentFlow['required_document_id'] ?? 0);
        $label = (string) ($documentFlow['required_document_label'] ?? 'الوثيقة');

        if ($applicationId <= 0 || $requiredDocumentId <= 0) {
            throw new ApiException(
                'رمز رفع الوثيقة غير صالح.',
                422,
                [],
                [],
                'INVALID_UPLOAD_TOKEN'
            );
        }

        return [
            'application_id' => $applicationId,
            'required_document_id' => $requiredDocumentId,
            'label' => $label,
        ];
    }
}
