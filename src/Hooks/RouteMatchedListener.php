<?php

namespace Materom\Vigil\Hooks;

use Illuminate\Routing\Events\RouteMatched;
use Materom\Vigil\Core;
use Materom\Vigil\State\RequestState;
use Throwable;

/**
 * @internal
 */
final class RouteMatchedListener
{
    /**
     * @param  Core<RequestState>  $vigil
     */
    public function __construct(
        private Core $vigil,
    ) {
        //
    }

    public function __invoke(RouteMatched $event): void
    {
        try {
            $this->vigil->attachMiddlewareToRoute($event->route);
        } catch (Throwable $e) {
            $this->vigil->report($e, handled: true);
        }
    }
}
