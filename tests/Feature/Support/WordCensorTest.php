<?php

declare(strict_types=1);

use App\Models\CommentVersion;
use App\Models\Message;
use App\Models\Mod;
use App\Models\ModIssue;

beforeEach(function (): void {
    config()->set('censor.words', 'tackle,ramp');
});

describe('word censoring on write', function (): void {
    it('censors mod name, teaser, and description', function (): void {
        $mod = Mod::factory()->create([
            'name' => 'Tackle Overhaul',
            'teaser' => 'The best r4mp builder',
            'description' => 'Build a RAMP with t4ckle physics.',
        ]);

        expect($mod->getRawOriginal('name'))->toBe('T∗∗∗∗∗ Overhaul')
            ->and($mod->getRawOriginal('teaser'))->toBe('The best r∗∗∗ builder')
            ->and($mod->getRawOriginal('description'))->toBe('Build a R∗∗∗ with t∗∗∗∗∗ physics.');
    });

    it('censors comment bodies and their translations', function (): void {
        $version = CommentVersion::factory()->translated()->create([
            'body' => 'nice tackle move',
            'translated_body' => 'nice ramp move',
        ]);

        expect($version->getRawOriginal('body'))->toBe('nice t∗∗∗∗∗ move')
            ->and($version->getRawOriginal('translated_body'))->toBe('nice r∗∗∗ move');
    });

    it('censors chat messages', function (): void {
        $message = Message::factory()->create(['content' => 'check this RaMp']);

        expect($message->getRawOriginal('content'))->toBe('check this R∗∗∗');
    });

    it('censors issue titles and bodies', function (): void {
        $issue = ModIssue::factory()->create([
            'title' => 'Crash on the ramp',
            'body' => 'Tackle breaks it.',
        ]);

        expect($issue->getRawOriginal('title'))->toBe('Crash on the r∗∗∗')
            ->and($issue->getRawOriginal('body'))->toBe('T∗∗∗∗∗ breaks it.');
    });

    it('renders masks intact inside the author\'s markdown', function (string $model, string $attribute, string $html): void {
        config()->set('censor.words', 'bsg');

        $record = $model::factory()->create([$attribute => "**prior to BSG adding zoom, BSG claimed** `BSG`\n\n```\n{\"side\": \"BSG\"}\n```"]);

        expect($record->{$html})->toContain('<strong>prior to B∗∗ adding zoom, B∗∗ claimed</strong>')
            ->toContain('<code>B∗∗</code>')
            ->toContain('{"side": "B∗∗"}');
    })->with([
        'mod description' => [Mod::class, 'description', 'description_html'],
        'issue body' => [ModIssue::class, 'body', 'body_html'],
        'comment body' => [CommentVersion::class, 'body', 'body_html'],
        'comment translation' => [CommentVersion::class, 'translated_body', 'translated_body_html'],
        'chat message' => [Message::class, 'content', 'content_html'],
    ]);

    it('stores content untouched when no words are configured', function (): void {
        config()->set('censor.words', '');

        $mod = Mod::factory()->create(['name' => 'Tackle Overhaul']);

        expect($mod->getRawOriginal('name'))->toBe('Tackle Overhaul');
    });
});
