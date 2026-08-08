<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Symfony Request::create() injects Accept-Language: en-us,en;q=0.5 by default.
        // Clear it so HTTP tests match production clients that omit the header
        // (resolver then uses users.language / configured default ar).
        $this->withServerVariables([
            'HTTP_ACCEPT_LANGUAGE' => '',
        ]);
    }
}
