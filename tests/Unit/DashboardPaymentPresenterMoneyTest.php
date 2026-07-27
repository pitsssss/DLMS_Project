<?php

namespace Tests\Unit;

use App\Modules\Dashboard\Support\DashboardPaymentPresenter;
use PHPUnit\Framework\TestCase;

class DashboardPaymentPresenterMoneyTest extends TestCase
{
    public function test_formats_exact_decimal_strings_without_float_precision_loss(): void
    {
        $this->assertSame('50.00', DashboardPaymentPresenter::money('50.00'));
        $this->assertSame('10.25', DashboardPaymentPresenter::money('10.25'));
        $this->assertSame('0.50', DashboardPaymentPresenter::money('0.50'));
        $this->assertSame('9999999999.99', DashboardPaymentPresenter::money('9999999999.99'));
    }
}
