<?php

declare(strict_types=1);

use App\Models\CommentVersion;
use App\Models\Message;
use App\Models\Mod;
use App\Models\ModIssue;
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

    expect($mod->refresh()->getRawOriginal('name'))->toBe('T∗∗∗∗∗ Overhaul')
        ->and($message->refresh()->getRawOriginal('content'))->toBe('a clean message')
        ->and($version->refresh()->getRawOriginal('body'))->toBe('nice r∗∗∗ move')
        ->and($version->getRawOriginal('translated_body'))->toBeNull();
});

it('censors pre-existing mod issues', function (): void {
    config()->set('censor.words', '');
    $issue = ModIssue::factory()->create(['title' => 'Tackle crash', 'body' => 'the tackle breaks']);

    config()->set('censor.words', 'tackle');

    $this->artisan('censor:existing', ['--force' => true])->assertSuccessful();

    expect($issue->refresh()->getRawOriginal('title'))->toBe('T∗∗∗∗∗ crash')
        ->and($issue->getRawOriginal('body'))->toBe('the t∗∗∗∗∗ breaks');
});

it('censors soft deleted mod issues too', function (): void {
    config()->set('censor.words', '');
    $issue = ModIssue::factory()->create(['title' => 'Tackle crash']);
    $issue->delete();

    config()->set('censor.words', 'tackle');

    $this->artisan('censor:existing', ['--force' => true])->assertSuccessful();

    expect(ModIssue::withTrashed()->findOrFail($issue->id)->getRawOriginal('title'))->toBe('T∗∗∗∗∗ crash');
});

it('leaves masks stored with the old ASCII asterisks to censor:repair-masks', function (): void {
    config()->set('censor.words', '');
    $version = CommentVersion::factory()->create(['body' => 'thanks B**', 'translated_body' => null]);

    config()->set('censor.words', 'bsg');

    $this->artisan('censor:existing', ['--force' => true])
        ->expectsOutputToContain('CommentVersion: 0 record(s) updated')
        ->assertSuccessful();

    expect($version->refresh()->getRawOriginal('body'))->toBe('thanks B**');
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

    expect($mod->refresh()->getRawOriginal('name'))->toBe('T∗∗∗∗∗ Overhaul');
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

    expect($mod->refresh()->getRawOriginal('name'))->toBe('T∗∗∗∗∗ Overhaul')
        ->and(Storage::disk('local')->get('censor-words.hash'))->toBe(hash('sha256', 'tackle'));
});

it('does not record the hash on a dry run', function (): void {
    config()->set('censor.words', 'tackle');

    $this->artisan('censor:existing', ['--dry-run' => true])->assertSuccessful();

    expect(Storage::disk('local')->exists('censor-words.hash'))->toBeFalse();
});
