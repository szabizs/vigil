<?php

namespace Materom\VigilAgent;

/**
 * One upload: the gzip body built from the lines [$start, $end) of a spool segment.
 */
final class Batch
{
    public function __construct(
        public readonly string $segment,
        public readonly int $start,
        public readonly int $end,
        public readonly int $segmentSize,
        public readonly string $body,
        public readonly int $rawBytes,
        public readonly int $frames,
        public readonly int $skippedLines = 0,
    ) {
        //
    }

    public function completesSegment(): bool
    {
        return $this->end >= $this->segmentSize;
    }

    public function isEmpty(): bool
    {
        return $this->frames === 0;
    }
}
