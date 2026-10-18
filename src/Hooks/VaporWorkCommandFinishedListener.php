<?php

namespace Materom\Vigil\Hooks;

use Illuminate\Console\Events\CommandFinished;
use Materom\Vigil\Core;
use Materom\Vigil\State\CommandState;

/**
 * @internal
 */
final class VaporWorkCommandFinishedListener
{
    /**
     * @param  Core<CommandState>  $vigil
     */
    public function __construct(
        private Core $vigil,
    ) {
        //
    }

    public function __invoke(CommandFinished $event): void
    {
        $this->vigil->finishExecution();
    }
}
