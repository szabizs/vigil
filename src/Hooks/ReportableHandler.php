<?php

namespace Materom\Vigil\Hooks;

use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Materom\Vigil\Core;
use Materom\Vigil\State\CommandState;
use Materom\Vigil\State\RequestState;
use Throwable;

use function str_repeat;

/**
 * @internal
 */
final class ReportableHandler
{
    public ?string $reservedMemory;

    /**
     * @param  Core<RequestState|CommandState>  $vigil
     */
    public function __construct(
        private Core $vigil,
    ) {
        $this->reservedMemory = str_repeat('n', 32768);
    }

    public function __invoke(Throwable $e): void
    {
        if (HandleExceptions::$reservedMemory === null) {
            $this->reservedMemory = null;
        }

        if ($this->vigil->executionState->source === 'schedule') {
            return;
        }

        $this->vigil->report($e);
    }
}
