<?php

use Materom\Vigil\Tests\Support\AgentFakes;
use Materom\VigilAgent\Frame;
use Materom\VigilAgent\FrameHandler;
use Materom\VigilAgent\SignatureWatcher;
use Materom\VigilAgent\SpoolDirectory;
use Materom\VigilAgent\SpoolWriter;

beforeEach(function () {
    $this->path = AgentFakes::tempDirectory();
    $this->directory = new SpoolDirectory($this->path);
    $this->logger = AgentFakes::logger();
    $this->hash = Frame::tokenHash('secret');
    $this->handle = function (string $encoded, int $maxBytes = 1073741824): string {
        $frame = new Frame;
        $frame->append($encoded);
        $writer = new SpoolWriter($this->directory, $this->logger, maxBytes: $maxBytes);
        $result = (new FrameHandler($writer, $this->hash, $this->logger))->handle($frame);
        $writer->rotate();

        return $result;
    };
});

afterEach(function () {
    AgentFakes::removeDirectory($this->path);
});

it('spools a frame body before acknowledging it', function () {
    expect(($this->handle)(Frame::encode('[{"t":"log"}]', $this->hash)))->toBe(FrameHandler::ACK)
        ->and(file_get_contents($this->directory->segments()[0]))->toBe("[{\"t\":\"log\"}]\n");
});

it('acknowledges a PING without spooling it', function () {
    expect(($this->handle)(Frame::encode('PING', $this->hash)))->toBe(FrameHandler::ACK)
        ->and($this->directory->segments())->toBe([]);
});

it('refuses a frame with another token hash', function () {
    expect(($this->handle)(Frame::encode('[{"t":"log"}]', Frame::tokenHash('other'))))->toBe(FrameHandler::REFUSE)
        ->and($this->directory->segments())->toBe([])
        ->and(implode("\n", $this->logger->lines))->toContain('Incoming token hash mismatch');
});

it('asks for a shutdown when the payload version changes', function () {
    expect(($this->handle)(Frame::encode('[{"t":"log"}]', $this->hash, 'v2')))->toBe(FrameHandler::SHUTDOWN)
        ->and($this->directory->segments())->toBe([]);
});

it('refuses when the spool is full', function () {
    expect(($this->handle)(Frame::encode('[{"t":"log"}]', $this->hash), maxBytes: 1))->toBe(FrameHandler::ACK)
        ->and(($this->handle)(Frame::encode('[{"t":"log"}]', $this->hash), maxBytes: 1))->toBe(FrameHandler::REFUSE);
});

it('notices a replaced PHAR only once and ignores a missing signature', function () {
    $path = $this->path.'/signature.txt';
    file_put_contents($path, 'old');
    $changes = [];
    $watcher = new SignatureWatcher($path, 'old', function (string $signature) use (&$changes) {
        $changes[] = $signature;
    });

    expect($watcher->check())->toBeFalse();

    unlink($path);
    expect($watcher->check())->toBeFalse();

    file_put_contents($path, 'new');
    expect($watcher->check())->toBeTrue()
        ->and($watcher->check())->toBeTrue()
        ->and($changes)->toBe(['new']);
});

it('resolves the signature of an Envoyer style release to the current symlink', function () {
    expect(SignatureWatcher::resolvePath('/srv/app/releases/123/vendor/materom/vigil/agent/build'))
        ->toBe('/srv/app/releases/123/vendor/materom/vigil/agent/build/signature.txt');
});
