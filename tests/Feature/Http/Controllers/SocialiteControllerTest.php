<?php

declare(strict_types=1);

use App\Jobs\DownloadUserAvatar;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Laravel\Fortify\Features;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;

/**
 * Mock the Discord payload the callback will see. A null $verified omits the flag entirely, as a change in Discord's
 * response shape would.
 */
function mockDiscordUser(string $email, ?bool $verified = true, string $providerId = 'provider-user-id', ?string $avatar = null): void
{
    $mock = Mockery::mock(SocialiteUser::class);
    $mock->shouldReceive('getId')->andReturn($providerId);
    $mock->shouldReceive('getEmail')->andReturn($email);
    $mock->shouldReceive('getName')->andReturn('Discord User');
    $mock->shouldReceive('getNickname')->andReturn(null);
    $mock->shouldReceive('getAvatar')->andReturn($avatar);
    $mock->token = 'access-token';
    $mock->refreshToken = 'refresh-token';
    $mock->user = ['mfa_enabled' => false] + ($verified === null ? [] : ['verified' => $verified]);

    Socialite::shouldReceive('driver->user')->andReturn($mock);
}

describe('OAuth callback authentication', function (): void {
    it('creates a new user and attaches the OAuth provider when logging in via OAuth', function (): void {
        Queue::fake([DownloadUserAvatar::class]);

        // Mock the Socialite user.
        $mock = Mockery::mock(SocialiteUser::class);
        $mock->shouldReceive('getId')->andReturn('provider-user-id');
        $mock->shouldReceive('getEmail')->andReturn('newuser@example.com');
        $mock->shouldReceive('getName')->andReturn('New User');
        $mock->shouldReceive('getNickname')->andReturn(null);
        $mock->shouldReceive('getAvatar')->andReturn('avatar-url');
        $mock->token = 'access-token';
        $mock->refreshToken = 'refresh-token';
        $mock->user = ['mfa_enabled' => false, 'verified' => true];

        // Mock Socialite facade.
        Socialite::shouldReceive('driver->user')->andReturn($mock);

        // Hit the callback route.
        $response = $this->get('/login/discord/callback');

        // Assert that the user was created.
        $user = User::query()->where('email', 'newuser@example.com')->first();
        expect($user)->not->toBeNull()
            ->and($user->name)->toBe('New User');

        // Assert that the OAuth provider was attached.
        $oAuthConnection = $user->oAuthConnections()->whereProvider('discord')->first();
        expect($oAuthConnection)->not->toBeNull()
            ->and($oAuthConnection->provider_id)->toBe('provider-user-id');

        // Assert the avatar download was queued for the new user.
        Queue::assertPushed(fn (DownloadUserAvatar $job): bool => $job->user->is($user)
            && $job->avatarUrl === 'avatar-url');

        // Assert the user is authenticated.
        $this->assertAuthenticatedAs($user);

        // Assert redirect to dashboard.
        $response->assertRedirect(route('dashboard'));
    })->skip(fn (): bool => ! Features::enabled(Features::registration()), 'Registration support is not enabled.');

    it('stores the provider address lowercased when creating a user', function (): void {
        Queue::fake([DownloadUserAvatar::class]);

        // Providers echo back whatever casing the user typed. Postgres matches users.email case-sensitively, so a row
        // stored with the provider's casing would be invisible to every path that lowercases first, recovery included.
        mockDiscordUser('NewUser@Example.COM');

        $this->get('/login/discord/callback')->assertRedirect(route('dashboard'));

        expect(User::query()->where('email', 'newuser@example.com')->exists())->toBeTrue();
    })->skip(fn (): bool => ! Features::enabled(Features::registration()), 'Registration support is not enabled.');

    it('does not queue an avatar download when the provider has no avatar', function (): void {
        Queue::fake([DownloadUserAvatar::class]);

        // Mock the Socialite user.
        $mock = Mockery::mock(SocialiteUser::class);
        $mock->shouldReceive('getId')->andReturn('provider-user-id');
        $mock->shouldReceive('getEmail')->andReturn('newuser@example.com');
        $mock->shouldReceive('getName')->andReturn('New User');
        $mock->shouldReceive('getNickname')->andReturn(null);
        $mock->shouldReceive('getAvatar')->andReturn(null);
        $mock->token = 'access-token';
        $mock->refreshToken = 'refresh-token';
        $mock->user = ['mfa_enabled' => false, 'verified' => true];

        // Mock Socialite facade.
        Socialite::shouldReceive('driver->user')->andReturn($mock);

        $this->get('/login/discord/callback');

        Queue::assertNotPushed(DownloadUserAvatar::class);
    })->skip(fn (): bool => ! Features::enabled(Features::registration()), 'Registration support is not enabled.');

    it('attaches a new OAuth provider to an existing user when logging in via OAuth', function (): void {
        Queue::fake([DownloadUserAvatar::class]);

        // Create an existing user.
        $user = User::factory()->create([
            'email' => 'existinguser@example.com',
            'name' => 'Existing User',
            'password' => Hash::make('password123'),
        ]);

        // Mock the Socialite user.
        $mock = Mockery::mock(SocialiteUser::class);
        $mock->shouldReceive('getId')->andReturn('new-provider-user-id');
        $mock->shouldReceive('getEmail')->andReturn('existinguser@example.com');
        $mock->shouldReceive('getName')->andReturn('Existing User Updated');
        $mock->shouldReceive('getNickname')->andReturn(null);
        $mock->shouldReceive('getAvatar')->andReturn('new-avatar-url');
        $mock->token = 'new-access-token';
        $mock->refreshToken = 'new-refresh-token';
        $mock->user = ['mfa_enabled' => false, 'verified' => true];

        // Mock Socialite facade.
        Socialite::shouldReceive('driver->user')->andReturn($mock);

        // Hit the callback route.
        $response = $this->get('/login/discord/callback');

        // Refresh user data.
        $user->refresh();

        // Assert that the username was not updated.
        expect($user->name)->toBe('Existing User')
            ->and($user->name)->not->toBe('Existing User Updated');

        // Assert that the new OAuth provider was attached.
        $oauthConnection = $user->oAuthConnections()->whereProvider('discord')->first();
        expect($oauthConnection)->not->toBeNull()
            ->and($oauthConnection->provider_id)->toBe('new-provider-user-id');

        // Assert the user is authenticated.
        $this->assertAuthenticatedAs($user);

        // Assert redirect to dashboard.
        $response->assertRedirect(route('dashboard'));
    });

    it('does not queue an avatar download when an existing connection logs in again', function (): void {
        Queue::fake([DownloadUserAvatar::class]);

        // Create an existing user with an existing OAuth connection.
        $user = User::factory()->create(['email' => 'returning@example.com']);
        $user->oAuthConnections()->create([
            'provider' => 'discord',
            'provider_id' => 'returning-provider-id',
            'token' => 'old-token',
            'refresh_token' => 'old-refresh-token',
            'nickname' => '',
            'name' => 'Returning User',
            'email' => 'returning@example.com',
            'avatar' => 'old-avatar-url',
        ]);

        // Mock the Socialite user.
        $mock = Mockery::mock(SocialiteUser::class);
        $mock->shouldReceive('getId')->andReturn('returning-provider-id');
        $mock->shouldReceive('getEmail')->andReturn('returning@example.com');
        $mock->shouldReceive('getName')->andReturn('Returning User');
        $mock->shouldReceive('getNickname')->andReturn(null);
        $mock->shouldReceive('getAvatar')->andReturn('fresh-avatar-url');
        $mock->token = 'fresh-token';
        $mock->refreshToken = 'fresh-refresh-token';
        $mock->user = ['mfa_enabled' => false, 'verified' => true];

        // Mock Socialite facade.
        Socialite::shouldReceive('driver->user')->andReturn($mock);

        $this->get('/login/discord/callback');

        Queue::assertNotPushed(DownloadUserAvatar::class);
    });
});

describe('OAuth callback archived account recovery', function (): void {
    it('hands back the archived account whose tombstone the verified discord email reproduces', function (): void {
        Queue::fake([DownloadUserAvatar::class]);

        $user = User::factory()->create([
            'name' => 'Archived User',
            'email' => '4242@unclaimed.account',
            'email_tombstone' => User::emailTombstoneFor('archived@example.com'),
            'email_verified_at' => null,
            'password' => null,
        ]);

        mockDiscordUser('archived@example.com', avatar: 'avatar-url');

        $response = $this->get('/login/discord/callback');

        $user->refresh();

        expect($user->email)->toBe('archived@example.com')
            ->and($user->email_tombstone)->toBeNull()
            ->and($user->email_verified_at)->not->toBeNull()
            ->and($user->name)->toBe('Archived User')
            ->and(User::query()->count())->toBe(1);

        $oAuthConnection = $user->oAuthConnections()->whereProvider('discord')->first();
        expect($oAuthConnection)->not->toBeNull()
            ->and($oAuthConnection->provider_id)->toBe('provider-user-id');

        Queue::assertPushed(fn (DownloadUserAvatar $job): bool => $job->user->is($user)
            && $job->avatarUrl === 'avatar-url');

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dashboard'));
    });

    it('refuses an unverified discord email that matches a live account', function (): void {
        $user = User::factory()->create(['email' => 'member@example.com']);

        mockDiscordUser('member@example.com', verified: false);

        $response = $this->get('/login/discord/callback');

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors();
        expect($user->oAuthConnections()->exists())->toBeFalse();
        $this->assertGuest();
    });

    it('refuses an unverified discord email that matches a tombstone', function (): void {
        $user = User::factory()->create([
            'email' => '4242@unclaimed.account',
            'email_tombstone' => User::emailTombstoneFor('archived@example.com'),
            'email_verified_at' => null,
        ]);

        mockDiscordUser('archived@example.com', verified: false);

        $response = $this->get('/login/discord/callback');

        $user->refresh();

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors();
        expect($user->email)->toBe('4242@unclaimed.account')
            ->and($user->email_tombstone)->not->toBeNull()
            ->and($user->oAuthConnections()->exists())->toBeFalse();
        $this->assertGuest();
    });

    it('leaves unrelated archived accounts alone when the verified email matches nothing', function (): void {
        config()->set('fortify.features', array_values(array_unique(
            [...config()->array('fortify.features'), Features::registration()],
        )));

        $archived = User::factory()->create([
            'email' => '4242@unclaimed.account',
            'email_tombstone' => User::emailTombstoneFor('someone-else@example.com'),
        ]);

        mockDiscordUser('stranger@example.com');

        $this->get('/login/discord/callback');

        $archived->refresh();

        $newUser = User::query()->where('email', 'stranger@example.com')->first();
        expect($newUser)->not->toBeNull()
            ->and($newUser->is($archived))->toBeFalse()
            ->and($archived->email)->toBe('4242@unclaimed.account')
            ->and($archived->email_tombstone)->not->toBeNull();

        $this->assertAuthenticatedAs($newUser);
    });

    it('refuses to adopt an archived account by its anonymised placeholder address', function (): void {
        $archived = User::factory()->create([
            'name' => 'Archived User',
            'email' => '4242@unclaimed.account',
            'email_tombstone' => User::emailTombstoneFor('archived@example.com'),
            'email_verified_at' => null,
            'password' => null,
        ]);

        // Nobody controls unclaimed.account, so Discord vouching for a mailbox there proves nothing.
        mockDiscordUser('4242@unclaimed.account');

        $response = $this->get('/login/discord/callback');

        $archived->refresh();

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors();
        expect($archived->email_tombstone)->not->toBeNull()
            ->and($archived->oAuthConnections()->exists())->toBeFalse();
        $this->assertGuest();
    });

    it('refuses recovery when a live account already holds the proven address', function (): void {
        $live = User::factory()->create(['email' => 'archived@example.com']);
        $archived = User::factory()->create([
            'email' => '4242@unclaimed.account',
            'email_tombstone' => User::emailTombstoneFor('archived@example.com'),
            'email_verified_at' => null,
        ]);

        mockDiscordUser('archived@example.com');

        $response = $this->get('/login/discord/callback');

        $archived->refresh();

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors();
        expect($archived->email)->toBe('4242@unclaimed.account')
            ->and($archived->email_tombstone)->not->toBeNull()
            ->and($archived->oAuthConnections()->exists())->toBeFalse()
            ->and($live->oAuthConnections()->exists())->toBeFalse();
        $this->assertGuest();
    });

    it('does not lock out an already-linked connection when the provider omits the verified flag', function (): void {
        $user = User::factory()->create(['email' => 'returning@example.com']);
        $user->oAuthConnections()->create([
            'provider' => 'discord',
            'provider_id' => 'returning-provider-id',
            'token' => 'old-token',
            'refresh_token' => 'old-refresh-token',
            'nickname' => '',
            'name' => 'Returning User',
            'email' => 'returning@example.com',
            'avatar' => '',
        ]);

        mockDiscordUser('returning@example.com', verified: null, providerId: 'returning-provider-id');

        $response = $this->get('/login/discord/callback');

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dashboard'));
    });
});

describe('OAuth callback with registration disabled', function (): void {
    beforeEach(function (): void {
        config()->set('fortify.features', array_values(array_diff(
            config('fortify.features'),
            [Features::registration()],
        )));
    });

    it('blocks a brand-new discord user from creating an account', function (): void {
        mockDiscordUser('stranger@example.com');

        $response = $this->get('/login/discord/callback');

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors();
        expect(User::query()->where('email', 'stranger@example.com')->exists())->toBeFalse();
        $this->assertGuest();
    });

    it('still logs in an existing user linking discord for the first time', function (): void {
        $user = User::factory()->create(['email' => 'member@example.com']);
        mockDiscordUser('member@example.com');

        $response = $this->get('/login/discord/callback');

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dashboard'));
        expect($user->oAuthConnections()->whereProvider('discord')->exists())->toBeTrue();
    });

    it('still logs in a user with an existing discord connection', function (): void {
        $user = User::factory()->create(['email' => 'returning@example.com']);
        $user->oAuthConnections()->create([
            'provider' => 'discord',
            'provider_id' => 'returning-provider-id',
            'token' => 'old-token',
            'refresh_token' => 'old-refresh-token',
            'nickname' => '',
            'name' => 'Returning User',
            'email' => 'returning@example.com',
            'avatar' => '',
        ]);
        mockDiscordUser('returning@example.com', providerId: 'returning-provider-id');

        $response = $this->get('/login/discord/callback');

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dashboard'));
    });

    it('still hands back an archived account, since recovery is not a registration', function (): void {
        $user = User::factory()->create([
            'email' => '4242@unclaimed.account',
            'email_tombstone' => User::emailTombstoneFor('archived@example.com'),
            'email_verified_at' => null,
            'password' => null,
        ]);

        mockDiscordUser('archived@example.com');

        $response = $this->get('/login/discord/callback');

        $user->refresh();

        expect($user->email)->toBe('archived@example.com')
            ->and($user->email_tombstone)->toBeNull()
            ->and($user->oAuthConnections()->whereProvider('discord')->exists())->toBeTrue();

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dashboard'));
    });
});
