<?php

namespace Materom\Vigil\Hooks;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Queue\Events\JobPopping;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Materom\Vigil\Core;
use Materom\Vigil\State\CommandState;
use Throwable;

/**
 * @internal
 */
final class WorkerLifecycleListener
{
    /**
     * @param  Core<CommandState>  $vigil
     */
    public function __construct(
        private Core $vigil,
    ) {
        //
    }

    public function __invoke(Looping|JobPopping|JobProcessing|WorkerStopping|CommandFinished $event): void
    {
        try {
            match ($event::class) {
                Looping::class, WorkerStopping::class => $this->vigil->finishExecution()->waitForExecution(),
                CommandFinished::class => $event->command === 'queue:work' && $this->vigil->finishExecution()->waitForExecution(),
                JobPopping::class => $this->vigil->prepareForNextJob(),
                JobProcessing::class => $this->vigil->prepareForJob($event->job),
            };
        } catch (Throwable $e) {
            $this->vigil->report($e, handled: true);
        }
    }
}
