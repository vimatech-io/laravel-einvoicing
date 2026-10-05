<?php

declare(strict_types=1);

namespace Vimatech\EInvoicing\Tests\Support;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

final class RecordingExceptionHandler implements ExceptionHandler
{
    /** @var list<Throwable> */
    public array $reported = [];

    public function report(Throwable $e): void
    {
        $this->reported[] = $e;
    }

    public function shouldReport(Throwable $e): bool
    {
        return true;
    }

    public function render($request, Throwable $e)
    {
        throw $e;
    }

    public function renderForConsole($output, Throwable $e): void
    {
        throw $e;
    }
}
