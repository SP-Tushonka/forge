<?php

declare(strict_types=1);

use App\Models\CommentVersion;
use App\Models\Message;
use App\Models\Mod;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
});

it('censors pre-existing content and leaves clean content alone', function (): void {
    config()->set('censor.words', '');
    $mod = Mod::factory()->create(['name' => 'Tackle Overhaul']);
    $message = Message::factory()->create(['content' => 'a clean message']);
    $version = CommentVersion::factory()->create(['body' => 'nice ramp move', 'translated_body' => null]);

    config()->set('censor.words', 'tackle,ramp');

    $this->artisan('censor:existing')
        ->expectsConfirmation('This permanently rewrites stored content containing censored words. Continue?', 'yes')
        ->assertSuccessful();

    expect($mod->refresh()->getRawOriginal('name'))->toBe('T***** Overhaul')
        ->and($message->refresh()->getRawOriginal('content'))->toBe('a clean message')
        ->and($version->refresh()->getRawOriginal('body'))->toBe('nice r*** move')
        ->and($version->getRawOriginal('translated_body'))->toBeNull();
});

it('reports without saving when run with --dry-run', function (): void {
    config()->set('censor.words', '');
    $mod = Mod::factory()->create(['name' => 'Tackle Overhaul']);

    config()->set('censor.words', 'tackle');

    $this->artisan('censor:existing', ['--dry-run' => true])->assertSuccessful();

    expect($mod->refresh()->getRawOriginal('name'))->toBe('Tackle Overhaul');
});

it('warns and exits when no words are configured', function (): void {
    config()->set('censor.words', '');

    $this->artisan('censor:existing')
        ->expectsOutputToContain('No censored words configured')
        ->assertSuccessful();
});

it('runs without a confirmation prompt when forced', function (): void {
    config()->set('censor.words', '');
    $mod = Mod::factory()->create(['name' => 'Tackle Overhaul']);

    config()->set('censor.words', 'tackle');

    $this->artisan('censor:existing', ['--force' => true])->assertSuccessful();

    expect($mod->refresh()->getRawOriginal('name'))->toBe('T***** Overhaul');
});

it('records the word list hash after a completed run', function (): void {
    config()->set('censor.words', 'tackle');

    $this->artisan('censor:existing', ['--force' => true])->assertSuccessful();

    expect(Storage::disk('local')->get('censor-words.hash'))->toBe(hash('sha256', 'tackle'));
});

it('skips the sweep when the word list has not changed since the last run', function (): void {
    config()->set('censor.words', '');
    $mod = Mod::factory()->create(['name' => 'Tackle Overhaul']);

    config()->set('censor.words', 'tackle');
    Storage::disk('local')->put('censor-words.hash', hash('sha256', 'tackle'));

    $this->artisan('censor:existing', ['--force' => true, '--if-changed' => true])
        ->expectsOutputToContain('Word list unchanged')
        ->assertSuccessful();

    expect($mod->refresh()->getRawOriginal('name'))->toBe('Tackle Overhaul');
});

it('sweeps when the word list differs from the recorded hash', function (): void {
    config()->set('censor.words', '');
    $mod = Mod::factory()->create(['name' => 'Tackle Overhaul']);

    config()->set('censor.words', 'tackle');
    Storage::disk('local')->put('censor-words.hash', hash('sha256', 'old-list'));

    $this->artisan('censor:existing', ['--force' => true, '--if-changed' => true])->assertSuccessful();

    expect($mod->refresh()->getRawOriginal('name'))->toBe('T***** Overhaul')
        ->and(Storage::disk('local')->get('censor-words.hash'))->toBe(hash('sha256', 'tackle'));
});

it('does not record the hash on a dry run', function (): void {
    config()->set('censor.words', 'tackle');

    $this->artisan('censor:existing', ['--dry-run' => true])->assertSuccessful();

    expect(Storage::disk('local')->exists('censor-words.hash'))->toBeFalse();
});
