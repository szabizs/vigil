<?php

namespace Materom\Vigil\Factories;

use DateTimeZone;
use Materom\Vigil\Core;
use Materom\Vigil\Hooks\LogHandler;
use Materom\Vigil\Hooks\LogRecordProcessor;
use Materom\Vigil\State\CommandState;
use Materom\Vigil\State\RequestState;
use Monolog\Logger as Monolog;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\LoggerInterface;

/**
 * @internal
 */
final class Logger
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
     * @param  array<string, mixed>&array{level: \Psr\Log\LogLevel::*}  $config
     */
    public function __invoke(array $config): LoggerInterface
    {
        return new Monolog(
            name: 'vigil',
            handlers: [
                new LogHandler(
                    vigil: $this->vigil,
                    level: Monolog::toMonologLevel($config['level']),
                    // There is some unexpected behaviour in the framework when
                    // using a log stack that causes monolog processors to leak
                    // and apply their side-effects to other log handlers in
                    // the stack. Instead of passing processors to the monolog
                    // instance, as you would usually expect, we pass them to
                    // our handler to apply manually. This allows us to keep
                    // the side-effects of the processors isolated to
                    // Vigil's handler when used in a stack of handlers.
                    processors: [
                        new LogRecordProcessor($this->vigil, 'Y-m-d H:i:s.uP'),
                        new PsrLogMessageProcessor('Y-m-d H:i:s.uP'),
                    ],
                ),
            ],
            timezone: new DateTimeZone('UTC'),
        );
    }
}
