<?php

namespace Materom\Vigil\Hooks;

use Illuminate\Foundation\Events\Terminating;
use Materom\Vigil\Compatibility;
use Materom\Vigil\Core;
use Materom\Vigil\ExecutionStage;
use Materom\Vigil\State\CommandState;
use Materom\Vigil\State\RequestState;
use Throwable;

/**
 * @internal
 */
final class TerminatingListener
{
    /**
     * @param  Core<RequestState|CommandState>  $vigil
     */
    public function __construct(
        private Core $vigil,
    ) {
        //
    }

    public function __invoke(Terminating $event): void
    {
        if (! Compatibility::$terminatingEventExists) {
            return;
        }

        try {
            $this->vigil->stage(ExecutionStage::Terminating);
        } catch (Throwable $e) {
            $this->vigil->report($e, handled: true);
        }
    }
}
