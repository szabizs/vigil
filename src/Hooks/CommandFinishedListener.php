<?php

namespace Materom\Vigil\Hooks;

use Illuminate\Console\Events\CommandFinished;
use Materom\Vigil\Compatibility;
use Materom\Vigil\Core;
use Materom\Vigil\ExecutionStage;
use Materom\Vigil\State\CommandState;
use Throwable;

/**
 * @internal
 */
final class CommandFinishedListener
{
    /**
     * @param  Core<CommandState>  $vigil
     */
    public function __construct(
        private Core $vigil,
    ) {
        //
    }

    public function __invoke(CommandFinished $event): void
    {
        try {
            if ($this->vigil->capturingCommandNamed($event->command) && ! Compatibility::$terminatingEventExists) {
                $this->vigil->stage(ExecutionStage::Terminating);
            }
        } catch (Throwable $e) {
            $this->vigil->report($e, handled: true);
        }
    }
}
