<?php

namespace Materom\VigilAgent;

use Closure;
use Throwable;

use function basename;
use function count;
use function microtime;
use function round;

/**
 * Ships closed spool segments to the product, oldest first, at most `$concurrency` uploads at a time.
 *
 * Driven by `tick()` (a periodic loop timer). A segment is uploaded in batches of at most the reader's byte cap;
 * the batches of one segment go one after the other and a checkpoint records how far the segment has been
 * shipped, so a restart never re-sends an accepted batch. See Outcome for how each answer is handled.
 */
final class Uploader
{
    public const UNAUTHORIZED_RETRY_SECONDS = 300.0;

    public const ENVIRONMENT_RETRY_SECONDS = 60.0;

    /**
     * @var array<string, true>
     */
    private array $inFlight = [];

    private float $pausedUntil = 0.0;

    private int $failures = 0;

    private bool $draining = false;

    private bool $drainFailed = false;

    /**
     * @var (Closure(): void)|null
     */
    private ?Closure $onDrained = null;

    /**
     * @var Closure(): float
     */
    private Closure $clock;

    /**
     * @var Closure(Closure(): void): void
     */
    private Closure $defer;

    public int $delivered = 0;

    /**
     * @param  list<float>  $backoff
     * @param  (Closure(): float)|null  $clock
     * @param  (Closure(Closure(): void): void)|null  $defer  runs the callback on a later loop tick
     */
    public function __construct(
        private SpoolDirectory $directory,
        private BatchReader $reader,
        private Transport $transport,
        private Logger $logger,
        private int $concurrency = 2,
        private array $backoff = [2.5, 5, 10, 20, 40, 80, 160, 300],
        ?Closure $clock = null,
        ?Closure $defer = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->defer = $defer ?? static function (Closure $callback): void {
            $callback();
        };
    }

    public function tick(): void
    {
        foreach ($this->directory->segments() as $segment) {
            if ($this->halted() || count($this->inFlight) >= $this->concurrency) {
                return;
            }

            if (isset($this->inFlight[$segment])) {
                continue;
            }

            $this->upload($segment);
        }
    }

    /**
     * One last upload round: ignore any pause, keep going while uploads succeed, and call `$onDrained` once
     * nothing is in flight and the spool is empty or an upload failed.
     *
     * @param  Closure(): void  $onDrained
     */
    public function drain(Closure $onDrained): void
    {
        $this->draining = true;
        $this->drainFailed = false;
        $this->onDrained = $onDrained;

        $this->tick();
        $this->checkDrained();
    }

    private function halted(): bool
    {
        return $this->draining ? $this->drainFailed : ($this->clock)() < $this->pausedUntil;
    }

    public function inFlight(): int
    {
        return count($this->inFlight);
    }

    public function pausedFor(): float
    {
        return max(0.0, $this->pausedUntil - ($this->clock)());
    }

    public function failures(): int
    {
        return $this->failures;
    }

    private function upload(string $segment): void
    {
        try {
            $batch = $this->reader->read($segment, $this->directory->checkpoint($segment));
        } catch (Throwable $e) {
            $this->logger->error('Unable to read ['.basename($segment).']: '.$e->getMessage());

            return;
        }

        if ($batch === null) {
            $this->directory->delete($segment);

            return;
        }

        if ($batch->skippedLines > 0) {
            $this->logger->error('Skipped ['.$batch->skippedLines.'] unreadable line(s) in ['.basename($segment).'].');
        }

        if ($batch->isEmpty()) {
            $this->advance($batch);

            return;
        }

        $this->inFlight[$segment] = true;
        $start = ($this->clock)();

        $this->logger->debug('Uploading ['.basename($segment).'@'.$batch->start.']: '.$batch->frames.' frame(s), '.Logger::bytes($batch->rawBytes).' → '.Logger::bytes(strlen($batch->body)).' gzip.');

        try {
            $this->transport->post(
                $batch->body,
                function (int $status, string $body) use ($batch, $start): void {
                    $this->settle($batch, Outcome::fromResponse($status, $body), $start);
                },
                function (string $error) use ($batch, $start): void {
                    $this->settle($batch, Outcome::fromError($error), $start);
                },
            );
        } catch (Throwable $e) {
            $this->settle($batch, Outcome::fromError($e->getMessage()), $start);
        }
    }

    private function settle(Batch $batch, Outcome $outcome, float $start): void
    {
        unset($this->inFlight[$batch->segment]);

        $duration = round(($this->clock)() - $start, 3);
        $name = basename($batch->segment);
        $progress = true;

        switch ($outcome->kind) {
            case Outcome::DELIVERED:
                $this->advance($batch);
                $this->failures = 0;
                $this->pausedUntil = 0.0;
                $this->delivered += $batch->frames;
                $this->logger->info("Ingest successful [{$duration}s]: {$outcome->status} [{$name}@{$batch->start}; {$batch->frames} frame(s); ".Logger::bytes($batch->rawBytes).' → '.Logger::bytes(strlen($batch->body)).' gzip'.($outcome->accepted !== null ? "; {$outcome->accepted} record(s) accepted" : '').']');

                break;

            case Outcome::DEAD_LETTER:
                try {
                    $dead = $this->directory->deadLetter($batch->segment, $batch->start, $batch->end, $outcome->status, $outcome->message);
                    $this->advance($batch);
                    $this->logger->error("Ingest rejected [{$duration}s]: {$outcome->describe()}; batch moved to [dead/".basename($dead).'].');
                } catch (Throwable $e) {
                    $progress = false;
                    $this->pause($this->nextBackoff());
                    $this->logger->error("Ingest rejected [{$duration}s]: {$outcome->describe()}; unable to dead-letter the batch, keeping it: {$e->getMessage()}");
                }

                break;

            case Outcome::UNAUTHORIZED:
                $progress = false;
                $this->pause(self::UNAUTHORIZED_RETRY_SECONDS);
                $this->logger->critical("Ingest refused [{$duration}s]: {$outcome->describe()}. The product rejected VIGIL_TOKEN. Spooled data is kept; retrying every ".((int) self::UNAUTHORIZED_RETRY_SECONDS).'s until the token is fixed.');

                break;

            case Outcome::ENVIRONMENT:
                $progress = false;
                $this->pause(self::ENVIRONMENT_RETRY_SECONDS);
                $this->logger->critical("Ingest refused [{$duration}s]: {$outcome->describe()}. The product does not accept VIGIL_ENVIRONMENT. Spooled data is kept; retrying every ".((int) self::ENVIRONMENT_RETRY_SECONDS).'s until the environment is created or the configuration is fixed.');

                break;

            case Outcome::RETRY_AFTER:
                $progress = false;
                $wait = $outcome->retryIn ?? $this->nextBackoff();
                $this->pause($wait);
                $this->logger->error("Ingest deferred [{$duration}s]: {$outcome->describe()}; retrying in {$wait}s.");

                break;

            default:
                $progress = false;
                $wait = $this->nextBackoff();
                $this->pause($wait);
                $this->logger->error("Ingest failed [{$duration}s]: {$outcome->describe()}; batch kept, retrying in {$wait}s.");
        }

        if ($this->draining) {
            if (! $progress) {
                $this->drainFailed = true;
            }

            ($this->defer)(function (): void {
                $this->tick();
                $this->checkDrained();
            });

            return;
        }

        if ($progress) {
            ($this->defer)($this->tick(...));
        }
    }

    private function advance(Batch $batch): void
    {
        if ($batch->completesSegment()) {
            $this->directory->delete($batch->segment);

            return;
        }

        $this->directory->saveCheckpoint($batch->segment, $batch->end);
    }

    private function nextBackoff(): float
    {
        $this->failures++;

        return (float) ($this->backoff[$this->failures - 1] ?? $this->backoff[count($this->backoff) - 1]);
    }

    private function pause(float $seconds): void
    {
        $this->pausedUntil = max($this->pausedUntil, ($this->clock)() + $seconds);
    }

    private function checkDrained(): void
    {
        if ($this->onDrained === null || $this->inFlight !== []) {
            return;
        }

        if (! $this->drainFailed && $this->directory->segments() !== []) {
            return;
        }

        $onDrained = $this->onDrained;
        $this->onDrained = null;
        $onDrained();
    }
}
