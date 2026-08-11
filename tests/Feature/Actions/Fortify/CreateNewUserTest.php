<?php

declare(strict_types=1);

use App\Actions\Fortify\CreateNewUser;
use App\Models\DisposableEmailBlocklist;
use App\Models\User;
use App\Support\ArchivedAccountLookupLimiter;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Features;

describe('registration', function (): void {
    it('can render registration screen', function (): void {
        $response = $this->get('/register');

        $response->assertStatus(200);
    })->skip(fn (): bool => ! Features::enabled(Features::registration()), 'Registration support is not enabled.');

    it('allows new users to register', function (): void {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'timezone' => 'America/New_York',
            'terms' => true,
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    })->skip(fn (): bool => ! Features::enabled(Features::registration()), 'Registration support is not enabled.');

    it('rejects passwords exceeding maximum length', function (): void {
        $longPassword = str_repeat('a', 129);

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => $longPassword,
            'password_confirmation' => $longPassword,
            'timezone' => 'America/New_York',
            'terms' => true,
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
    })->skip(fn (): bool => ! Features::enabled(Features::registration()), 'Registration support is not enabled.');

    it('renders validation error messages on the registration form', function (): void {
        $response = $this->followingRedirects()->from('/register')->post('/register', [
            'name' => '',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'different',
            'timezone' => 'America/New_York',
            'terms' => false,
        ]);

        $response->assertOk();
        $response->assertSee('The name field is required.');
        $response->assertSee('The email field must be a valid email address.');
        $response->assertSee('The terms field must be accepted.');
        $this->assertGuest();
    })->skip(fn (): bool => ! Features::enabled(Features::registration()), 'Registration support is not enabled.');

    it('rejects registration with a disposable email address', function (): void {
        DisposableEmailBlocklist::query()->create(['domain' => 'tempmail.com']);

        $response = $this->followingRedirects()->from('/register')->post('/register', [
            'name' => 'Test User',
            'email' => 'test@tempmail.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'timezone' => 'America/New_York',
            'terms' => true,
        ]);

        $response->assertOk();
        $response->assertSee('This email address has been detected as disposable and is not supported.');
        $this->assertGuest();
    })->skip(fn (): bool => ! Features::enabled(Features::registration()), 'Registration support is not enabled.');

    it('translates a duplicate-email registration race into a validation error', function (): void {
        $input = [
            'name' => 'Racer One',
            'email' => 'race@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'timezone' => 'America/New_York',
            'terms' => true,
        ];

        // Simulate the race: a concurrent request claims the same email after our unique validation has passed but
        // before our insert runs. The creating hook fires once (guarded) so the conflicting factory insert that wins
        // the row does not recurse.
        $conflictInserted = false;
        User::creating(function (User $user) use (&$conflictInserted): void {
            if ($conflictInserted) {
                return;
            }

            $conflictInserted = true;
            User::factory()->create(['email' => $user->email]);
        });

        try {
            new CreateNewUser()->create($input);
            $this->fail('Expected a ValidationException for the duplicate email.');
        } catch (ValidationException $validationException) {
            expect($validationException->errors())->toHaveKey('email');
        }

        // The savepoint rollback discards both the losing insert and the simulated winner inserted on the same
        // connection, so no row persists. A production winner runs on its own connection and is unaffected.
        expect(User::query()->where('email', 'race@example.com')->count())->toBe(0);
    });
});

describe('archived accounts', function (): void {
    it('points an archived address at recovery instead of creating a user', function (): void {
        User::factory()->create([
            'email_tombstone' => User::emailTombstoneFor('returning@example.com'),
        ]);

        try {
            new CreateNewUser()->create([
                'name' => 'Returning User',
                'email' => 'returning@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
                'timezone' => 'America/New_York',
                'terms' => '1',
            ]);

            $this->fail('Expected a ValidationException for the archived address.');
        } catch (ValidationException $validationException) {
            expect($validationException->errors())->toHaveKey('email')
                ->and($validationException->errors()['email'][0])->toContain('old Forge')
                ->and($validationException->errors()['email'][0])->toContain(route('account.recovery.request'));
        }

        expect(User::query()->where('email', 'returning@example.com')->exists())->toBeFalse();
    });

    it('refuses to register over an address that still has an archived account', function (): void {
        User::factory()->create([
            'email_tombstone' => User::emailTombstoneFor('returning@example.com'),
        ]);

        $response = $this->followingRedirects()->from('/register')->post('/register', [
            'name' => 'Returning User',
            'email' => 'returning@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'timezone' => 'America/New_York',
            'terms' => true,
        ]);

        $response->assertOk();
        $response->assertSee('This address belonged to an account on the old Forge.');
        $response->assertSee(route('account.recovery.request'));

        $this->assertGuest();

        expect(User::query()->where('email', 'returning@example.com')->exists())->toBeFalse();
    })->skip(fn (): bool => ! Features::enabled(Features::registration()), 'Registration support is not enabled.');

    it('still registers an address that no archived account is holding', function (): void {
        User::factory()->create([
            'email_tombstone' => User::emailTombstoneFor('returning@example.com'),
        ]);

        $user = new CreateNewUser()->create([
            'name' => 'Fresh User',
            'email' => 'fresh@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'timezone' => 'America/New_York',
            'terms' => '1',
        ]);

        expect($user->email)->toBe('fresh@example.com');
    });

    it('stops looking archived accounts up once the per-IP limit is spent', function (): void {
        // Tombstoned, so the rate limit message rather than the recovery one proves the lookup never ran.
        User::factory()->create([
            'email_tombstone' => User::emailTombstoneFor('limited@example.com'),
        ]);

        $key = ArchivedAccountLookupLimiter::key();

        foreach (range(1, config()->integer('recovery.max_attempts')) as $ignored) {
            RateLimiter::hit($key, config()->integer('recovery.decay_seconds'));
        }

        try {
            new CreateNewUser()->create([
                'name' => 'Limited User',
                'email' => 'limited@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
                'timezone' => 'America/New_York',
                'terms' => '1',
            ]);

            $this->fail('Expected the per-IP limiter to reject the lookup.');
        } catch (ValidationException $validationException) {
            expect($validationException->errors())->toHaveKey('email')
                ->and($validationException->errors()['email'][0])->toBe('Too many attempts. Please try again later.');
        }

        expect(User::query()->where('email', 'limited@example.com')->exists())->toBeFalse();
    });
});
