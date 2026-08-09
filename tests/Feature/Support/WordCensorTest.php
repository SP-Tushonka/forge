<?php

declare(strict_types=1);

use App\Models\CommentVersion;
use App\Models\Message;
use App\Models\Mod;

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

        expect($mod->getRawOriginal('name'))->toBe('T***** Overhaul')
            ->and($mod->getRawOriginal('teaser'))->toBe('The best r*** builder')
            ->and($mod->getRawOriginal('description'))->toBe('Build a R*** with t***** physics.');
    });

    it('censors comment bodies and their translations', function (): void {
        $version = CommentVersion::factory()->translated()->create([
            'body' => 'nice tackle move',
            'translated_body' => 'nice ramp move',
        ]);

        expect($version->getRawOriginal('body'))->toBe('nice t***** move')
            ->and($version->getRawOriginal('translated_body'))->toBe('nice r*** move');
    });

    it('censors chat messages', function (): void {
        $message = Message::factory()->create(['content' => 'check this RaMp']);

        expect($message->getRawOriginal('content'))->toBe('check this R***');
    });

    it('stores content untouched when no words are configured', function (): void {
        config()->set('censor.words', '');

        $mod = Mod::factory()->create(['name' => 'Tackle Overhaul']);

        expect($mod->getRawOriginal('name'))->toBe('Tackle Overhaul');
    });
});
