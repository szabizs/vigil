<?php

namespace Materom\VigilAgent;

use RuntimeException;

use function basename;
use function clearstatcache;
use function ctype_digit;
use function date;
use function fclose;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function filesize;
use function flock;
use function floor;
use function fopen;
use function fread;
use function fseek;
use function fwrite;
use function glob;
use function is_dir;
use function is_file;
use function json_encode;
use function microtime;
use function mkdir;
use function rename;
use function rtrim;
use function sort;
use function sprintf;
use function strlen;
use function substr;
use function trim;
use function unlink;

/**
 * The relay's on-disk spool.
 *
 * Layout:
 * - `<unix-ms>-<seq>.open`: the segment the relay is appending to (at most one).
 * - `<unix-ms>-<seq>.ndjson`: a closed segment, one frame body (a JSON array of records) per line, never written
 *   again. Names sort chronologically, so the uploader ships the oldest first.
 * - `<segment>.ndjson.offset`: the uploader's checkpoint inside a segment that is shipped in several uploads.
 * - `dead/`: batches the product rejected as invalid (400/413), with the error next to them.
 * - `agent.lock`: held by the running relay so two relays never share a spool.
 */
final class SpoolDirectory
{
    public const OPEN = '.open';

    public const CLOSED = '.ndjson';

    public const CHECKPOINT = '.offset';

    public const DEAD = 'dead';

    public readonly string $path;

    public function __construct(string $path)
    {
        $this->path = rtrim($path, '/');
    }

    public function ensureExists(): void
    {
        foreach ([$this->path, $this->deadPath()] as $directory) {
            if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
                throw new RuntimeException("Unable to create the spool directory [{$directory}].");
            }
        }
    }

    public function deadPath(): string
    {
        return $this->path.'/'.self::DEAD;
    }

    /**
     * Closed segments, oldest first.
     *
     * @return list<string>
     */
    public function segments(): array
    {
        return $this->glob('*'.self::CLOSED);
    }

    /**
     * @return list<string>
     */
    public function openSegments(): array
    {
        return $this->glob('*'.self::OPEN);
    }

    /**
     * @return list<string>
     */
    public function deadLetters(): array
    {
        return $this->glob(self::DEAD.'/*'.self::CLOSED);
    }

    public function newOpenSegment(float $now, int &$sequence): string
    {
        do {
            $sequence++;
            $path = sprintf('%s/%013d-%06d%s', $this->path, (int) floor($now * 1000), $sequence, self::OPEN);
        } while (file_exists($path) || file_exists(substr($path, 0, -strlen(self::OPEN)).self::CLOSED));

        return $path;
    }

    /**
     * Rename an open segment to its closed name; an empty one is deleted instead.
     */
    public function close(string $openPath): void
    {
        clearstatcache(true, $openPath);

        if (@filesize($openPath) === 0) {
            @unlink($openPath);

            return;
        }

        @rename($openPath, substr($openPath, 0, -strlen(self::OPEN)).self::CLOSED);
    }

    /**
     * Close open segments left behind by a relay that is gone (crashed or killed). Returns how many.
     */
    public function recoverOpenSegments(float $olderThanSeconds = 0.0, ?float $now = null): int
    {
        $now ??= microtime(true);
        $recovered = 0;

        foreach ($this->openSegments() as $path) {
            clearstatcache(true, $path);
            $modified = @filemtime($path);

            if ($modified === false || $now - $modified < $olderThanSeconds) {
                continue;
            }

            $this->close($path);
            $recovered++;
        }

        return $recovered;
    }

    /**
     * Bytes of every segment, open or closed, minus what has already been shipped from partially uploaded ones.
     */
    public function totalBytes(): int
    {
        $bytes = 0;

        foreach ($this->openSegments() as $path) {
            $bytes += (int) @filesize($path);
        }

        foreach ($this->segments() as $path) {
            $bytes += (int) @filesize($path) - $this->checkpoint($path);
        }

        return $bytes;
    }

    public function size(string $segment): int
    {
        clearstatcache(true, $segment);

        return (int) @filesize($segment);
    }

    public function checkpoint(string $segment): int
    {
        $value = is_file($segment.self::CHECKPOINT) ? @file_get_contents($segment.self::CHECKPOINT) : false;

        return $value === false || ! ctype_digit(trim($value)) ? 0 : (int) trim($value);
    }

    /**
     * Atomically record that every byte before `$offset` has been shipped (or dead-lettered).
     */
    public function saveCheckpoint(string $segment, int $offset): void
    {
        $temporary = $segment.self::CHECKPOINT.'.tmp';

        if (@file_put_contents($temporary, (string) $offset) === false || ! @rename($temporary, $segment.self::CHECKPOINT)) {
            throw new RuntimeException("Unable to write the checkpoint of [{$segment}].");
        }
    }

    /**
     * Delete a fully shipped segment, then its checkpoint.
     */
    public function delete(string $segment): void
    {
        foreach ([$segment, $segment.self::CHECKPOINT] as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }

    public function removeOrphanCheckpoints(): void
    {
        foreach ($this->glob('*'.self::CLOSED.self::CHECKPOINT) as $checkpoint) {
            if (! file_exists(substr($checkpoint, 0, -strlen(self::CHECKPOINT)))) {
                @unlink($checkpoint);
            }
        }
    }

    /**
     * Copy the byte range [$start, $end) of a segment to `dead/` together with the reason it was rejected.
     * Returns the dead-letter path.
     */
    public function deadLetter(string $segment, int $start, int $end, int $status, string $reason, ?float $now = null): string
    {
        $this->ensureExists();

        $name = substr(basename($segment), 0, -strlen(self::CLOSED)).'-'.$start;
        $target = $this->deadPath().'/'.$name.self::CLOSED;

        $source = @fopen($segment, 'rb');
        $destination = @fopen($target, 'wb');

        if ($source === false || $destination === false) {
            throw new RuntimeException("Unable to dead-letter [{$segment}] to [{$target}].");
        }

        try {
            fseek($source, $start);
            $remaining = $end - $start;

            while ($remaining > 0) {
                $chunk = fread($source, $remaining > 1048576 ? 1048576 : $remaining);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                fwrite($destination, $chunk);
                $remaining -= strlen($chunk);
            }
        } finally {
            fclose($source);
            fclose($destination);
        }

        @file_put_contents($this->deadPath().'/'.$name.'.error.json', json_encode([
            'segment' => basename($segment),
            'offset' => $start,
            'bytes' => $end - $start,
            'status' => $status,
            'error' => $reason,
            'rejected_at' => date('c', (int) ($now ?? microtime(true))),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return $target;
    }

    /**
     * Take an exclusive, non-blocking lock held for the life of the returned handle; null when another relay
     * holds it.
     *
     * @return resource|null
     */
    public function lock(string $name = 'agent')
    {
        $this->ensureExists();
        $handle = @fopen("{$this->path}/{$name}.lock", 'c');

        if ($handle === false) {
            throw new RuntimeException("Unable to open the lock file in [{$this->path}].");
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /**
     * @return list<string>
     */
    private function glob(string $pattern): array
    {
        $paths = glob($this->path.'/'.$pattern) ?: [];
        sort($paths, SORT_STRING);

        return $paths;
    }
}
