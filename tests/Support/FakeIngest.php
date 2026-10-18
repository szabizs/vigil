<?php

namespace Materom\Vigil\Tests\Support;

use Materom\Vigil\Contracts\Ingest;
use Materom\Vigil\Payload;
use RuntimeException;

/**
 * Captures records instead of transmitting them, mirroring the buffer semantics of the real Ingest:
 * write() buffers, writeNow() transmits immediately, digest() transmits the buffer, flush() drops it.
 */
final class FakeIngest implements Ingest
{
    /**
     * @var list<array<mixed>>
     */
    public array $buffer = [];

    /**
     * @var list<array<mixed>>
     */
    public array $transmitted = [];

    public int $pings = 0;

    public bool $shouldDigestWhenBufferIsFull = true;

    public function write(array $record): void
    {
        $this->buffer[] = $record;
    }

    public function writeNow(array $record): void
    {
        $this->transmitted[] = $record;
    }

    public function ping(): void
    {
        $this->pings++;
    }

    public function shouldDigest(bool $bool = true): void
    {
        $this->shouldDigestWhenBufferIsFull($bool);
    }

    public function shouldDigestWhenBufferIsFull(bool $bool = true): void
    {
        $this->shouldDigestWhenBufferIsFull = $bool;
    }

    public function digest(): void
    {
        $this->transmitted = [...$this->transmitted, ...$this->buffer];

        $this->buffer = [];
    }

    public function flush(): void
    {
        $this->buffer = [];
    }

    /**
     * Transmitted records, JSON round-tripped exactly as they would travel on the wire.
     *
     * @return list<array<string, mixed>>
     */
    public function records(?string $type = null): array
    {
        if ($this->transmitted === []) {
            return [];
        }

        /** @var list<array<string, mixed>> $records */
        $records = json_decode(Payload::json($this->transmitted, 'abcdefg')->rawPayload(), true, flags: JSON_THROW_ON_ERROR);

        if ($type === null) {
            return $records;
        }

        return array_values(array_filter($records, static fn (array $record): bool => $record['t'] === $type));
    }

    /**
     * @return array<string, mixed>
     */
    public function first(string $type): array
    {
        $records = $this->records($type);

        if ($records === []) {
            throw new RuntimeException("No [{$type}] record was transmitted. Transmitted types: [".implode(', ', array_column($this->records(), 't')).']');
        }

        return $records[0];
    }
}
