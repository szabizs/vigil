<?php

namespace Materom\Vigil\Hooks;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Materom\Vigil\Core;
use Materom\Vigil\ExecutionStage;
use Materom\Vigil\State\RequestState;
use Throwable;

/**
 * @internal
 */
final class RequestHandledListener
{
    /**
     * @param  Core<RequestState>  $vigil
     */
    public function __construct(
        private Core $vigil,
    ) {
        //
    }

    public function __invoke(RequestHandled $event): void
    {
        try {
            $this->vigil->stage(ExecutionStage::Sending);
        } catch (Throwable $e) {
            $this->vigil->report($e, handled: true);
        }
    }
}
