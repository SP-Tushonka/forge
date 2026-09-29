<?php

declare(strict_types=1);

use App\Models\ModSubscription;
use Illuminate\Support\Facades\URL;

it('removes only that subscription through a signed link', function (): void {
    $subscription = ModSubscription::factory()->create();
    $other = ModSubscription::factory()->create(['user_id' => $subscription->user_id]);

    $this->get(URL::signedRoute('mod.unsubscribe', ['user' => $subscription->user_id, 'modId' => $subscription->mod_id]))
        ->assertOk()
        ->assertSee('Successfully Unsubscribed');

    expect(ModSubscription::query()->whereKey($subscription->id)->exists())->toBeFalse()
        ->and(ModSubscription::query()->whereKey($other->id)->exists())->toBeTrue();
});

it('rejects an unsigned link', function (): void {
    $subscription = ModSubscription::factory()->create();

    $this->get(route('mod.unsubscribe', ['user' => $subscription->user_id, 'modId' => $subscription->mod_id]))
        ->assertForbidden();

    expect(ModSubscription::query()->count())->toBe(1);
});
