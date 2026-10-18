<?php

namespace Materom\Vigil\Hooks;

use Materom\Vigil\Compatibility;
use Materom\Vigil\Core;
use Materom\Vigil\State\CommandState;
use Materom\Vigil\State\RequestState;
use Throwable;

/**
 * @internal
 */
final class PolyfillContextDehydration
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
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function __invoke(mixed $connection, mixed $queue, array $payload): array
    {
        $context = Compatibility::$context;

        try {
            if (($context['nightwatch_user_id'] ?? '') === '') {
                $context['nightwatch_user_id'] = $this->vigil->executionState->user->resolvedUserId();
            }

            return [
                ...$payload,
                'nightwatch' => [
                    ...($payload['nightwatch'] ?? []), // @phpstan-ignore arrayUnpacking.nonIterable
                    'nightwatch_trace_id' => $context['nightwatch_trace_id'] ?? null,
                    'nightwatch_should_sample' => $context['nightwatch_should_sample'] ?? null,
                    'nightwatch_user_id' => $context['nightwatch_user_id'],
                ],
            ];
        } catch (Throwable $e) {
            $this->vigil->report($e, handled: true);

            return $payload;
        }
    }
}
