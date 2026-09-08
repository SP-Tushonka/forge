<?php

declare(strict_types=1);

use App\Actions\Staff\DetachUserEmail;
use App\Models\User;
use App\Support\UndeliverableAddress;

it('replaces the address with an undeliverable placeholder', function (): void {
    $target = User::factory()->create(['email' => 'real@example.com', 'hub_id' => 4321]);

    $old = resolve(DetachUserEmail::class)->execute($target, recoverable: false);

    $target->refresh();

    expect($old)->toBe('real@example.com')
        ->and($target->email)->toBe('4321@unclaimed.invalid')
        ->and(UndeliverableAddress::check($target->email))->toBeTrue()
        ->and($target->email_verified_at)->toBeNull();
});

it('falls back to the user id when hub_id is null', function (): void {
    $target = User::factory()->create(['email' => 'real@example.com', 'hub_id' => null]);

    resolve(DetachUserEmail::class)->execute($target, recoverable: false);

    expect($target->fresh()->email)->toBe('user'.$target->id.'@unclaimed.invalid');
});

it('writes a tombstone when the removal is recoverable', function (): void {
    $target = User::factory()->create(['email' => 'real@example.com', 'hub_id' => null]);

    resolve(DetachUserEmail::class)->execute($target, recoverable: true);

    expect($target->fresh()->email_tombstone)->toBe(User::emailTombstoneFor('real@example.com'));
});

it('writes no tombstone when the removal is permanent', function (): void {
    $target = User::factory()->create(['email' => 'real@example.com', 'hub_id' => null]);

    resolve(DetachUserEmail::class)->execute($target, recoverable: false);

    expect($target->fresh()->email_tombstone)->toBeNull();
});
