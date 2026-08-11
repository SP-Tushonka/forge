<?php

declare(strict_types=1);

use App\Models\AccountRecovery;
use App\Models\User;
use App\Notifications\AccountRecoveryNotification;
use App\Support\ArchivedAccountLookupLimiter;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Livewire;

it('renders the request page for guests', function (): void {
    $this->get(route('account.recovery.request'))
        ->assertOk()
        ->assertSee('Recover your account');
});

it('sends a recovery link for an address that reproduces a tombstone', function (): void {
    Notification::fake();

    $user = User::factory()->create([
        'email_tombstone' => User::emailTombstoneFor('owner@example.com'),
    ]);

    Livewire::test('pages::account.recover')
        ->set('email', 'Owner@Example.com')
        ->call('submit')
        ->assertHasNoErrors();

    Notification::assertSentOnDemand(AccountRecoveryNotification::class);

    $recovery = AccountRecovery::query()->where('user_id', $user->id)->sole();

    expect($recovery->email)->toBe('owner@example.com')
        ->and($recovery->consumed_at)->toBeNull();
});

it('sends nothing for an address with no archived account', function (): void {
    Notification::fake();

    Livewire::test('pages::account.recover')
        ->set('email', 'stranger@example.com')
        ->call('submit')
        ->assertHasNoErrors();

    Notification::assertNothingSent();

    expect(AccountRecovery::query()->count())->toBe(0);
});

it('rejects an address that is not an email', function (): void {
    Notification::fake();

    Livewire::test('pages::account.recover')
        ->set('email', 'not-an-email')
        ->call('submit')
        ->assertHasErrors(['email' => 'email']);

    Notification::assertNothingSent();
});

it('stops requesting links once the per-IP limit is spent', function (): void {
    Notification::fake();

    $key = ArchivedAccountLookupLimiter::key();

    foreach (range(1, config()->integer('recovery.max_attempts')) as $ignored) {
        RateLimiter::hit($key, config()->integer('recovery.decay_seconds'));
    }

    User::factory()->create([
        'email_tombstone' => User::emailTombstoneFor('owner@example.com'),
    ]);

    Livewire::test('pages::account.recover')
        ->set('email', 'owner@example.com')
        ->call('submit')
        ->assertHasNoErrors();

    Notification::assertNothingSent();

    expect(AccountRecovery::query()->count())->toBe(0);
});

it('emails a token that redeems the link it was stored against', function (): void {
    Notification::fake();

    User::factory()->create([
        'email_tombstone' => User::emailTombstoneFor('owner@example.com'),
    ]);

    Livewire::test('pages::account.recover')
        ->set('email', 'owner@example.com')
        ->call('submit');

    $recovery = AccountRecovery::query()->sole();

    Notification::assertSentOnDemand(
        AccountRecoveryNotification::class,
        function (AccountRecoveryNotification $notification) use ($recovery): bool {
            $emailed = Str::afterLast($notification->toMail(new AnonymousNotifiable)->actionUrl ?? '', '/');

            return hash('sha256', $emailed) === $recovery->token;
        },
    );
});
