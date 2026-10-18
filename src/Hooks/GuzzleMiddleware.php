<?php

namespace Materom\Vigil\Hooks;

use GuzzleHttp\Promise\PromiseInterface;
use Materom\Vigil\Core;
use Materom\Vigil\State\CommandState;
use Materom\Vigil\State\RequestState;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * @internal
 */
final class GuzzleMiddleware
{
    /**
     * @param  Core<RequestState|CommandState>  $vigil
     */
    public function __construct(
        private Core $vigil,
    ) {
        //
    }

    /**
     * TODO record the failed responses as well.
     */
    public function __invoke(callable $handler): callable
    {
        if ($this->vigil->config['filtering']['ignore_outgoing_requests'] || $this->vigil->paused()) {
            return $handler;
        }

        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            try {
                $startMicrotime = $this->vigil->clock->microtime();
            } catch (Throwable $e) {
                $this->vigil->report($e, handled: true);

                return $handler($request, $options);
            }

            return $handler($request, $options)->then(function (ResponseInterface $response) use ($request, $startMicrotime): ResponseInterface {
                try {
                    $endMicrotime = $this->vigil->clock->microtime();

                    $this->vigil->outgoingRequest(
                        $startMicrotime, $endMicrotime,
                        $request, $response,
                    );
                } catch (Throwable $e) {
                    $this->vigil->report($e, handled: true);
                }

                return $response;
            });
        };
    }
}
