<?php

namespace Materom\Vigil\Hooks;

use Closure;
use Illuminate\Http\Request;
use Materom\Vigil\Core;
use Materom\Vigil\ExecutionStage;
use Materom\Vigil\State\RequestState;
use Throwable;

/**
 * @internal
 */
final class RouteMiddleware
{
    /**
     * @param  Core<RequestState>  $vigil
     */
    public function __construct(
        private Core $vigil,
    ) {
        //
    }

    public function handle(Request $request, Closure $next): mixed
    {
        try {
            $this->vigil->stage(ExecutionStage::Action);
        } catch (Throwable $e) {
            $this->vigil->report($e, handled: true);
        }

        $response = $next($request);

        // If an exception occurs in the action phase, the usual
        // ResponsePrepared event is not fired. This fallback
        // ensures that we go to the AfterMiddleware stage.
        try {
            $this->vigil->stage(ExecutionStage::AfterMiddleware);
        } catch (Throwable $e) {
            $this->vigil->report($e, handled: true);
        }

        return $response;
    }
}
