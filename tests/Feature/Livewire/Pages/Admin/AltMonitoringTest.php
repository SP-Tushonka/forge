<?php

declare(strict_types=1);

use App\Enums\AltIndicatorType;
use App\Enums\AltMatchOutcome;
use App\Models\AltWatch;
use App\Models\AltWatchIndicator;
use App\Models\AltWatchMatch;
use App\Models\User;
use Livewire\Livewire;

it('lets only admins open the dashboard', function (): void {
    $this->actingAs(User::factory()->create())->get(route('admin.alt-monitoring'))->assertForbidden();
    $this->actingAs(User::factory()->moderator()->create())->get(route('admin.alt-monitoring'))->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get(route('admin.alt-monitoring'))->assertOk();
});

it('shows "Alt Monitoring" in the admin menu', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/user-management')
        ->assertSee(route('admin.alt-monitoring'), false)
        ->assertSee('Alt Monitoring');
});

it('lists unreviewed new matches and active watches', function (): void {
    $admin = User::factory()->admin()->create();
    $watch = AltWatch::factory()->for(User::factory()->create(['name' => 'Evader']), 'watchedUser')->create(['reason' => 'Came back after a ban']);
    $indicator = AltWatchIndicator::factory()->for($watch, 'watch')->create(['type' => AltIndicatorType::Device, 'value' => str_repeat('a', 64)]);
    AltWatchMatch::factory()->for($watch, 'watch')->for(User::factory()->create(['name' => 'FreshAlt']))->create(['matched_indicator_ids' => [$indicator->id]]);
    AltWatchMatch::factory()->for($watch, 'watch')->for(User::factory()->create(['name' => 'KnownAlt']))->baseline()->create();
    AltWatch::factory()->ended()->for(User::factory()->create(['name' => 'OldCase']), 'watchedUser')->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.alt-monitoring')
        ->assertSee('FreshAlt')
        ->assertSee('Evader')
        ->assertSee('Came back after a ban')
        ->assertDontSee('KnownAlt')
        ->assertDontSee('OldCase')
        ->set('filter', 'all')
        ->assertSee('OldCase');
});

it('confirms and dismisses matches, recording the reviewer', function (): void {
    $admin = User::factory()->admin()->create();
    $confirm = AltWatchMatch::factory()->create();
    $dismiss = AltWatchMatch::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.alt-monitoring')
        ->call('review', $confirm->id, 'confirmed')
        ->call('review', $dismiss->id, 'dismissed');

    expect($confirm->refresh()->review_outcome)->toBe(AltMatchOutcome::Confirmed)
        ->and($confirm->reviewed_by)->toBe($admin->id)
        ->and($dismiss->refresh()->review_outcome)->toBe(AltMatchOutcome::Dismissed);
});
