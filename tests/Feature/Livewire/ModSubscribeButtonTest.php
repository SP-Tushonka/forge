<?php

declare(strict_types=1);

use App\Models\Mod;
use App\Models\ModSubscription;
use App\Models\ModVersion;
use App\Models\SptVersion;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->withoutDefer();

    SptVersion::query()->firstOrCreate(['version' => '1.0.0'], SptVersion::factory()->make(['version' => '1.0.0'])->toArray());

    $this->mod = Mod::factory()->create(['published_at' => now()->subHour()]);
    ModVersion::factory()->recycle($this->mod)->create(['spt_version_constraint' => '1.0.0']);

    $this->subscriber = User::factory()->create();
});

$subscribed = fn (Mod $mod, User $user): bool => ModSubscription::query()
    ->where('mod_id', $mod->id)->where('user_id', $user->id)->exists();

$toastText = fn (array $params): string => (string) ($params['slots']['text'] ?? '');

describe('ModSubscribeButton toggling', function () use ($subscribed): void {
    it('subscribes and then unsubscribes', function () use ($subscribed): void {
        $component = Livewire::actingAs($this->subscriber)
            ->test('mod-subscribe-button', ['modId' => $this->mod->id])
            ->assertDontSee('Subscribed')
            ->call('toggle')
            ->assertSee('Subscribed');

        expect($subscribed($this->mod, $this->subscriber))->toBeTrue();

        $component->call('toggle')->assertDontSee('Subscribed');

        expect($subscribed($this->mod, $this->subscriber))->toBeFalse();
    });

    it('shows guests a login link instead of the toggle', function (): void {
        Livewire::test('mod-subscribe-button', ['modId' => $this->mod->id])
            ->assertSee(route('login'))
            ->assertDontSee('data-test="mod-subscribe-button"', false);
    });
});

describe('ModSubscribeButton eligibility', function () use ($subscribed, $toastText): void {
    it('refuses the owner', function () use ($subscribed, $toastText): void {
        Livewire::actingAs($this->mod->owner)
            ->test('mod-subscribe-button', ['modId' => $this->mod->id])
            ->call('toggle')
            ->assertDispatched('toast-show', fn (string $event, array $params): bool => str_contains($toastText($params), 'You cannot subscribe to your own mod.'));

        expect($subscribed($this->mod, $this->mod->owner))->toBeFalse();
    });

    it('refuses an additional author', function () use ($subscribed, $toastText): void {
        $author = User::factory()->create();
        $this->mod->additionalAuthors()->attach($author);

        Livewire::actingAs($author)
            ->test('mod-subscribe-button', ['modId' => $this->mod->id])
            ->call('toggle')
            ->assertDispatched('toast-show', fn (string $event, array $params): bool => str_contains($toastText($params), 'You cannot subscribe to your own mod.'));

        expect($subscribed($this->mod, $author))->toBeFalse();
    });

    it('is not rendered on the mod page for its owner', function (): void {
        $this->actingAs($this->mod->owner)
            ->get($this->mod->detail_url)
            ->assertDontSee('data-test="mod-subscribe-button"', false);
    });

    it('is rendered on the mod page for other members', function (): void {
        $this->actingAs($this->subscriber)
            ->get($this->mod->detail_url)
            ->assertSee('data-test="mod-subscribe-button"', false);
    });

    it('lets an author who already has a subscription row unsubscribe', function () use ($subscribed): void {
        $author = User::factory()->create();
        $this->mod->additionalAuthors()->attach($author);
        ModSubscription::factory()->for($author)->for($this->mod)->create();

        Livewire::actingAs($author)
            ->test('mod-subscribe-button', ['modId' => $this->mod->id])
            ->call('toggle');

        expect($subscribed($this->mod, $author))->toBeFalse();
    });
});

describe('ModSubscribeButton rate limiting', function () use ($subscribed, $toastText): void {
    it('refuses toggles once the limit is spent', function () use ($subscribed, $toastText): void {
        foreach (range(1, 20) as $attempt) {
            RateLimiter::hit('mod-subscription:'.$this->subscriber->id, 60);
        }

        Livewire::actingAs($this->subscriber)
            ->test('mod-subscribe-button', ['modId' => $this->mod->id])
            ->call('toggle')
            ->assertDispatched('toast-show', fn (string $event, array $params): bool => str_contains($toastText($params), 'subscribing too quickly'));

        expect($subscribed($this->mod, $this->subscriber))->toBeFalse();
    });

    it('exempts staff', function () use ($subscribed): void {
        $admin = User::factory()->admin()->create();
        foreach (range(1, 20) as $attempt) {
            RateLimiter::hit('mod-subscription:'.$admin->id, 60);
        }

        Livewire::actingAs($admin)
            ->test('mod-subscribe-button', ['modId' => $this->mod->id])
            ->call('toggle');

        expect($subscribed($this->mod, $admin))->toBeTrue();
    });
});

describe('ModSubscribeButton authorization', function (): void {
    it('refuses a snapshot minted before the mod was disabled', function (): void {
        $component = Livewire::actingAs($this->subscriber)
            ->test('mod-subscribe-button', ['modId' => $this->mod->id])
            ->assertSuccessful();

        $this->mod->update(['disabled' => true]);

        $component->call('toggle')->assertForbidden();

        expect(ModSubscription::query()->count())->toBe(0);
    });
});
