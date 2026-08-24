<?php

declare(strict_types=1);

use App\Models\Mod;
use App\Models\ModEndorsement;
use App\Models\ModVersion;
use App\Models\SptVersion;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->withoutDefer();

    SptVersion::query()->firstOrCreate(['version' => '1.0.0'], SptVersion::factory()->make(['version' => '1.0.0'])->toArray());

    $this->mod = Mod::factory()->create(['published_at' => now()->subHour()]);
    ModVersion::factory()->recycle($this->mod)->create(['spt_version_constraint' => '1.0.0']);

    // Verified, old enough to endorse, and not the author.
    $this->endorser = User::factory()->create(['created_at' => now()->subDays(30)]);
});

/**
 * The text slot of the first toast the component dispatched.
 */
$toastText = fn (array $params): string => (string) ($params['slots']['text'] ?? '');

describe('ModEndorseButton toggling', function (): void {
    it('endorses the mod and moves the counter up by one', function (): void {
        Livewire::actingAs($this->endorser)
            ->test('mod-endorse-button', ['modId' => $this->mod->id])
            ->call('toggle')
            ->assertSuccessful();

        expect($this->mod->fresh()->endorsements_count)->toBe(1)
            ->and(ModEndorsement::query()->active()->where('user_id', $this->endorser->id)->where('mod_id', $this->mod->id)->exists())->toBeTrue();
    });

    it('withdraws the endorsement and moves the counter back down', function (): void {
        $component = Livewire::actingAs($this->endorser)
            ->test('mod-endorse-button', ['modId' => $this->mod->id]);

        $component->call('toggle');
        $component->call('toggle');

        expect($this->mod->fresh()->endorsements_count)->toBe(0);

        $row = ModEndorsement::query()->where('user_id', $this->endorser->id)->where('mod_id', $this->mod->id)->sole();
        expect($row->revoked_at)->not->toBeNull();
    });

    it('flips the rendered state between Endorse and Endorsed', function (): void {
        $component = Livewire::actingAs($this->endorser)
            ->test('mod-endorse-button', ['modId' => $this->mod->id]);

        expect($component->instance()->isEndorsed)->toBeFalse();
        $component->assertSee('Endorse');

        $component->call('toggle');

        expect($component->instance()->isEndorsed)->toBeTrue();
        $component->assertSee('Endorsed');
    });

    it('keeps the original endorsed_at anchor across a withdraw and re-endorse', function (): void {
        $component = Livewire::actingAs($this->endorser)
            ->test('mod-endorse-button', ['modId' => $this->mod->id]);

        $component->call('toggle');

        $anchor = ModEndorsement::query()
            ->where('user_id', $this->endorser->id)
            ->where('mod_id', $this->mod->id)
            ->sole()
            ->endorsed_at;

        $this->travel(10)->days();

        $component->call('toggle');
        $component->call('toggle');

        $row = ModEndorsement::query()
            ->where('user_id', $this->endorser->id)
            ->where('mod_id', $this->mod->id)
            ->sole();

        expect($row->endorsed_at->equalTo($anchor))->toBeTrue()
            ->and($row->revoked_at)->toBeNull()
            ->and($this->mod->fresh()->endorsements_count)->toBe(1);
    });
});

describe('ModEndorseButton eligibility', function () use ($toastText): void {
    it('refuses the mod owner and explains why', function () use ($toastText): void {
        $this->mod->update(['owner_id' => $this->endorser->id]);

        Livewire::actingAs($this->endorser)
            ->test('mod-endorse-button', ['modId' => $this->mod->id])
            ->call('toggle')
            ->assertDispatched('toast-show', fn (string $event, array $params): bool => str_contains($toastText($params), 'You cannot endorse your own mod.'));

        expect(ModEndorsement::query()->count())->toBe(0)
            ->and($this->mod->fresh()->endorsements_count)->toBe(0);
    });

    it('refuses a user with an unverified email address and explains why', function () use ($toastText): void {
        $unverified = User::factory()->unverified()->create(['created_at' => now()->subDays(30)]);

        Livewire::actingAs($unverified)
            ->test('mod-endorse-button', ['modId' => $this->mod->id])
            ->call('toggle')
            ->assertDispatched('toast-show', fn (string $event, array $params): bool => str_contains($toastText($params), 'verify your email address'));

        expect(ModEndorsement::query()->count())->toBe(0)
            ->and($this->mod->fresh()->endorsements_count)->toBe(0);
    });

    it('refuses an account younger than seven days and explains why', function () use ($toastText): void {
        $fresh = User::factory()->create(['created_at' => now()->subDays(6)]);

        Livewire::actingAs($fresh)
            ->test('mod-endorse-button', ['modId' => $this->mod->id])
            ->call('toggle')
            ->assertDispatched('toast-show', fn (string $event, array $params): bool => str_contains($toastText($params), 'at least 7 days old'));

        expect(ModEndorsement::query()->count())->toBe(0)
            ->and($this->mod->fresh()->endorsements_count)->toBe(0);
    });

    it('renders a disabled control with the reason instead of a working button', function (): void {
        $fresh = User::factory()->create(['created_at' => now()->subDays(6)]);

        $component = Livewire::actingAs($fresh)
            ->test('mod-endorse-button', ['modId' => $this->mod->id]);

        expect($component->instance()->denialReason)->toContain('at least 7 days old');
        $component->assertDontSee('data-test="mod-endorse-button"', escape: false);
    });

    it('does not let a guest toggle anything', function (): void {
        Livewire::test('mod-endorse-button', ['modId' => $this->mod->id])
            ->call('toggle')
            ->assertSuccessful();

        expect(ModEndorsement::query()->count())->toBe(0)
            ->and($this->mod->fresh()->endorsements_count)->toBe(0);
    });

    it('offers a guest a login prompt rather than a toggle', function (): void {
        Livewire::test('mod-endorse-button', ['modId' => $this->mod->id])
            ->assertSee(route('login'), escape: false)
            ->assertDontSee('data-test="mod-endorse-button"', escape: false);
    });
});

describe('ModEndorseButton rate limiting', function () use ($toastText): void {
    it('refuses further toggles once the configured attempts are spent', function () use ($toastText): void {
        config()->set('endorsements.rate_limiting.max_attempts', 1);

        $component = Livewire::actingAs($this->endorser)
            ->test('mod-endorse-button', ['modId' => $this->mod->id]);

        $component->call('toggle');

        expect($this->mod->fresh()->endorsements_count)->toBe(1);

        $component->call('toggle')
            ->assertDispatched('toast-show', fn (string $event, array $params): bool => str_contains($toastText($params), 'endorsing too quickly'));

        // The withdraw never happened.
        expect($this->mod->fresh()->endorsements_count)->toBe(1)
            ->and(ModEndorsement::query()->active()->count())->toBe(1);
    });
});

describe('ModEndorseButton authorization', function (): void {
    it('forbids a guest mounting against a disabled mod', function (): void {
        $this->mod->update(['disabled' => true]);

        Livewire::test('mod-endorse-button', ['modId' => $this->mod->id])
            ->assertForbidden();
    });

    // Guards the trait's hydrate hook: a snapshot minted while the mod was visible must stop being served the
    // moment the mod is taken down.
    it('refuses a snapshot minted before the mod was disabled', function (): void {
        $component = Livewire::actingAs($this->endorser)
            ->test('mod-endorse-button', ['modId' => $this->mod->id])
            ->assertSuccessful();

        $this->mod->update(['disabled' => true]);

        $component->call('toggle')->assertForbidden();

        expect(ModEndorsement::query()->count())->toBe(0)
            ->and($this->mod->fresh()->endorsements_count)->toBe(0);
    });
});
