<?php

declare(strict_types=1);

use App\Models\Emoji;
use App\Models\Mod;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Database\QueryException;

describe('Reaction storage', function (): void {
    it('records a reaction against a mod', function (): void {
        $mod = Mod::factory()->create();
        $user = User::factory()->create();
        $heart = Emoji::query()->where('shortcode', 'heart')->sole();

        $mod->reactions()->create(['user_id' => $user->id, 'emoji_id' => $heart->id]);

        $reaction = Reaction::query()->sole();

        expect($reaction->reactable_type)->toBe(Mod::class)
            ->and($reaction->reactable_id)->toBe($mod->id)
            ->and($reaction->emoji->shortcode)->toBe('heart')
            ->and($reaction->user->id)->toBe($user->id);
    });

    it('holds at most one reaction per user per mod, whatever the emoji', function (): void {
        $mod = Mod::factory()->create();
        $user = User::factory()->create();

        $mod->reactions()->create([
            'user_id' => $user->id,
            'emoji_id' => Emoji::query()->where('shortcode', 'heart')->sole()->id,
        ]);

        expect(fn () => $mod->reactions()->create([
            'user_id' => $user->id,
            'emoji_id' => Emoji::query()->where('shortcode', 'fire')->sole()->id,
        ]))->toThrow(QueryException::class);
    });

    it('rejects the same user reacting twice with the same emoji', function (): void {
        $mod = Mod::factory()->create();
        $user = User::factory()->create();
        $heart = Emoji::query()->where('shortcode', 'heart')->sole();

        $mod->reactions()->create(['user_id' => $user->id, 'emoji_id' => $heart->id]);

        expect(fn () => $mod->reactions()->create(['user_id' => $user->id, 'emoji_id' => $heart->id]))
            ->toThrow(QueryException::class);
    });

    it('lets different users react to the same mod with different emoji', function (): void {
        $mod = Mod::factory()->create();

        foreach (['heart', 'fire', 'tada'] as $shortcode) {
            $mod->reactions()->create([
                'user_id' => User::factory()->create()->id,
                'emoji_id' => Emoji::query()->where('shortcode', $shortcode)->sole()->id,
            ]);
        }

        expect($mod->reactions()->count())->toBe(3);
    });

    it('refuses to delete an emoji that is still in use', function (): void {
        $mod = Mod::factory()->create();
        $heart = Emoji::query()->where('shortcode', 'heart')->sole();
        $mod->reactions()->create(['user_id' => User::factory()->create()->id, 'emoji_id' => $heart->id]);

        expect(fn () => $heart->delete())->toThrow(QueryException::class);
    });

    it('removes reactions when the user is deleted', function (): void {
        $mod = Mod::factory()->create();
        $user = User::factory()->create();
        $mod->reactions()->create([
            'user_id' => $user->id,
            'emoji_id' => Emoji::query()->where('shortcode', 'heart')->sole()->id,
        ]);

        $user->delete();

        expect(Reaction::query()->count())->toBe(0);
    });
});

describe('Mod reactability', function (): void {
    it('accepts reactions on a published, enabled mod', function (): void {
        expect(Mod::factory()->create(['disabled' => false, 'published_at' => now()->subHour()])->canReceiveReactions())
            ->toBeTrue();
    });

    it('refuses reactions on a disabled mod', function (): void {
        expect(Mod::factory()->create(['disabled' => true, 'published_at' => now()->subHour()])->canReceiveReactions())
            ->toBeFalse();
    });

    it('refuses reactions on an unpublished mod', function (): void {
        expect(Mod::factory()->create(['disabled' => false, 'published_at' => null])->canReceiveReactions())
            ->toBeFalse();
    });

    it('refuses reactions on a mod published in the future', function (): void {
        expect(Mod::factory()->create(['disabled' => false, 'published_at' => now()->addDay()])->canReceiveReactions())
            ->toBeFalse();
    });
});
