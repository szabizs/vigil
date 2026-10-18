<?php

namespace Materom\VigilAgent;

use function count;
use function ctype_digit;
use function explode;
use function hash;
use function hash_equals;
use function strlen;
use function substr;

/**
 * Incremental parser for the frame `<len>:v1:<tokenHash>:<body>` the Vigil client writes to the relay.
 *
 * Valid frames parse exactly like the original Nightwatch agent's Payload: `<len>` counts the bytes after the
 * first colon and the frame is complete once that many bytes arrived. On top of that, a header that does not
 * parse within MAX_HEADER_BYTES, or a body longer than declared, marks the frame malformed so a stray client
 * cannot make the relay buffer an unbounded colon-free stream.
 */
final class Frame
{
    public const EXPECTED_VERSION = 'v1';

    public const PING = 'PING';

    public const MAX_HEADER_BYTES = 64;

    public string $value = '';

    public string $version = '';

    public string $tokenHash = '';

    public ?int $length = null;

    public bool $complete = false;

    public bool $malformed = false;

    public int $bytesReceived = 0;

    public static function tokenHash(string $token): string
    {
        return substr(hash('xxh128', $token), 0, 7);
    }

    public static function encode(string $body, string $tokenHash, string $version = self::EXPECTED_VERSION): string
    {
        $length = strlen($version) + 1 + strlen($tokenHash) + 1 + strlen($body);

        return $length.':'.$version.':'.$tokenHash.':'.$body;
    }

    public function append(string $chunk): void
    {
        $this->bytesReceived += strlen($chunk);

        if ($this->complete || $this->malformed) {
            return;
        }

        $this->value .= $chunk;
        $this->parse();

        if ($this->length === null) {
            return;
        }

        $received = strlen($this->version) + 1 + strlen($this->tokenHash) + 1 + strlen($this->value);
        $this->complete = $this->length === $received;
        $this->malformed = $received > $this->length;
    }

    public function isEmpty(): bool
    {
        return $this->bytesReceived === 0;
    }

    public function versionIsValid(): bool
    {
        return $this->version === self::EXPECTED_VERSION;
    }

    public function tokenHashMatches(string $expected): bool
    {
        return hash_equals($expected, $this->tokenHash);
    }

    public function isPing(): bool
    {
        return $this->value === self::PING;
    }

    private function parse(): void
    {
        if ($this->length !== null) {
            return;
        }

        $bits = explode(':', substr($this->value, 0, self::MAX_HEADER_BYTES), 4);

        if (count($bits) !== 4) {
            $this->malformed = strlen($this->value) >= self::MAX_HEADER_BYTES;

            return;
        }

        if (! ctype_digit($bits[0])) {
            $this->malformed = true;

            return;
        }

        $headerLength = strlen($bits[0]) + strlen($bits[1]) + strlen($bits[2]) + 3;

        $this->length = (int) $bits[0];
        $this->version = $bits[1];
        $this->tokenHash = $bits[2];
        $this->value = substr($this->value, $headerLength);
    }
}
