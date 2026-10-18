<?php

namespace Materom\Vigil\Hooks;

use Illuminate\Http\Client\Factory;
use Materom\Vigil\Core;
use Materom\Vigil\State\CommandState;
use Materom\Vigil\State\RequestState;
use Throwable;

/**
 * @internal
 */
final class HttpClientFactoryResolvedHandler
{
    /**
     * @param  Core<RequestState|CommandState>  $vigil
     */
    public function __construct(
        private Core $vigil,
    ) {
        //
    }

    public function __invoke(Factory $factory): void
    {
        try {
            /**
             * @see \Materom\Vigil\Records\OutgoingRequest
             */
            $factory->globalMiddleware($this->vigil->guzzleMiddleware());
        } catch (Throwable $e) {
            $this->vigil->report($e, handled: true);
        }
    }
}
