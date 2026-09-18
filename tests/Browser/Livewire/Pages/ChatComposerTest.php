<?php

declare(strict_types=1);

use App\Models\Conversation;
use App\Models\User;

beforeEach(function (): void {
    $this->me = User::factory()->create();
    $this->conversation = Conversation::factory()->withUsers($this->me, User::factory()->create())->create();

    $this->actingAs($this->me);
});

$input = '@chat-message-input';

describe('Chat composer', function () use ($input): void {
    it('sends on Enter and empties the editor', function () use ($input): void {
        visit(route('chat', ['conversationHash' => $this->conversation->hash_id]))
            ->on()->desktop()
            ->type($input, 'Hello there')
            ->keys($input, ['Enter'])
            // The server empties messageText once the message is stored.
            ->assertValue($input, '')
            ->assertNoJavaScriptErrors();

        expect($this->conversation->messages()->pluck('content')->all())->toBe(['Hello there']);
    });

    it('starts a new line on Shift+Enter instead of sending', function () use ($input): void {
        visit(route('chat', ['conversationHash' => $this->conversation->hash_id]))
            ->on()->desktop()
            ->type($input, 'line one')
            ->keys($input, ['Shift+Enter'])
            ->typeSlowly($input, 'line two', 20)
            ->keys($input, ['Enter'])
            ->assertValue($input, '')
            ->assertNoJavaScriptErrors();

        // One message holding both lines: Shift+Enter did not send the first line on its own.
        expect($this->conversation->messages()->pluck('content')->all())->toBe(["line one\nline two"]);
    });

    it('sends from a list line rather than continuing the list', function () use ($input): void {
        visit(route('chat', ['conversationHash' => $this->conversation->hash_id]))
            ->on()->desktop()
            ->type($input, '- first point')
            ->keys($input, ['Enter'])
            ->assertValue($input, '')
            ->assertNoJavaScriptErrors();

        expect($this->conversation->messages()->pluck('content')->all())->toBe(['- first point']);
    });
});
