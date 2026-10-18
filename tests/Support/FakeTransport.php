<?php

namespace Materom\Vigil\Tests\Support;

use Closure;
use Materom\VigilAgent\Transport;

/**
 * A Transport that records every upload and answers from a queue of canned responses.
 */
final class FakeTransport implements Transport
{
    /**
     * @var list<array{records: list<mixed>, raw: string}>
     */
    public array $posts = [];

    /**
     * @var list<array{0: int, 1: string}|string|null>
     */
    private array $answers = [];

    /**
     * @var list<array{0: Closure(int, string): void, 1: Closure(string): void}>
     */
    public array $pending = [];

    /**
     * Queue answers: [status, body] responds, a string fails with that network error, null leaves the upload
     * pending until resolve() is called.
     *
     * @param  array{0: int, 1: string}|string|null  ...$answers
     */
    public function answer(array|string|null ...$answers): self
    {
        foreach ($answers as $answer) {
            $this->answers[] = $answer;
        }

        return $this;
    }

    public function post(string $gzipBody, Closure $onResponse, Closure $onError): void
    {
        $raw = (string) gzdecode($gzipBody);
        $this->posts[] = ['records' => json_decode($raw, true, flags: JSON_THROW_ON_ERROR)['records'], 'raw' => $raw];

        $answer = $this->answers === [] ? [202, '{"accepted":0}'] : array_shift($this->answers);

        if ($answer === null) {
            $this->pending[] = [$onResponse, $onError];

            return;
        }

        if (is_string($answer)) {
            $onError($answer);

            return;
        }

        $onResponse($answer[0], $answer[1]);
    }

    public function resolve(int $status = 202, string $body = '{"accepted":0}'): void
    {
        [$onResponse] = array_shift($this->pending);

        $onResponse($status, $body);
    }
}
