<?php

namespace App\Support;

final class Msg
{
    public static function get(string $key, array $replace = []): string
    {
        return ArabicMessageTranslator::get($key, $replace);
    }
}
