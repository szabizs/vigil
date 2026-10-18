<?php

namespace Materom\Vigil\Hooks;

use Illuminate\Contracts\Foundation\Application;
use Materom\Vigil\Core;
use Materom\Vigil\ExecutionStage;
use Materom\Vigil\State\RequestState;
use Throwable;

/**
 * @internal
 */
final class RequestBootedHandler
{
    /**
     * @param  Core<RequestState>  $vigil
     */
    public function __construct(
        private Core $vigil,
    ) {
        //
    }

    public function __invoke(Application $app): void
    {
        try {
            $this->vigil->stage(ExecutionStage::BeforeMiddleware);
        } catch (Throwable $e) {
            $this->vigil->report($e, handled: true);
        }
    }
}
