<?php

namespace App\Enums;

enum DocumentRejectionReason: string
{
    case UnclearDocument = 'unclear_document';
    case WrongDocument = 'wrong_document';
    case ExpiredDocument = 'expired_document';
    case IncompleteDocument = 'incomplete_document';
    case Other = 'other';

    public function label(): string
    {
        return __('messages.documents.rejection_reasons.'.$this->value);
    }

    public function requiresDetails(): bool
    {
        return $this === self::Other;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Combobox / API options for dashboard clients.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
            ],
            self::cases()
        );
    }

    public function displayReason(?string $details): string
    {
        $details = $details !== null ? trim($details) : '';

        if ($this === self::Other) {
            return $details;
        }

        if ($details !== '') {
            return $this->label().' — '.$details;
        }

        return $this->label();
    }
}
