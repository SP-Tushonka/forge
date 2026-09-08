<?php

declare(strict_types=1);

use App\Actions\Staff\DetachUserEmail;
use App\Actions\Staff\TransferModOwnership;
use App\Enums\TrackingEventType;
use App\Exceptions\StaffActionException;
use App\Models\Mod;
use App\Models\TrackingEvent;
use App\Models\User;
use App\Notifications\ModOwnershipChangedNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;

it('transfers ownership and records a moderation action', function (): void {
    Notification::fake();

    $staff = User::factory()->admin()->create();
    $previous = User::factory()->create();
    $next = User::factory()->create();
    $mod = Mod::factory()->recycle($previous)->create();

    resolve(TransferModOwnership::class)->execute($staff, $mod, $next, false, 'Original author asked us to');

    expect($mod->fresh()->owner_id)->toBe($next->id);

    $event = TrackingEvent::query()
        ->where('event_name', TrackingEventType::MOD_OWNERSHIP_TRANSFER->value)
        ->sole();

    expect($event->is_moderation_action)->toBeTrue()
        ->and($event->reason)->toBe('Original author asked us to');
});

it('notifies both the previous and the new owner', function (): void {
    Notification::fake();

    $staff = User::factory()->admin()->create();
    $previous = User::factory()->create();
    $next = User::factory()->create();
    $mod = Mod::factory()->recycle($previous)->create();

    resolve(TransferModOwnership::class)->execute($staff, $mod, $next, false, 'Handover');

    Notification::assertSentTo($previous, ModOwnershipChangedNotification::class);
    Notification::assertSentTo($next, ModOwnershipChangedNotification::class);
});

it('keeps the previous owner as an additional author when asked', function (): void {
    Notification::fake();

    $staff = User::factory()->admin()->create();
    $previous = User::factory()->create();
    $next = User::factory()->create();
    $mod = Mod::factory()->recycle($previous)->create();

    resolve(TransferModOwnership::class)->execute($staff, $mod, $next, true, 'Handover');

    expect($mod->fresh()->additionalAuthors->pluck('id')->all())->toContain($previous->id);
});

it('does not keep the previous owner as an author when not asked', function (): void {
    Notification::fake();

    $staff = User::factory()->admin()->create();
    $previous = User::factory()->create();
    $next = User::factory()->create();
    $mod = Mod::factory()->recycle($previous)->create();

    resolve(TransferModOwnership::class)->execute($staff, $mod, $next, false, 'Handover');

    expect($mod->fresh()->additionalAuthors->pluck('id')->all())->not->toContain($previous->id);
});

it('detaches the new owner from the additional authors', function (): void {
    Notification::fake();

    $staff = User::factory()->admin()->create();
    $previous = User::factory()->create();
    $next = User::factory()->create();
    $mod = Mod::factory()->recycle($previous)->create();
    $mod->additionalAuthors()->attach($next->id);

    resolve(TransferModOwnership::class)->execute($staff, $mod, $next, false, 'Handover');

    expect($mod->fresh()->additionalAuthors->pluck('id')->all())->not->toContain($next->id);
});

it('refuses to transfer to the current owner', function (): void {
    $staff = User::factory()->admin()->create();
    $owner = User::factory()->create();
    $mod = Mod::factory()->recycle($owner)->create();

    resolve(TransferModOwnership::class)->execute($staff, $mod, $owner, false, 'nope');
})->throws(StaffActionException::class, 'That user already owns this mod.');

it('refuses to transfer to a banned account', function (): void {
    $staff = User::factory()->admin()->create();
    $owner = User::factory()->create();
    $banned = User::factory()->create();
    $banned->ban();
    $mod = Mod::factory()->recycle($owner)->create();

    resolve(TransferModOwnership::class)->execute($staff, $mod, $banned->fresh(), false, 'nope');
})->throws(StaffActionException::class, 'Cannot transfer ownership to a banned account.');

it('refuses to transfer to a locked account', function (): void {
    $staff = User::factory()->admin()->create();
    $owner = User::factory()->create();
    $locked = User::factory()->create();
    $mod = Mod::factory()->recycle($owner)->create();

    // Exactly what LockAccount leaves behind: an undeliverable address that can never verify.
    resolve(DetachUserEmail::class)->execute($locked, false);

    resolve(TransferModOwnership::class)->execute($staff, $mod, $locked->fresh(), false, 'nope');
})->throws(StaffActionException::class, 'Cannot transfer ownership to an account with an unverified email address.');

it('refuses to transfer to an account that has never verified its email', function (): void {
    $staff = User::factory()->admin()->create();
    $owner = User::factory()->create();
    $unverified = User::factory()->unverified()->create();
    $mod = Mod::factory()->recycle($owner)->create();

    resolve(TransferModOwnership::class)->execute($staff, $mod, $unverified, false, 'nope');
})->throws(StaffActionException::class, 'Cannot transfer ownership to an account with an unverified email address.');

it('refuses to transfer to someone with a block relationship with an author', function (): void {
    $staff = User::factory()->admin()->create();
    $owner = User::factory()->create();
    $author = User::factory()->create();
    $next = User::factory()->create();

    $mod = Mod::factory()->recycle($owner)->create();
    $mod->additionalAuthors()->attach($author->id);
    $next->block($author);

    resolve(TransferModOwnership::class)->execute($staff, $mod, $next, false, 'nope');
})->throws(StaffActionException::class);

it('refuses a moderator', function (): void {
    $moderator = User::factory()->moderator()->create();
    $owner = User::factory()->create();
    $next = User::factory()->create();
    $mod = Mod::factory()->recycle($owner)->create();

    resolve(TransferModOwnership::class)->execute($moderator, $mod, $next, false, 'nope');
})->throws(AuthorizationException::class);

it('refuses a regular user', function (): void {
    $actor = User::factory()->create();
    $owner = User::factory()->create();
    $next = User::factory()->create();
    $mod = Mod::factory()->recycle($owner)->create();

    resolve(TransferModOwnership::class)->execute($actor, $mod, $next, false, 'nope');
})->throws(AuthorizationException::class);
