<?php

namespace Materom\Vigil\Hooks;

use Closure;
use Illuminate\Http\Request;
use Materom\Vigil\Compatibility;
use Materom\Vigil\Core;
use Materom\Vigil\ExecutionStage;
use Materom\Vigil\Facades\Vigil;
use Materom\Vigil\State\RequestState;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * @internal
 */
final class GlobalMiddleware
{
    private bool $hasHandledRequest = false;

    private bool $hasTerminated = false;

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
        if ($this->hasHandledRequest) {
            return $next($request);
        }

        $this->hasHandledRequest = true;

        try {
            $this->vigil->configureRequestSampling();
        } catch (Throwable $e) {
            Vigil::unrecoverableExceptionOccurred($e);
        }

        try {
            $this->vigil->captureRequestPreview($request);
        } catch (Throwable $e) {
            $this->vigil->report($e, handled: true);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($this->hasTerminated || Compatibility::$terminatingEventExists) {
            return;
        }

        $this->hasTerminated = true;

        try {
            $this->vigil->stage(ExecutionStage::Terminating);
        } catch (Throwable $e) {
            $this->vigil->report($e, handled: true);
        }
    }
}
