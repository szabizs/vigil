<?php

namespace Materom\VigilAgent;

use Throwable;

use function in_array;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function json_decode;
use function max;
use function min;

/**
 * What the uploader does with the product's answer to one upload.
 *
 * - 2xx: delivered, the batch is removed from the spool.
 * - 401: the token is invalid. Not retryable as such, but the data is kept and retried slowly in case the token
 *   gets fixed.
 * - 422 "Unknown environment" / "Environment required": the declared environment is not accepted (yet). Data is kept
 *   and retried every minute until an admin creates the environment or the configuration is fixed.
 * - 400 / 413: the batch itself is unacceptable; it is moved to `dead/` with the error.
 * - 429 / 503: the product is busy; the batch is kept and retried after `retry_in` seconds.
 * - Anything else (other 4xx/5xx, network errors, timeouts): the batch is kept and retried with exponential backoff.
 */
final class Outcome
{
    public const DELIVERED = 'delivered';

    public const DEAD_LETTER = 'dead_letter';

    public const UNAUTHORIZED = 'unauthorized';

    public const ENVIRONMENT = 'environment';

    public const RETRY_AFTER = 'retry_after';

    public const BACKOFF = 'backoff';

    public function __construct(
        public readonly string $kind,
        public readonly int $status,
        public readonly string $message,
        public readonly ?float $retryIn = null,
        public readonly ?int $accepted = null,
    ) {
        //
    }

    public static function fromResponse(int $status, string $body): self
    {
        $json = self::decode($body);
        $message = Logger::limit(isset($json['message']) && is_string($json['message']) ? $json['message'] : $body);

        if ($status >= 200 && $status < 300) {
            return new self(self::DELIVERED, $status, $message, accepted: isset($json['accepted']) && is_int($json['accepted']) ? $json['accepted'] : null);
        }

        return match (true) {
            $status === 401 => new self(self::UNAUTHORIZED, $status, $message),
            $status === 422 && in_array($message, ['Unknown environment', 'Environment required'], true) => new self(self::ENVIRONMENT, $status, $message),
            $status === 400, $status === 413 => new self(self::DEAD_LETTER, $status, $message),
            $status === 429, $status === 503 => new self(
                self::RETRY_AFTER,
                $status,
                $message,
                isset($json['retry_in']) && is_numeric($json['retry_in']) ? min(3600.0, max(0.5, (float) $json['retry_in'])) : null,
            ),
            default => new self(self::BACKOFF, $status, $message),
        };
    }

    public static function fromError(string $message): self
    {
        return new self(self::BACKOFF, 0, Logger::limit($message));
    }

    public function describe(): string
    {
        return $this->status === 0 ? $this->message : "{$this->status} [{$this->message}]";
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $body): array
    {
        try {
            $json = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($json) ? $json : [];
    }
}
