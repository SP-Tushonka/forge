<?php

declare(strict_types=1);

use App\Models\Emoji;
use App\Models\Mod;
use App\Models\Reaction;
use App\Models\User;
use App\Models\UserRole;
use App\Services\ReactionSummaryService;
use App\Traits\Livewire\HandlesReactions;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->withoutDefer();

    $this->mod = Mod::factory()->create(['disabled' => false, 'published_at' => now()->subHour()]);
    $this->heart = Emoji::query()->where('shortcode', 'heart')->sole();
    $this->user = User::factory()->create();
});

/**
 * A minimal host for the trait, so the shared logic is tested before any real page depends on it.
 */
$host = new class extends Component
{
    use HandlesReactions;

    public function render(): string
    {
        return '<div></div>';
    }
};

describe('Mod reactions', function () use ($host): void {
    it('adds a reaction', function () use ($host): void {
        Livewire::actingAs($this->user)
            ->test($host::class)
            ->call('toggleReaction', 'mod', $this->mod->id, $this->heart->id)
            ->assertSuccessful();

        expect(Reaction::query()->where('reactable_type', Mod::class)->where('reactable_id', $this->mod->id)->count())
            ->toBe(1);
    });

    it('removes the reaction on a second call', function () use ($host): void {
        $component = Livewire::actingAs($this->user)->test($host::class);

        $component->call('toggleReaction', 'mod', $this->mod->id, $this->heart->id);
        $component->call('toggleReaction', 'mod', $this->mod->id, $this->heart->id);

        expect(Reaction::query()->count())->toBe(0);
    });

    it('replaces the existing reaction when a different emoji is picked', function () use ($host): void {
        $fire = Emoji::query()->where('shortcode', 'fire')->sole();
        $component = Livewire::actingAs($this->user)->test($host::class);

        $component->call('toggleReaction', 'mod', $this->mod->id, $this->heart->id);
        $component->call('toggleReaction', 'mod', $this->mod->id, $fire->id);

        $reaction = Reaction::query()->sole();

        expect($reaction->emoji_id)->toBe($fire->id)
            ->and($reaction->user_id)->toBe($this->user->id);
    });

    it('removes the reaction when the already-picked emoji is clicked again', function () use ($host): void {
        $fire = Emoji::query()->where('shortcode', 'fire')->sole();
        $component = Livewire::actingAs($this->user)->test($host::class);

        $component->call('toggleReaction', 'mod', $this->mod->id, $this->heart->id);
        $component->call('toggleReaction', 'mod', $this->mod->id, $fire->id);
        $component->call('toggleReaction', 'mod', $this->mod->id, $fire->id);

        expect(Reaction::query()->count())->toBe(0);
    });

    it('keeps one reaction per user even under rapid switching', function () use ($host): void {
        $component = Livewire::actingAs($this->user)->test($host::class);

        foreach (['heart', 'fire', 'tada', 'joy', 'thumbsup'] as $shortcode) {
            $component->call(
                'toggleReaction',
                'mod',
                $this->mod->id,
                Emoji::query()->where('shortcode', $shortcode)->sole()->id,
            );
        }

        expect(Reaction::query()->where('user_id', $this->user->id)->count())->toBe(1);
    });

    it('lets two users hold different reactions on the same mod', function () use ($host): void {
        $other = User::factory()->create();
        $fire = Emoji::query()->where('shortcode', 'fire')->sole();

        Livewire::actingAs($this->user)->test($host::class)
            ->call('toggleReaction', 'mod', $this->mod->id, $this->heart->id);
        Livewire::actingAs($other)->test($host::class)
            ->call('toggleReaction', 'mod', $this->mod->id, $fire->id);

        expect(Reaction::query()->count())->toBe(2);
    });

    it('rejects an unknown reactable type', function () use ($host): void {
        Livewire::actingAs($this->user)
            ->test($host::class)
            ->call('toggleReaction', 'user', $this->user->id, $this->heart->id)
            ->assertStatus(404);

        expect(Reaction::query()->count())->toBe(0);
    });

    it('rejects a disabled emoji even when called directly', function () use ($host): void {
        $retired = Emoji::factory()->disabled()->create();

        Livewire::actingAs($this->user)
            ->test($host::class)
            ->call('toggleReaction', 'mod', $this->mod->id, $retired->id);

        expect(Reaction::query()->count())->toBe(0);
    });

    it('refuses a reaction on a disabled mod', function () use ($host): void {
        $this->mod->update(['disabled' => true]);

        Livewire::actingAs($this->user)
            ->test($host::class)
            ->call('toggleReaction', 'mod', $this->mod->id, $this->heart->id);

        expect(Reaction::query()->count())->toBe(0);
    });

    it('refuses a reaction from a guest', function () use ($host): void {
        Livewire::test($host::class)
            ->call('toggleReaction', 'mod', $this->mod->id, $this->heart->id);

        expect(Reaction::query()->count())->toBe(0);
    });

    it('refuses to let an owner react to their own mod', function () use ($host): void {
        $owner = User::factory()->create();
        $this->mod->owner()->associate($owner)->save();

        Livewire::actingAs($owner)
            ->test($host::class)
            ->call('toggleReaction', 'mod', $this->mod->id, $this->heart->id);

        expect(Reaction::query()->count())->toBe(0);
    });

    it('refuses a reaction from a user with an unverified email', function () use ($host): void {
        $unverified = User::factory()->unverified()->create();

        Livewire::actingAs($unverified)
            ->test($host::class)
            ->call('toggleReaction', 'mod', $this->mod->id, $this->heart->id);

        expect(Reaction::query()->count())->toBe(0);
    });

    it('exempts staff from the rate limit', function () use ($host): void {
        $role = UserRole::query()->firstOrCreate(
            ['name' => 'Moderator'],
            UserRole::factory()->moderator()->make()->toArray(),
        );
        $staff = User::factory()->create(['user_role_id' => $role->id]);
        $max = config()->integer('reactions.rate_limiting.max_attempts');

        for ($i = 0; $i < $max + 5; $i++) {
            RateLimiter::hit('reactions:'.$staff->id, 60);
        }

        Livewire::actingAs($staff)
            ->test($host::class)
            ->call('toggleReaction', 'mod', $this->mod->id, $this->heart->id);

        expect(Reaction::query()->count())->toBe(1);
    });

    it('stops accepting reactions once the rate limit is spent', function () use ($host): void {
        $max = config()->integer('reactions.rate_limiting.max_attempts');

        for ($i = 0; $i < $max; $i++) {
            RateLimiter::hit('reactions:'.$this->user->id, 60);
        }

        Livewire::actingAs($this->user)
            ->test($host::class)
            ->call('toggleReaction', 'mod', $this->mod->id, $this->heart->id);

        expect(Reaction::query()->count())->toBe(0);
    });

    it('stops rendering a chip once its emoji is disabled', function (): void {
        $fire = Emoji::query()->where('shortcode', 'fire')->sole();
        $this->mod->reactions()->create(['user_id' => $this->user->id, 'emoji_id' => $fire->id]);

        $bar = fn (): string => Blade::render(
            '<x-reaction-bar reactable-type="mod" :reactable-id="$id" :counts="$counts" :whitelist="$whitelist" />',
            [
                'id' => $this->mod->id,
                'counts' => [$fire->id => 1],
                'whitelist' => resolve(ReactionSummaryService::class)->whitelist(),
            ],
        );

        expect($bar())->toContain('reaction-chip-'.$this->mod->id.'-fire');

        $fire->update(['enabled' => false]);
        resolve(ReactionSummaryService::class)->forgetWhitelist();

        expect($bar())->not->toContain('reaction-chip-'.$this->mod->id.'-fire');
    });
});

describe('Emoji surface restrictions on the write path', function () use ($host): void {
    /**
     * A host that reacts to comments, so both interactive surfaces can be driven through the same trait.
     */
    $commentHost = new class extends Component
    {
        use HandlesReactions;

        public function render(): string
        {
            return '<div></div>';
        }

        protected function reactableClass(): string
        {
            return App\Models\Comment::class;
        }

        protected function reactionSurface(): App\Enums\EmojiSurface
        {
            return App\Enums\EmojiSurface::CommentReactions;
        }
    };

    it('refuses an emoji restricted on mod reactions', function () use ($host): void {
        Emoji::query()->whereKey($this->heart->id)->update(['allow_mod_reactions' => false]);
        resolve(ReactionSummaryService::class)->forgetWhitelist();

        // The picker never offers it, so reaching this at all means a hand-built payload.
        Livewire::actingAs($this->user)
            ->test($host::class)
            ->call('toggleReaction', 'mod', $this->mod->id, $this->heart->id)
            ->assertSuccessful();

        expect(Reaction::query()->count())->toBe(0);
    });

    it('still accepts an emoji restricted only on comment reactions', function () use ($host): void {
        Emoji::query()->whereKey($this->heart->id)->update(['allow_comment_reactions' => false]);
        resolve(ReactionSummaryService::class)->forgetWhitelist();

        Livewire::actingAs($this->user)
            ->test($host::class)
            ->call('toggleReaction', 'mod', $this->mod->id, $this->heart->id);

        expect(Reaction::query()->count())->toBe(1);
    });

    it('refuses an emoji restricted on comment reactions when reacting to a comment', function () use ($commentHost): void {
        $comment = App\Models\Comment::factory()->create(['commentable_type' => Mod::class, 'commentable_id' => $this->mod->id]);

        Emoji::query()->whereKey($this->heart->id)->update(['allow_comment_reactions' => false]);
        resolve(ReactionSummaryService::class)->forgetWhitelist();

        Livewire::actingAs($this->user)
            ->test($commentHost::class)
            ->call('toggleReaction', 'comment', $comment->id, $this->heart->id);

        expect(Reaction::query()->count())->toBe(0);
    });

    it('leaves a mod-description restriction out of the write path entirely', function () use ($host): void {
        // Description text is a separate surface, so restricting it must not stop anyone reacting to the mod.
        Emoji::query()->whereKey($this->heart->id)->update(['allow_mod_description' => false]);
        resolve(ReactionSummaryService::class)->forgetWhitelist();

        Livewire::actingAs($this->user)
            ->test($host::class)
            ->call('toggleReaction', 'mod', $this->mod->id, $this->heart->id);

        expect(Reaction::query()->count())->toBe(1);
    });
});
