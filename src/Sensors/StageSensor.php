<?php

namespace Materom\Vigil\Sensors;

use Materom\Vigil\Clock;
use Materom\Vigil\ExecutionStage;
use Materom\Vigil\State\CommandState;
use Materom\Vigil\State\RequestState;

use function round;

/**
 * @internal
 */
final class StageSensor
{
    public function __construct(
        private RequestState|CommandState $executionState,
        private Clock $clock,
    ) {
        //
    }

    public function __invoke(ExecutionStage $executionStage): void
    {
        $nowMicrotime = $this->clock->microtime();

        $this->executionState->stageDurations[$this->executionState->stage->value] = (int) round(($nowMicrotime - $this->executionState->currentExecutionStageStartedAtMicrotime) * 1_000_000);
        $this->executionState->stage = $executionStage;
        $this->executionState->currentExecutionStageStartedAtMicrotime = $nowMicrotime;
    }
}
