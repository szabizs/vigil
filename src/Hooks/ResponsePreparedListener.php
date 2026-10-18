<?php

namespace Materom\Vigil\Hooks;

use Illuminate\Routing\Events\ResponsePrepared;
use Materom\Vigil\Core;
use Materom\Vigil\ExecutionStage;
use Materom\Vigil\State\RequestState;
use Throwable;

/**
 * @internal
 */
final class ResponsePreparedListener
{
    /**
     * @param  Core<RequestState>  $vigil
     */
    public function __construct(
        private Core $vigil,
    ) {
        //
    }

    public function __invoke(ResponsePrepared $event): void
    {
        try {
            if ($this->vigil->executionStageIs(ExecutionStage::Render)) {
                $this->vigil->stage(ExecutionStage::AfterMiddleware);
            }
        } catch (Throwable $e) {
            $this->vigil->report($e, handled: true);
        }
    }
}
