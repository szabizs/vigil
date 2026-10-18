<?php

namespace Materom\VigilAgent;

use RuntimeException;

use function clearstatcache;
use function deflate_add;
use function deflate_init;
use function fclose;
use function fgets;
use function filesize;
use function fopen;
use function fseek;
use function ftell;
use function rtrim;
use function str_ends_with;
use function strlen;
use function substr;
use function trim;

/**
 * Builds the body of one upload from a spool segment.
 *
 * Every line of a segment is one frame body, i.e. a JSON array of records. Exactly like the original agent's
 * StreamBuffer, the surrounding `[` and `]` are stripped and the record lists are joined with commas into
 * `{"records":[...]}`, which is gzip-compressed incrementally while the lines are read. One upload holds at most
 * `$maxBytes` of raw record bytes, so a large segment is shipped in several uploads (a single line larger than
 * that is shipped alone).
 */
final class BatchReader
{
    public const PREFIX = '{"records":[';

    public const SUFFIX = ']}';

    public function __construct(private int $maxBytes = 8388608, private int $level = 6)
    {
        //
    }

    public function read(string $segment, int $offset = 0): ?Batch
    {
        clearstatcache(true, $segment);
        $size = @filesize($segment);

        if ($size === false) {
            throw new RuntimeException("Unable to read the spool segment [{$segment}].");
        }

        if ($offset >= $size) {
            return null;
        }

        $handle = @fopen($segment, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open the spool segment [{$segment}].");
        }

        $context = deflate_init(ZLIB_ENCODING_GZIP, ['level' => $this->level]);

        if ($context === false) {
            throw new RuntimeException('Unable to initialise gzip compression.');
        }

        $body = deflate_add($context, self::PREFIX, ZLIB_NO_FLUSH);
        $rawBytes = 0;
        $frames = 0;
        $skipped = 0;
        $end = $offset;

        try {
            fseek($handle, $offset);

            while (true) {
                $position = ftell($handle);
                $line = fgets($handle);

                if ($line === false) {
                    break;
                }

                if (! str_ends_with($line, "\n")) {
                    // A torn last line: the write that produced it never completed, so it was never acknowledged.
                    $end = $position + strlen($line);
                    $skipped++;

                    break;
                }

                $records = $this->records(rtrim($line, "\r\n"));

                if ($records === null) {
                    $end = $position + strlen($line);
                    $skipped++;

                    continue;
                }

                if ($records === '') {
                    $end = $position + strlen($line);

                    continue;
                }

                $addition = strlen($records) + ($frames > 0 ? 1 : 0);

                if ($frames > 0 && $rawBytes + $addition > $this->maxBytes) {
                    break;
                }

                $body .= deflate_add($context, ($frames > 0 ? ',' : '').$records, ZLIB_NO_FLUSH);
                $rawBytes += $addition;
                $frames++;
                $end = $position + strlen($line);
            }
        } finally {
            fclose($handle);
        }

        $body .= deflate_add($context, self::SUFFIX, ZLIB_FINISH);

        return new Batch(
            segment: $segment,
            start: $offset,
            end: $end,
            segmentSize: $size,
            body: $body,
            rawBytes: $rawBytes,
            frames: $frames,
            skippedLines: $skipped,
        );
    }

    /**
     * The comma separated records of one line: the inside of a JSON array, a lone JSON object, '' for an empty
     * array, or null for a line that is neither.
     */
    public function records(string $line): ?string
    {
        if ($line === '') {
            return '';
        }

        $first = $line[0];
        $last = substr($line, -1);

        if ($first === '[' && $last === ']') {
            $records = substr($line, 1, -1);

            return trim($records) === '' ? '' : $records;
        }

        if ($first === '{' && $last === '}') {
            return $line;
        }

        return null;
    }
}
