<?php

declare(strict_types=1);

use App\Models\Mod;
use App\Models\ModVersion;
use App\Models\SptVersion;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->withoutDefer();

    SptVersion::query()->firstOrCreate(['version' => '1.0.0'], SptVersion::factory()->make(['version' => '1.0.0'])->toArray());

    $this->mod = Mod::factory()->create(['published_at' => now()->subHour()]);
    ModVersion::factory()->recycle($this->mod)->create(['spt_version_constraint' => '1.0.0']);

    $this->viewer = User::factory()->create();
});

$toastText = fn (array $params): string => (string) ($params['slots']['text'] ?? '');

it('ignores the mod and reloads the page', function (): void {
    Livewire::actingAs($this->viewer)
        ->test('ignore-mod-button', ['modId' => $this->mod->id])
        ->assertSee('Ignore mod')
        ->call('toggle')
        ->assertRedirect($this->mod->detail_url);

    expect($this->viewer->ignoredMods()->where('mod_id', $this->mod->id)->exists())->toBeTrue();
});

it('stops ignoring an ignored mod', function (): void {
    $this->viewer->ignoreMod($this->mod);

    Livewire::actingAs($this->viewer)
        ->test('ignore-mod-button', ['modId' => $this->mod->id])
        ->assertSee('Stop ignoring')
        ->call('toggle');

    expect($this->viewer->ignoredMods()->exists())->toBeFalse();
});

it('refuses the owner', function () use ($toastText): void {
    Livewire::actingAs($this->mod->owner)
        ->test('ignore-mod-button', ['modId' => $this->mod->id])
        ->call('toggle')
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => str_contains($toastText($params), 'You cannot ignore your own mod.'));

    expect($this->mod->owner->ignoredMods()->exists())->toBeFalse();
});

it('is shown on the mod page to other members only', function (): void {
    $this->actingAs($this->viewer)->get($this->mod->detail_url)->assertSee('data-test="ignore-mod-button"', false);
    $this->actingAs($this->mod->owner)->get($this->mod->detail_url)->assertDontSee('data-test="ignore-mod-button"', false);
    auth()->logout();
    $this->get($this->mod->detail_url)->assertDontSee('data-test="ignore-mod-button"', false);
});
