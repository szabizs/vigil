<?php

namespace Materom\VigilAgent;

/**
 * Decides what happens to one complete frame: spool it and acknowledge, refuse it, or shut the relay down.
 */
final class FrameHandler
{
    public const ACK = 'ack';

    public const REFUSE = 'refuse';

    public const SHUTDOWN = 'shutdown';

    public function __construct(
        private SpoolWriter $spool,
        private string $tokenHash,
        private Logger $logger,
    ) {
        //
    }

    public function handle(Frame $frame): string
    {
        if (! $frame->versionIsValid()) {
            $this->logger->info("Incoming payload version has changed [{$frame->version}]; expected [".Frame::EXPECTED_VERSION.']');

            return self::SHUTDOWN;
        }

        if (! $frame->tokenHashMatches($this->tokenHash)) {
            $this->logger->error("Incoming token hash mismatch [{$frame->tokenHash}]! Check your application/agent configuration (VIGIL_TOKEN).");

            return self::REFUSE;
        }

        if ($frame->isPing()) {
            return self::ACK;
        }

        return $this->spool->append($frame->value) ? self::ACK : self::REFUSE;
    }
}
