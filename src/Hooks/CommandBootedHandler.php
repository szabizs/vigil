<?php

namespace Materom\Vigil\Hooks;

use Illuminate\Contracts\Foundation\Application;
use Materom\Vigil\Core;
use Materom\Vigil\ExecutionStage;
use Materom\Vigil\State\CommandState;
use Throwable;

/**
 * @internal
 */
final class CommandBootedHandler
{
    /**
     * @param  Core<CommandState>  $vigil
     */
    public function __construct(
        private Core $vigil,
    ) {
        //
    }

    public function __invoke(Application $app): void
    {
        try {
            $this->vigil->stage(ExecutionStage::Action);
        } catch (Throwable $e) {
            $this->vigil->report($e, handled: true);
        }
    }
}
