<?php

namespace Materom\Vigil\Hooks;

use Illuminate\Queue\Events\JobProcessing;
use Materom\Vigil\Compatibility;
use Materom\Vigil\Core;
use Materom\Vigil\State\CommandState;
use Materom\Vigil\State\RequestState;
use Throwable;

/**
 * @internal
 */
final class PolyfillContextHydration
{
    /**
     * @param  Core<RequestState|CommandState>  $vigil
     */
    public function __construct(
        private Core $vigil,
    ) {
        //
    }

    public function __invoke(JobProcessing $event): void
    {
        try {
            $nightwatch = $event->job->payload()['nightwatch'] ?? [];

            Compatibility::$context = [
                'nightwatch_trace_id' => $nightwatch['nightwatch_trace_id'] ?? null,
                'nightwatch_should_sample' => $nightwatch['nightwatch_should_sample'] ?? null,
                'nightwatch_user_id' => $nightwatch['nightwatch_user_id'] ?? '',
            ];
        } catch (Throwable $e) {
            $this->vigil->report($e, handled: true);

            Compatibility::$context = [];
        }
    }
}
