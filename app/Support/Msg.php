<?php

namespace App\Support;

final class Msg
{
    public static function get(string $key, array $replace = []): string
    {
        return __("messages.{$key}", $replace);
    }
}
