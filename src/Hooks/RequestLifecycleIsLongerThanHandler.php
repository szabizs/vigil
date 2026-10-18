<?php

namespace Materom\Vigil\Hooks;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Materom\Vigil\Core;
use Materom\Vigil\ExecutionStage;
use Materom\Vigil\State\RequestState;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * @internal
 */
final class RequestLifecycleIsLongerThanHandler
{
    /**
     * @param  Core<RequestState>  $vigil
     */
    public function __construct(
        private Core $vigil,
    ) {
        //
    }

    public function __invoke(Carbon $startedAt, Request $request, Response $response): void
    {
        try {
            $this->vigil->stage(ExecutionStage::End);
        } catch (Throwable $e) {
            $this->vigil->report($e, handled: true);
        }

        try {
            $this->vigil->captureUser();
        } catch (Throwable $e) {
            $this->vigil->report($e, handled: true);
        }

        try {
            $this->vigil->request($request, $response);
        } catch (Throwable $e) {
            $this->vigil->report($e, handled: true);
        }

        $this->vigil->finishExecution();
    }
}
