<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Fixtures;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

/**
 * Keeps what was reported, for tests that boot their own application and therefore cannot
 * use Testbench's Exceptions fake.
 */
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

    public function render($request, Throwable $e): never
    {
        throw $e;
    }

    public function renderForConsole($output, Throwable $e): void
    {
        //
    }

    /**
     * @param  class-string<Throwable>  $type
     * @return list<Throwable>
     */
    public function of(string $type): array
    {
        return array_values(array_filter($this->reported, fn (Throwable $e): bool => $e instanceof $type));
    }
}
