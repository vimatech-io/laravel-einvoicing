<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Vimatech\EInvoicing\EInvoicingServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            EInvoicingServiceProvider::class,
        ];
    }
}
