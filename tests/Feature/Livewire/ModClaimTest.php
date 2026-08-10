<?php

declare(strict_types=1);

use App\Enums\ClaimVerificationMethod;
use App\Enums\ModClaimStatus;
use App\Models\Mod;
use App\Models\ModClaim;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * A user who satisfies every claim precondition: verified email and MFA confirmed
 */
function claimant(): User
{
    return User::factory()->create([
        'two_factor_secret' => encrypt('SECRET'),
        'two_factor_confirmed_at' => now(),
    ]);
}

function unownedMod(string $url = 'https://github.com/owner/repo'): Mod
{
    $mod = Mod::factory()->create(['owner_id' => null, 'disabled' => false]);

    // The factory attaches its own random source links, which would give every mod an allowlisted repository.
    $mod->sourceCodeLinks()->delete();
    $mod->addSourceCodeLink($url);

    return $mod->refresh();
}

describe('claim button visibility', function (): void {
    it('is hidden from guests', function (): void {
        $mod = unownedMod();

        Livewire::test('mod-claim', ['modId' => $mod->id])
            ->assertSet('canClaim', false)
            ->assertSet('missingMfa', false)
            ->assertDontSee('mod-claim-button');
    });

    it('is shown disabled with an MFA prompt to users without MFA', function (): void {
        $mod = unownedMod();

        Livewire::actingAs(User::factory()->create())
            ->test('mod-claim', ['modId' => $mod->id])
            ->assertSet('canClaim', false)
            ->assertSet('missingMfa', true)
            ->assertSeeHtml('data-test="mod-claim-button-mfa-disabled"')
            ->assertSee('To claim a mod you must have MFA authentication enabled');
    });

    it('is shown to an eligible claimant', function (): void {
        $mod = unownedMod();

        Livewire::actingAs(claimant())
            ->test('mod-claim', ['modId' => $mod->id])
            ->assertSet('canClaim', true)
            ->assertSet('missingMfa', false);
    });

    it('is hidden once the mod has an owner', function (): void {
        $mod = unownedMod();
        $mod->update(['owner_id' => User::factory()->create()->id]);

        Livewire::actingAs(claimant())
            ->test('mod-claim', ['modId' => $mod->id])
            ->assertSet('canClaim', false)
            ->assertSet('missingMfa', false);
    });

    it('is hidden for a disabled mod', function (): void {
        $mod = unownedMod();
        $mod->update(['disabled' => true]);

        Livewire::actingAs(claimant())
            ->test('mod-claim', ['modId' => $mod->id])
            ->assertSet('canClaim', false)
            ->assertSet('missingMfa', false);
    });

    it('does not offer the MFA-disabled button to a mod author without MFA', function (): void {
        $mod = unownedMod();
        $author = User::factory()->create();
        $mod->additionalAuthors()->attach($author);

        Livewire::actingAs($author)
            ->test('mod-claim', ['modId' => $mod->id])
            ->assertSet('canClaim', false)
            ->assertSet('missingMfa', false);
    });
});

describe('initiating', function (): void {
    it('creates a pending claim with a token', function (): void {
        $mod = unownedMod();
        $user = claimant();

        Livewire::actingAs($user)
            ->test('mod-claim', ['modId' => $mod->id])
            ->call('initiate')
            ->assertSet('showModal', true);

        $claim = ModClaim::query()->where('mod_id', $mod->id)->where('user_id', $user->id)->first();

        expect($claim)->not->toBeNull()
            ->and($claim->status)->toBe(ModClaimStatus::Pending)
            ->and(mb_strlen($claim->token))->toBe(32)
            ->and($claim->expires_at)->not->toBeNull();
    });

    it('refuses a user who cannot claim', function (): void {
        $mod = unownedMod();

        Livewire::actingAs(User::factory()->create())
            ->test('mod-claim', ['modId' => $mod->id])
            ->call('initiate')
            ->assertForbidden();
    });
});

describe('verifying', function (): void {
    it('assigns ownership when the token is in the repository', function (): void {
        $mod = unownedMod();
        $user = claimant();
        $claim = ModClaim::factory()->create(['mod_id' => $mod->id, 'user_id' => $user->id]);

        Http::fake(['raw.githubusercontent.com/*' => Http::response($claim->token."\n", 200)]);

        Livewire::actingAs($user)->test('mod-claim', ['modId' => $mod->id])->call('verify');

        expect($mod->refresh()->owner_id)->toBe($user->id)
            ->and($claim->refresh()->status)->toBe(ModClaimStatus::Verified)
            ->and($claim->verified_via)->toBe(ClaimVerificationMethod::GitHub);
    });

    it('fetches only the URL derived from the mod\'s stored source link', function (): void {
        $mod = unownedMod('https://github.com/real-owner/real-repo');
        $user = claimant();
        $claim = ModClaim::factory()->create(['mod_id' => $mod->id, 'user_id' => $user->id]);

        Http::fake(['raw.githubusercontent.com/*' => Http::response($claim->token, 200)]);

        Livewire::actingAs($user)->test('mod-claim', ['modId' => $mod->id])->call('verify');

        Http::assertSent(fn ($request): bool => str_starts_with(
            $request->url(),
            'https://raw.githubusercontent.com/real-owner/real-repo/',
        ));
    });

    it('leaves the mod unowned when the token does not match', function (): void {
        $mod = unownedMod();
        $user = claimant();
        $claim = ModClaim::factory()->create(['mod_id' => $mod->id, 'user_id' => $user->id]);

        Http::fake(['raw.githubusercontent.com/*' => Http::response('some other content', 200)]);

        Livewire::actingAs($user)->test('mod-claim', ['modId' => $mod->id])->call('verify');

        expect($mod->refresh()->owner_id)->toBeNull()
            ->and($claim->refresh()->status)->toBe(ModClaimStatus::Pending);
    });

    it('makes no request when the source host is not allowlisted', function (): void {
        $mod = unownedMod('https://git.selfhosted-example.net/someone/mod');
        $user = claimant();
        ModClaim::factory()->create(['mod_id' => $mod->id, 'user_id' => $user->id]);

        Http::fake();

        Livewire::actingAs($user)->test('mod-claim', ['modId' => $mod->id])->call('verify');

        Http::assertNothingSent();
        expect($mod->refresh()->owner_id)->toBeNull();
    });
});

describe('mods with several source repositories', function (): void {
    it('accepts the token in any listed repository, recording which one proved it', function (): void {
        $mod = unownedMod('https://github.com/first/repo');
        $mod->addSourceCodeLink('https://gitlab.com/second/repo');
        $user = claimant();
        $claim = ModClaim::factory()->create(['mod_id' => $mod->id, 'user_id' => $user->id]);

        // Only the second repository carries the token.
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response('nothing here', 404),
            'gitlab.com/*' => Http::response($claim->token, 200),
        ]);

        Livewire::actingAs($user)->test('mod-claim', ['modId' => $mod->id])->call('verify');

        expect($mod->refresh()->owner_id)->toBe($user->id)
            ->and($claim->refresh()->verified_via)->toBe(ClaimVerificationMethod::GitLab)
            ->and($claim->verified_url)->toContain('gitlab.com/second/repo');
    });

    it('skips links on hosts that cannot be checked but still tries the rest', function (): void {
        $mod = unownedMod('https://git.selfhosted-example.net/someone/mod');
        $mod->addSourceCodeLink('https://github.com/real/repo');
        $user = claimant();
        $claim = ModClaim::factory()->create(['mod_id' => $mod->id, 'user_id' => $user->id]);

        Http::fake(['raw.githubusercontent.com/*' => Http::response($claim->token, 200)]);

        Livewire::actingAs($user)->test('mod-claim', ['modId' => $mod->id])->call('verify');

        expect($mod->refresh()->owner_id)->toBe($user->id);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'selfhosted-example.net'));
    });

    it('stops at the first repository that matches', function (): void {
        $mod = unownedMod('https://github.com/aaa/repo');
        $mod->addSourceCodeLink('https://github.com/zzz/repo');
        $user = claimant();
        $claim = ModClaim::factory()->create(['mod_id' => $mod->id, 'user_id' => $user->id]);

        Http::fake(['raw.githubusercontent.com/*' => Http::response($claim->token, 200)]);

        Livewire::actingAs($user)->test('mod-claim', ['modId' => $mod->id])->call('verify');

        // The first candidate matched, so no further branch or repository was requested.
        Http::assertSentCount(1);
    });

    it('caps how many repositories are tried', function (): void {
        $mod = unownedMod('https://github.com/one/repo');
        foreach (['two', 'three', 'four', 'five', 'six', 'seven'] as $name) {
            $mod->addSourceCodeLink("https://github.com/{$name}/repo");
        }
        $user = claimant();
        ModClaim::factory()->create(['mod_id' => $mod->id, 'user_id' => $user->id]);

        Http::fake(['raw.githubusercontent.com/*' => Http::response('no token', 404)]);

        Livewire::actingAs($user)->test('mod-claim', ['modId' => $mod->id])->call('verify');

        // claim.max_links repositories, each tried on both configured branches.
        Http::assertSentCount(config()->integer('claim.max_links') * 2);
    });
});

describe('escalating', function (): void {
    it('marks the claim for moderator review', function (): void {
        $mod = unownedMod();
        $user = claimant();
        $claim = ModClaim::factory()->create(['mod_id' => $mod->id, 'user_id' => $user->id]);

        Livewire::actingAs($user)->test('mod-claim', ['modId' => $mod->id])->call('escalate');

        expect($claim->refresh()->escalated_at)->not->toBeNull()
            ->and($claim->status)->toBe(ModClaimStatus::Pending);
    });
});
