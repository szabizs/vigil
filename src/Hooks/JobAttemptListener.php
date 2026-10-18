<?php

namespace Materom\Vigil\Hooks;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Materom\Vigil\Core;
use Materom\Vigil\State\CommandState;
use Throwable;

/**
 * @internal
 */
final class JobAttemptListener
{
    /**
     * @param  Core<CommandState>  $vigil
     */
    public function __construct(
        private Core $vigil,
    ) {
        //
    }

    public function __invoke(JobProcessed|JobReleasedAfterException|JobFailed $event): void
    {
        try {
            $this->vigil->jobAttempt($event);
        } catch (Throwable $e) {
            $this->vigil->report($e, handled: true);
        }
    }
}
