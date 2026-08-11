<?php

declare(strict_types=1);

use App\Models\AccountRecovery;
use App\Models\User;
use App\Support\ArchivedAccountLookupLimiter;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * An archived account plus an outstanding recovery link, returned with the token in the clear.
 *
 * @return array{0: User, 1: string}
 */
function archivedAccountWithLink(string $email = 'owner@example.com', string $state = 'valid'): array
{
    $user = User::factory()->create([
        'email_tombstone' => User::emailTombstoneFor($email),
    ]);

    $token = Str::random(64);

    $factory = AccountRecovery::factory();
    $factory = match ($state) {
        'expired' => $factory->expired(),
        'consumed' => $factory->consumed(),
        default => $factory,
    };

    $factory->create([
        'user_id' => $user->id,
        'email' => $email,
        'token' => hash('sha256', $token),
    ]);

    return [$user, $token];
}

it('hands the account back, signs the claimant in and clears the tombstone', function (): void {
    [$user, $token] = archivedAccountWithLink();

    Livewire::test('pages::account.redeem', ['token' => $token])
        ->set('password', 'a-very-good-password')
        ->set('password_confirmation', 'a-very-good-password')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);

    $user->refresh();

    expect($user->email)->toBe('owner@example.com')
        ->and($user->email_tombstone)->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('a-very-good-password', (string) $user->password))->toBeTrue();

    expect(AccountRecovery::query()->whereNull('consumed_at')->count())->toBe(0);
});

it('rejects a password that fails the registration rules', function (): void {
    [$user, $token] = archivedAccountWithLink();

    Livewire::test('pages::account.redeem', ['token' => $token])
        ->set('password', 'short')
        ->set('password_confirmation', 'short')
        ->call('submit')
        ->assertHasErrors('password')
        ->assertNoRedirect();

    $this->assertGuest();

    expect($user->refresh()->email_tombstone)->not->toBeNull();
});

it('rejects a password that does not match its confirmation', function (): void {
    [, $token] = archivedAccountWithLink();

    Livewire::test('pages::account.redeem', ['token' => $token])
        ->set('password', 'a-very-good-password')
        ->set('password_confirmation', 'a-different-password')
        ->call('submit')
        ->assertHasErrors('password')
        ->assertNoRedirect();

    $this->assertGuest();
});

it('refuses an expired link', function (): void {
    [$user, $token] = archivedAccountWithLink(state: 'expired');

    Livewire::test('pages::account.redeem', ['token' => $token])
        ->set('password', 'a-very-good-password')
        ->set('password_confirmation', 'a-very-good-password')
        ->call('submit')
        ->assertNoRedirect();

    $this->assertGuest();

    expect($user->refresh()->email_tombstone)->not->toBeNull();
});

it('refuses a link that has already been used', function (): void {
    [$user, $token] = archivedAccountWithLink(state: 'consumed');

    Livewire::test('pages::account.redeem', ['token' => $token])
        ->set('password', 'a-very-good-password')
        ->set('password_confirmation', 'a-very-good-password')
        ->call('submit')
        ->assertNoRedirect();

    $this->assertGuest();

    expect($user->refresh()->email_tombstone)->not->toBeNull();
});

it('refuses a token that matches no link', function (): void {
    Livewire::test('pages::account.redeem', ['token' => Str::random(64)])
        ->set('password', 'a-very-good-password')
        ->set('password_confirmation', 'a-very-good-password')
        ->call('submit')
        ->assertNoRedirect();

    $this->assertGuest();
});

it('drops the previous session when a signed-in visitor redeems a link', function (): void {
    [$user, $token] = archivedAccountWithLink();

    $other = User::factory()->create();

    // AuthenticateSession would otherwise carry this into the recovered account's session and log it straight out.
    session(['password_hash_web' => $other->getAuthPassword()]);

    Livewire::actingAs($other)
        ->test('pages::account.redeem', ['token' => $token])
        ->set('password', 'a-very-good-password')
        ->set('password_confirmation', 'a-very-good-password')
        ->call('submit')
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);

    expect(session('password_hash_web'))->not->toBe($other->getAuthPassword());
});

it('stops redeeming links once the per-IP limit is spent', function (): void {
    [$user, $token] = archivedAccountWithLink();

    // The same budget the recovery form and registration draw from, so an attacker cannot move between the forms for a
    // fresh one.
    $key = ArchivedAccountLookupLimiter::key('redeem');

    foreach (range(1, config()->integer('recovery.max_attempts')) as $ignored) {
        RateLimiter::hit($key, config()->integer('recovery.decay_seconds'));
    }

    Livewire::test('pages::account.redeem', ['token' => $token])
        ->set('password', 'a-very-good-password')
        ->set('password_confirmation', 'a-very-good-password')
        ->call('submit')
        ->assertNoRedirect();

    $this->assertGuest();

    expect($user->refresh()->email_tombstone)->not->toBeNull()
        ->and(AccountRecovery::query()->whereNull('consumed_at')->count())->toBe(1);
});

it('serves the redeem page for any well formed token', function (): void {
    $this->get(route('account.recovery.redeem', ['token' => Str::random(64)]))
        ->assertOk()
        ->assertSee('Choose a new password');
});

it('does not route a malformed token to the page', function (): void {
    $this->get('/account/recover/nope')->assertNotFound();
});
