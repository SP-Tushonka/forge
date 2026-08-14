<?php

declare(strict_types=1);

use App\Logging\DeduplicatingHandler;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;

function deprecationRecord(string $message): LogRecord
{
    return new LogRecord(new DateTimeImmutable, 'testing', Level::Warning, $message);
}

beforeEach(function (): void {
    $this->markers = sys_get_temp_dir().'/dedup-'.bin2hex(random_bytes(6));
    $this->inner = new TestHandler;
});

afterEach(function (): void {
    if (is_dir($this->markers)) {
        array_map(unlink(...), (array) glob($this->markers.'/*'));
        rmdir($this->markers);
    }
});

it('writes the first record and drops identical repeats', function (): void {
    $handler = new DeduplicatingHandler($this->inner, $this->markers, 3600);

    for ($i = 0; $i < 1000; $i++) {
        $handler->handle(deprecationRecord('Method Example::foo() is deprecated'));
    }

    expect($this->inner->getRecords())->toHaveCount(1);
});

it('writes each distinct message once', function (): void {
    $handler = new DeduplicatingHandler($this->inner, $this->markers, 3600);

    foreach (['first notice', 'second notice', 'first notice', 'third notice'] as $message) {
        $handler->handle(deprecationRecord($message));
    }

    expect($this->inner->getRecords())->toHaveCount(3);
});

it('suppresses a repeat raised by a separate process within the window', function (): void {
    // A second handler instance has an empty in-process cache, so only the marker file can stop it.
    (new DeduplicatingHandler($this->inner, $this->markers, 3600))->handle(deprecationRecord('repeated'));
    (new DeduplicatingHandler($this->inner, $this->markers, 3600))->handle(deprecationRecord('repeated'));

    expect($this->inner->getRecords())->toHaveCount(1);
});

it('writes again once the window has expired', function (): void {
    (new DeduplicatingHandler($this->inner, $this->markers, 3600))->handle(deprecationRecord('repeated'));

    // Age the marker past the window rather than sleeping through it.
    touch((string) glob($this->markers.'/*')[0], time() - 7200);

    (new DeduplicatingHandler($this->inner, $this->markers, 3600))->handle(deprecationRecord('repeated'));

    expect($this->inner->getRecords())->toHaveCount(2);
});

it('still writes when the marker directory cannot be created', function (): void {
    // A path underneath an existing file: mkdir fails the way a permission denial would.
    $blocker = tempnam(sys_get_temp_dir(), 'dedup');
    $handler = new DeduplicatingHandler($this->inner, $blocker.'/markers', 3600);

    $handler->handle(deprecationRecord('first'));
    $handler->handle(deprecationRecord('second'));

    unlink($blocker);

    expect($this->inner->getRecords())->toHaveCount(2);
});

it('never propagates a filesystem error out of the logger', function (): void {
    $handler = new DeduplicatingHandler($this->inner, "\0invalid", 3600);

    $handler->handle(deprecationRecord('first'));

    expect($this->inner->getRecords())->toHaveCount(1);
});
