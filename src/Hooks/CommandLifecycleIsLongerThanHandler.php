<?php

namespace Materom\Vigil\Hooks;

use Carbon\Carbon;
use Materom\Vigil\Core;
use Materom\Vigil\ExecutionStage;
use Materom\Vigil\State\CommandState;
use Symfony\Component\Console\Input\InputInterface;
use Throwable;

/**
 * @internal
 */
final class CommandLifecycleIsLongerThanHandler
{
    /**
     * @param  Core<CommandState>  $vigil
     */
    public function __construct(
        private Core $vigil,
    ) {
        //
    }

    public function __invoke(Carbon $startedAt, InputInterface $input, int $status): void
    {
        try {
            $this->vigil->stage(ExecutionStage::End);
        } catch (Throwable $e) {
            $this->vigil->report($e, handled: true);
        }

        try {
            $this->vigil->command($input, $status);
        } catch (Throwable $e) {
            $this->vigil->report($e, handled: true);
        }

        $this->vigil->finishExecution();
    }
}
