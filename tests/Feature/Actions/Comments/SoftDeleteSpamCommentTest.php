<?php

declare(strict_types=1);

use App\Actions\Comments\SoftDeleteSpamComment;
use App\Models\Comment;
use App\Models\Mod;
use App\Models\User;
use Illuminate\Support\Facades\Config;

beforeEach(function (): void {
    Config::set('akismet.enabled', false);

    $this->action = resolve(SoftDeleteSpamComment::class);
    $this->mod = Mod::factory()->create();
    $this->comment = Comment::factory()->create([
        'commentable_type' => Mod::class,
        'commentable_id' => $this->mod->id,
        'user_id' => User::factory()->create()->id,
    ]);
});

describe('delete actor', function (): void {
    it('records the acting moderator as the delete actor', function (): void {
        $moderator = User::factory()->moderator()->create();
        $this->actingAs($moderator);

        $this->action->execute($this->comment);

        $this->comment->refresh();
        expect($this->comment->deleted_at)->not->toBeNull()
            ->and($this->comment->deleted_by)->toBe($moderator->id);
    });

    it('leaves the actor null when the pipeline runs without an authenticated user', function (): void {
        $this->action->execute($this->comment);

        $this->comment->refresh();
        expect($this->comment->deleted_at)->not->toBeNull()
            ->and($this->comment->deleted_by)->toBeNull();
    });
});
