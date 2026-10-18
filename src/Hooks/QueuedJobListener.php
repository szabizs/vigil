<?php

namespace Materom\Vigil\Hooks;

use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobQueueing;
use Materom\Vigil\Core;
use Materom\Vigil\State\CommandState;
use Materom\Vigil\State\RequestState;
use Throwable;

/**
 * @internal
 */
final class QueuedJobListener
{
    /**
     * @param  Core<RequestState|CommandState>  $vigil
     */
    public function __construct(
        private Core $vigil,
    ) {
        //
    }

    public function __invoke(JobQueueing|JobQueued $event): void
    {
        try {
            $this->vigil->queuedJob($event);
        } catch (Throwable $e) {
            $this->vigil->report($e, handled: true);
        }
    }
}
