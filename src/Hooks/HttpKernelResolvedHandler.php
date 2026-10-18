<?php

namespace Materom\Vigil\Hooks;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Foundation\Events\Terminating;
use Illuminate\Foundation\Http\Kernel;
use Materom\Vigil\Core;
use Materom\Vigil\Facades\Vigil;
use Materom\Vigil\Http\Middleware\Sample;
use Materom\Vigil\State\RequestState;
use Throwable;

/**
 * @internal
 */
final class HttpKernelResolvedHandler
{
    /**
     * @param  Core<RequestState>  $vigil
     */
    public function __construct(
        private Core $vigil,
    ) {
        //
    }

    public function __invoke(KernelContract $kernel, Application $app): void
    {
        if (! $kernel instanceof Kernel) {
            return;
        }

        try {
            /**
             * @see \Materom\Vigil\ExecutionStage::End
             * @see \Materom\Vigil\Records\Request
             * @see Core::finishExecution()
             */
            $kernel->whenRequestLifecycleIsLongerThan(-1, new RequestLifecycleIsLongerThanHandler($this->vigil));
        } catch (Throwable $e) {
            Vigil::unrecoverableExceptionOccurred($e);
        }

        try {
            /**
             * @see \Materom\Vigil\ExecutionStage::Terminating
             */
            $kernel->prependMiddleware(GlobalMiddleware::class);
        } catch (Throwable $e) {
            $this->vigil->report($e, handled: true);
        }

        try {
            $kernel->prependToMiddlewarePriority(Sample::class);
        } catch (Throwable $e) {
            $this->vigil->report($e, handled: true);
        }
    }
}
