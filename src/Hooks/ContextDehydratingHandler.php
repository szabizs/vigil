<?php

namespace Materom\Vigil\Hooks;

use Illuminate\Log\Context\Repository;
use Materom\Vigil\Core;
use Materom\Vigil\State\CommandState;
use Materom\Vigil\State\RequestState;
use Throwable;

/**
 * @internal
 */
final class ContextDehydratingHandler
{
    /**
     * @param  Core<RequestState|CommandState>  $vigil
     */
    public function __construct(
        private Core $vigil,
    ) {
        //
    }

    public function __invoke(Repository $context): void
    {
        try {
            if (($context->getHidden('nightwatch_user_id') ?? '') === '') {
                $context->addHidden('nightwatch_user_id', $this->vigil->executionState->user->resolvedUserId());
            }
        } catch (Throwable $e) {
            $this->vigil->report($e, handled: true);
        }
    }
}
