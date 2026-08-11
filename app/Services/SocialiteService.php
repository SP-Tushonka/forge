<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\EmailAlreadyRegisteredException;
use App\Exceptions\RegistrationsClosedException;
use App\Jobs\DownloadUserAvatar;
use App\Models\OAuthConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Fortify\Features;
use Laravel\Socialite\Contracts\User as ProviderUser;

final readonly class SocialiteService
{
    public function __construct(private AccountRecoveryService $accountRecoveryService) {}

    /**
     * Find an existing user by OAuth connection or create a new one.
     *
     * @throws RegistrationsClosedException when the user is unknown and registration is disabled
     */
    public function findOrCreateUser(string $provider, ProviderUser $providerUser): ?User
    {
        /** @var \Laravel\Socialite\Two\User $providerUser */
        $email = mb_strtolower(mb_trim($providerUser->getEmail() ?? ''));

        if ($email === '') {
            Log::error('OAuth: Unable to retrieve email from provider', [
                'provider' => $provider,
                'provider_id' => $providerUser->getId(),
                'name' => $providerUser->getName(),
                'nickname' => $providerUser->getNickname(),
            ]);

            return null;
        }

        $oauthConnection = OAuthConnection::whereProvider($provider)
            ->whereProviderId($providerUser->getId())
            ->first();

        $mfaStatus = $this->getMfaStatus($provider, $providerUser);

        if ($oauthConnection !== null) {
            return $this->updateExistingConnection($oauthConnection, $providerUser, $mfaStatus);
        }

        // Everything below binds the provider identity to an address, so the provider must vouch for that address.
        if (! $this->hasVerifiedEmail($provider, $providerUser)) {
            Log::warning('OAuth: Provider reports the email address is unverified', [
                'provider' => $provider,
                'provider_id' => $providerUser->getId(),
                'name' => $providerUser->getName(),
                'nickname' => $providerUser->getNickname(),
            ]);

            return null;
        }

        $archivedUser = $this->accountRecoveryService->findArchivedAccount($email);

        if ($archivedUser instanceof User) {
            return $this->recoverArchivedAccount($archivedUser, $email, $provider, $providerUser, $mfaStatus);
        }

        if (User::query()->where('email', $email)->whereNotNull('email_tombstone')->exists()) {
            Log::warning('OAuth: Refused an address that is an archived account placeholder', [
                'provider' => $provider,
                'provider_id' => $providerUser->getId(),
                'name' => $providerUser->getName(),
                'nickname' => $providerUser->getNickname(),
            ]);

            return null;
        }

        throw_if(! Features::enabled(Features::registration()) && ! User::whereEmail($email)->exists(), RegistrationsClosedException::class);

        return $this->createNewConnection($provider, $email, $providerUser, $mfaStatus);
    }

    /**
     * Update an existing OAuth connection with fresh provider data.
     */
    private function updateExistingConnection(
        OAuthConnection $connection,
        ProviderUser $providerUser,
        ?bool $mfaStatus,
    ): User {
        /** @var \Laravel\Socialite\Two\User $providerUser */
        $connection->update([
            'token' => $providerUser->token,
            'refresh_token' => $providerUser->refreshToken,
            'nickname' => $providerUser->getNickname() ?? '',
            'name' => $providerUser->getName() ?? '',
            'email' => $providerUser->getEmail(),
            'avatar' => $providerUser->getAvatar() ?? '',
            'mfa_enabled' => $mfaStatus,
        ]);

        return $connection->user;
    }

    /**
     * Hand the archived account back to the provider identity that proved the address
     */
    private function recoverArchivedAccount(
        User $user,
        string $email,
        string $provider,
        ProviderUser $providerUser,
        ?bool $mfaStatus,
    ): ?User {
        try {
            return DB::transaction(function () use ($user, $email, $provider, $providerUser, $mfaStatus): User {
                $this->accountRecoveryService->applyRecovery($user, $email);
                $this->attachConnection($user, $provider, $providerUser, $mfaStatus);

                return $user;
            });
        } catch (EmailAlreadyRegisteredException) {
            Log::error('OAuth: Archived account recovery blocked by a live account holding the address', [
                'provider' => $provider,
                'provider_id' => $providerUser->getId(),
                'user_id' => $user->getKey(),
            ]);

            return null;
        }
    }

    /**
     * Create a new user and OAuth connection.
     */
    private function createNewConnection(string $provider, string $email, ProviderUser $providerUser, ?bool $mfaStatus): User
    {
        /** @var \Laravel\Socialite\Two\User $providerUser */
        $username = $this->generateUniqueUsername($providerUser);

        return DB::transaction(function () use ($providerUser, $provider, $email, $username, $mfaStatus): User {
            $user = User::query()->firstOrCreate(['email' => $email], [
                'name' => $username,
                'password' => null,
            ]);

            $this->attachConnection($user, $provider, $providerUser, $mfaStatus);

            return $user;
        });
    }

    /**
     * Link the provider identity to the user and pull down its avatar.
     */
    private function attachConnection(User $user, string $provider, ProviderUser $providerUser, ?bool $mfaStatus): void
    {
        /** @var \Laravel\Socialite\Two\User $providerUser */
        $oAuthConnection = $user->oAuthConnections()->updateOrCreate([
            'provider' => $provider,
            'provider_id' => $providerUser->getId(),
        ], [
            'token' => $providerUser->token,
            'refresh_token' => $providerUser->refreshToken,
            'nickname' => $providerUser->getNickname() ?? '',
            'name' => $providerUser->getName() ?? '',
            'email' => $providerUser->getEmail(),
            'avatar' => $providerUser->getAvatar() ?? '',
            'mfa_enabled' => $mfaStatus,
        ]);

        if ($oAuthConnection->avatar !== '') {
            dispatch(new DownloadUserAvatar($user, $oAuthConnection->avatar))->afterCommit();
        }
    }

    /**
     * Generate a unique username from provider data, appending random characters if needed.
     */
    private function generateUniqueUsername(ProviderUser $providerUser): string
    {
        $username = $providerUser->getName() ?: $providerUser->getNickname();
        $suffix = '';

        while (User::whereName($username.$suffix)->exists()) {
            $suffix = '-'.Str::random(5);
        }

        return $username.$suffix;
    }

    /**
     * Get the MFA status from the provider user based on the provider.
     */
    private function getMfaStatus(string $provider, ProviderUser $providerUser): ?bool
    {
        /** @var \Laravel\Socialite\Two\User $providerUser */
        $userData = (array) $providerUser->user;

        return match ($provider) {
            'discord' => isset($userData['mfa_enabled']) ? (bool) $userData['mfa_enabled'] : null,
            default => null,
        };
    }

    /**
     * Whether the provider itself vouches for the email address it returned
     */
    private function hasVerifiedEmail(string $provider, ProviderUser $providerUser): bool
    {
        /** @var \Laravel\Socialite\Two\User $providerUser */
        $userData = (array) $providerUser->user;

        return match ($provider) {
            'discord' => ($userData['verified'] ?? null) === true,
            default => false,
        };
    }
}
