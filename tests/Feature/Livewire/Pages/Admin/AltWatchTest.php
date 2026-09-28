<?php

declare(strict_types=1);

use App\Enums\AltMatchOutcome;
use App\Models\AltWatch;
use App\Models\AltWatchMatch;
use App\Models\User;
use Livewire\Livewire;

it('lets only admins open a watch', function (): void {
    $watch = AltWatch::factory()->create();

    $this->actingAs(User::factory()->create())->get(route('admin.alt-monitoring.watches.show', $watch))->assertForbidden();
    $this->actingAs(User::factory()->moderator()->create())->get(route('admin.alt-monitoring.watches.show', $watch))->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get(route('admin.alt-monitoring.watches.show', $watch))->assertOk();
});

it('shows new matches apart from the accounts that already matched', function (): void {
    $watch = AltWatch::factory()->create(['reason' => 'Came back after a ban']);
    AltWatchMatch::factory()->for($watch, 'watch')->for(User::factory()->create(['name' => 'FreshAlt']))->create();
    AltWatchMatch::factory()->for($watch, 'watch')->for(User::factory()->create(['name' => 'KnownAlt']))->baseline()->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.alt-watch', ['watch' => $watch])
        ->assertSee('Came back after a ban')
        ->assertSeeInOrder(['Matches', 'FreshAlt', 'Already matching when saved', 'KnownAlt']);
});

it('ends a watch and records who ended it', function (): void {
    $admin = User::factory()->admin()->create();
    $watch = AltWatch::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.alt-watch', ['watch' => $watch])
        ->call('endWatch');

    expect($watch->refresh()->ended_at)->not->toBeNull()
        ->and($watch->ended_by)->toBe($admin->id);
});

it('only reviews matches of the watch on screen', function (): void {
    $watch = AltWatch::factory()->create();
    $foreign = AltWatchMatch::factory()->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::admin.alt-watch', ['watch' => $watch])
        ->call('review', $foreign->id, AltMatchOutcome::Confirmed->value);

    expect($foreign->refresh()->review_outcome)->toBeNull();
});
