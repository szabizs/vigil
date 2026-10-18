<?php

namespace Materom\Vigil\Hooks;

use Illuminate\Console\Events\ScheduledTaskStarting;
use Materom\Vigil\Core;
use Materom\Vigil\State\CommandState;
use Throwable;

/**
 * @internal
 */
final class ScheduledTaskStartingListener
{
    /**
     * @param  Core<CommandState>  $vigil
     */
    public function __construct(
        private Core $vigil,
    ) {
        //
    }

    public function __invoke(ScheduledTaskStarting $event): void
    {
        try {
            $this->vigil->prepareForScheduledTask($event->task);
        } catch (Throwable $e) {
            $this->vigil->report($e, handled: true);
        }
    }
}
