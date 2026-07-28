<?php

namespace Tests\Support;

use PHPUnit\Framework\Assert;

trait AssertsArabicLabels
{
    /**
     * @param  array<string, mixed>|list<mixed>  $payload
     * @param  list<string>  $fields
     */
    protected function assertNoRawTranslationKeys(array $payload, array $fields = ['label', 'message', 'title', 'body', 'status_label', 'action_label', 'field_label', 'old_label', 'new_label', 'reason_label', 'type_label', 'role_label', 'provider_label']): void
    {
        $this->walkPayloadForTranslationKeys($payload, $fields);
    }

    /**
     * @param  mixed  $payload
     * @param  list<string>  $fields
     */
    private function walkPayloadForTranslationKeys(mixed $payload, array $fields): void
    {
        if (! is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array($key, $fields, true) && is_string($value)) {
                Assert::assertFalse(
                    (bool) preg_match('/^(messages|validation|enums|statuses|permissions|actions|documents|dashboard)\./', $value),
                    "Expected translated label for [{$key}], got raw key: {$value}"
                );
            }

            if (is_array($value)) {
                $this->walkPayloadForTranslationKeys($value, $fields);
            }
        }
    }
}
