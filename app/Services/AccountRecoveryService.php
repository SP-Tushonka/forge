<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccountRecoveryOutcome;
use App\Exceptions\AccountNotArchivedException;
use App\Exceptions\EmailAlreadyRegisteredException;
use App\Models\AccountRecovery;
use App\Models\User;
use App\Notifications\AccountRecoveryNotification;
use App\Support\DataTransferObjects\RecoveryAttempt;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Hands archived accounts back to the people who owned them on the retired Forge.
 *
 * The user base was imported with its addresses anonymised, leaving only a keyed digest of the original address in
 * users.email_tombstone. Proving control of an address that reproduces one of those digests proves ownership of the
 * account. Recovery is one-way: {@see self::applyRecovery()} clears the tombstone, so an account can never be handed
 * out twice.
 */
final readonly class AccountRecoveryService
{
    /**
     * Find the archived account whose tombstone this address reproduces.
     */
    public function findArchivedAccount(#[SensitiveParameter] string $email): ?User
    {
        return User::query()
            ->whereNotNull('email_tombstone')
            ->where('email_tombstone', User::emailTombstoneFor($email))
            ->first();
    }

    /**
     * Issue a recovery link, rate limited per address.
     */
    public function requestRecovery(#[SensitiveParameter] string $email): RecoveryAttempt
    {
        $key = 'account-recovery:'.hash('sha256', self::normalise($email));

        if (RateLimiter::tooManyAttempts($key, config()->integer('recovery.max_attempts', 5))) {
            return new RecoveryAttempt(AccountRecoveryOutcome::RateLimited, RateLimiter::availableIn($key));
        }

        $user = $this->findArchivedAccount($email);

        if (! $user instanceof User) {
            return new RecoveryAttempt(AccountRecoveryOutcome::NoMatch);
        }

        // Spent only when a link is actually sent. This bucket exists to stop someone spamming one address, and
        // charging it for misses would let an attacker lock a victim out of the only way back into their account
        RateLimiter::hit($key, config()->integer('recovery.decay_seconds', 900));

        $token = Str::random(config()->integer('recovery.token_length', 64));

        AccountRecovery::query()->create([
            'user_id' => $user->id,
            'email' => self::normalise($email),
            'token' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(config()->integer('recovery.expiry_minutes', 60)),
        ]);

        // The accounts stored address is the anonymized placeholder, which does not resolve, so the notification is
        // routed to the address the claimant just supplied rather than through the user model
        Notification::route('mail', self::normalise($email))
            ->notify(new AccountRecoveryNotification($token));

        return new RecoveryAttempt(AccountRecoveryOutcome::LinkSent);
    }

    /**
     * Redeem a recovery link, setting the password the claimant chose.
     */
    public function completeRecovery(string $token, #[SensitiveParameter] string $password): RecoveryAttempt
    {
        return DB::transaction(function () use ($token, $password): RecoveryAttempt {
            $recovery = AccountRecovery::query()
                ->where('token', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if (! $recovery instanceof AccountRecovery) {
                return new RecoveryAttempt(AccountRecoveryOutcome::InvalidLink);
            }

            if ($recovery->consumed_at !== null) {
                return new RecoveryAttempt(AccountRecoveryOutcome::AlreadyUsed);
            }

            if ($recovery->expires_at->isPast()) {
                return new RecoveryAttempt(AccountRecoveryOutcome::ExpiredLink);
            }

            $user = $recovery->user;

            try {
                $this->applyRecovery($user, $recovery->email, $password);
            } catch (EmailAlreadyRegisteredException) {
                return new RecoveryAttempt(AccountRecoveryOutcome::EmailTaken);
            } catch (AccountNotArchivedException) {
                $recovery->forceFill(['consumed_at' => now()])->save();

                return new RecoveryAttempt(AccountRecoveryOutcome::AlreadyUsed);
            }

            return new RecoveryAttempt(AccountRecoveryOutcome::Recovered, user: $user);
        });
    }

    /**
     * Hand the account back: adopt the proven address, clear the tombstone, and burn every outstanding link
     *
     * @throws EmailAlreadyRegisteredException when a live account already holds the address
     * @throws AccountNotArchivedException when the account was already recovered by another path
     */
    public function applyRecovery(User $user, #[SensitiveParameter] string $email, #[SensitiveParameter] ?string $password = null): void
    {
        $email = self::normalise($email);

        throw_if(
            User::query()->where('email', $email)->whereKeyNot($user->getKey())->exists(),
            EmailAlreadyRegisteredException::class,
        );

        try {
            DB::transaction(function () use ($user, $email, $password): void {
                $attributes = [
                    'email' => $email,
                    'email_verified_at' => now(),
                    'email_tombstone' => null,
                ];

                if ($password !== null) {
                    $attributes['password'] = Hash::make($password);
                }

                $recovered = User::query()
                    ->whereKey($user->getKey())
                    ->whereNotNull('email_tombstone')
                    ->update($attributes);

                throw_if($recovered === 0, AccountNotArchivedException::class);

                $user->forceFill($attributes)->syncOriginal();

                AccountRecovery::query()
                    ->where('user_id', $user->getKey())
                    ->whereNull('consumed_at')
                    ->update(['consumed_at' => now()]);
            });
        } catch (UniqueConstraintViolationException $uniqueConstraintViolationException) {
            throw new EmailAlreadyRegisteredException(previous: $uniqueConstraintViolationException);
        }

        DB::afterCommit(fn () => Log::info('Archived account recovered', [
            'user_id' => $user->getKey(),
            'method' => $password === null ? 'oauth' : 'email',
        ]));
    }

    /**
     * Apply the same normalisation the tombstones were derived under
     */
    private static function normalise(#[SensitiveParameter] string $email): string
    {
        return mb_strtolower(mb_trim($email));
    }
}
