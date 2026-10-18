<?php

namespace Materom\Vigil\Hooks;

use Illuminate\Routing\Events\PreparingResponse;
use Materom\Vigil\Core;
use Materom\Vigil\ExecutionStage;
use Materom\Vigil\State\RequestState;
use Throwable;

/**
 * @internal
 */
final class PreparingResponseListener
{
    /**
     * @param  Core<RequestState>  $vigil
     */
    public function __construct(
        private Core $vigil,
    ) {
        //
    }

    public function __invoke(PreparingResponse $event): void
    {
        try {
            if ($this->vigil->executionStageIs(ExecutionStage::Action)) {
                $this->vigil->stage(ExecutionStage::Render);
            }
        } catch (Throwable $e) {
            $this->vigil->report($e, handled: true);
        }
    }
}
