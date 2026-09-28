<?php

declare(strict_types=1);

use App\Enums\AltIndicatorType;
use App\Models\AltWatch;
use App\Models\AltWatchIndicator;
use App\Models\User;
use App\Models\UserDevice;
use App\Support\DataTransferObjects\AltIndicator;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

function formTrackEvent(int $visitorId, string $ip): void
{
    DB::table('tracking_events')->insert([
        'event_name' => 'login',
        'is_moderation_action' => false,
        'ip' => $ip,
        'country_code' => 'DE',
        'visitor_type' => User::class,
        'visitor_id' => $visitorId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('lets only admins open the create and edit screens', function (): void {
    $watch = AltWatch::factory()->create();

    foreach ([User::factory()->create(), User::factory()->moderator()->create()] as $user) {
        $this->actingAs($user)->get(route('admin.alt-monitoring.watches.create'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.alt-monitoring.watches.edit', $watch))->assertForbidden();
    }

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->get(route('admin.alt-monitoring.watches.create'))->assertOk();
    $this->actingAs($admin)->get(route('admin.alt-monitoring.watches.edit', $watch))->assertOk();
});

it('arrives with the user picked from the Alt Detection page', function (): void {
    $watched = User::factory()->create(['name' => 'Evader']);
    UserDevice::factory()->for($watched)->create(['browser' => 'Firefox', 'platform' => 'Linux']);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::withQueryParams(['user' => $watched->id])
        ->test('pages::admin.alt-watch-form')
        ->assertSet('watchedUserId', $watched->id)
        ->assertSee('Evader')
        ->assertSee('Firefox on Linux');
});

it('keeps the checklist across requests when the cache serializes what it stores', function (): void {
    // Redis serializes, and config/cache.php sets serializable_classes to false, so cached objects come back broken.
    config(['cache.stores.array.serialize' => true]);
    Cache::forgetDriver('array');
    $watched = User::factory()->create();
    formTrackEvent($watched->id, '84.12.3.77');
    $ip = new AltIndicator(AltIndicatorType::Ip, '84.12.3.77', '84.12.3.77');
    $this->actingAs(User::factory()->admin()->create());

    Livewire::withQueryParams(['user' => $watched->id])
        ->test('pages::admin.alt-watch-form')
        ->set('selected', [$ip->key()])
        ->assertHasNoErrors()
        ->assertSee('84.12.3.0/24')
        ->assertSee('No other account matches today.');
});

it('saves a watch with the ticked indicators and records existing matches without alerting', function (): void {
    Notification::fake();
    $admin = User::factory()->admin()->create();
    $watched = User::factory()->create();
    $existing = User::factory()->create();
    formTrackEvent($watched->id, '84.12.3.77');
    formTrackEvent($existing->id, '84.12.3.77');
    $ip = new AltIndicator(AltIndicatorType::Ip, '84.12.3.77', '84.12.3.77');
    $this->actingAs($admin);

    Livewire::withQueryParams(['user' => $watched->id])
        ->test('pages::admin.alt-watch-form')
        ->set('selected', [$ip->key()])
        ->set('reason', 'Came back after a ban')
        ->set('days', 30)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $watch = AltWatch::query()->sole();
    expect($watch->watched_user_id)->toBe($watched->id)
        ->and($watch->created_by)->toBe($admin->id)
        ->and($watch->reason)->toBe('Came back after a ban')
        ->and($watch->indicators()->sole()->value)->toBe('84.12.3.77')
        ->and($watch->matches()->sole()->user_id)->toBe($existing->id)
        ->and($watch->matches()->sole()->baseline)->toBeTrue();
    Notification::assertNothingSent();
});

it('requires a reason and at least one identifier', function (): void {
    $watched = User::factory()->create();
    formTrackEvent($watched->id, '84.12.3.77');
    $country = new AltIndicator(AltIndicatorType::Country, 'DE', 'DE');
    $this->actingAs(User::factory()->admin()->create());

    Livewire::withQueryParams(['user' => $watched->id])
        ->test('pages::admin.alt-watch-form')
        ->set('selected', [$country->key()])
        ->call('save')
        ->assertHasErrors(['reason'])
        ->set('reason', 'Suspected alt')
        ->call('save')
        ->assertHasErrors(['selected']);

    expect(AltWatch::query()->count())->toBe(0);
});

it('refuses to save a watch that matches too many accounts', function (): void {
    $watched = User::factory()->create(['email' => 'watched@crowd.test']);
    User::factory()->count(101)->sequence(fn (Sequence $sequence): array => ['email' => 'member'.$sequence->index.'@crowd.test'])->create();
    $domain = new AltIndicator(AltIndicatorType::EmailDomain, 'crowd.test', 'crowd.test');
    $this->actingAs(User::factory()->admin()->create());

    Livewire::withQueryParams(['user' => $watched->id])
        ->test('pages::admin.alt-watch-form')
        ->set('selected', [$domain->key()])
        ->set('reason', 'Suspected alt')
        ->call('save')
        ->assertHasErrors(['selected']);

    expect(AltWatch::query()->count())->toBe(0);
});

it('edits a watch, keeping its indicators and expiry unless changed', function (): void {
    $watch = AltWatch::factory()->create(['reason' => 'Old reason']);
    $stored = AltWatchIndicator::factory()->for($watch, 'watch')->create(['type' => AltIndicatorType::Ip, 'value' => '198.51.100.9']);
    $expiry = $watch->expires_at->toDateTimeString();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.alt-watch-form', ['watch' => $watch])
        ->assertSet('selected', [$stored->toIndicator()->key()])
        ->assertSee('198.51.100.9')
        ->set('reason', 'New reason')
        ->call('save')
        ->assertHasNoErrors();

    expect($watch->refresh()->reason)->toBe('New reason')
        ->and($watch->expires_at->toDateTimeString())->toBe($expiry)
        ->and($watch->indicators()->sole()->id)->toBe($stored->id);
});

it('does not edit an ended watch', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.alt-monitoring.watches.edit', AltWatch::factory()->ended()->create()))
        ->assertNotFound();
});
