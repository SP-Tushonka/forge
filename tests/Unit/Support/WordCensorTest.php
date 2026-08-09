<?php

declare(strict_types=1);

use App\Support\WordCensor;

describe('WordCensor::censor', function (): void {
    it('masks a configured word with its first character and asterisks', function (): void {
        $censor = new WordCensor(['tackle', 'ramp']);

        expect($censor->censor('Tackle the ramp'))->toBe('T***** the r***');
    });

    it('matches case-insensitively', function (): void {
        $censor = new WordCensor(['abc']);

        expect($censor->censor('ABC and aBc'))->toBe('A** and a**');
    });

    it('detects common character substitutions', function (): void {
        $censor = new WordCensor(['tackle', 'ramp']);

        expect($censor->censor('t4ckl3 the r@mp'))->toBe('t***** the r***');
    });

    it('does not match inside larger words', function (): void {
        $censor = new WordCensor(['grape']);

        expect($censor->censor('grapefruit is fine, grape is not'))->toBe('grapefruit is fine, g**** is not');
    });

    it('matches words adjacent to punctuation', function (): void {
        $censor = new WordCensor(['ramp']);

        expect($censor->censor('What a ramp!'))->toBe('What a r***!');
    });

    it('leaves text unchanged when no words are configured', function (): void {
        $censor = new WordCensor([]);

        expect($censor->censor('anything goes'))->toBe('anything goes');
    });

    it('passes through null and empty strings', function (): void {
        $censor = new WordCensor(['ramp']);

        expect($censor->censor(null))->toBeNull()
            ->and($censor->censor(''))->toBe('');
    });

    it('returns text with invalid UTF-8 unchanged instead of blanking it', function (): void {
        $censor = new WordCensor(['tackle']);
        $text = "tackle \xC3\x28 more";

        expect($censor->censor($text))->toBe($text);
    });

    it('does not censor plain numbers that only resemble a word through substitutions', function (): void {
        $censor = new WordCensor(['sos']);

        expect($censor->censor('meet in room 505'))->toBe('meet in room 505')
            ->and($censor->censor('send an SOS now'))->toBe('send an S** now');
    });

    it('ignores single-character and empty words', function (): void {
        $censor = new WordCensor(['a', '', ' ']);

        expect($censor->censor('a bad apple'))->toBe('a bad apple');
    });

    it('leaves URLs and domains intact while still censoring surrounding prose', function (): void {
        $censor = new WordCensor(['tackle']);

        expect($censor->censor('Get tackle at https://hub.tackle.com/download today'))
            ->toBe('Get t***** at https://hub.tackle.com/download today')
            ->and($censor->censor('see www.tackle.org or tackle.com'))->toBe('see www.tackle.org or tackle.com');
    });

    it('censors markdown link labels but not their URLs', function (): void {
        $censor = new WordCensor(['tackle']);

        expect($censor->censor('[Tackle guide](https://mods.tackle.com/files/1-tackle/)'))
            ->toBe('[T***** guide](https://mods.tackle.com/files/1-tackle/)');
    });

    it('still censors a word that ends a sentence', function (): void {
        $censor = new WordCensor(['tackle']);

        expect($censor->censor('I love tackle. More text'))->toBe('I love t*****. More text');
    });

    it('is idempotent so re-saving censored text changes nothing', function (): void {
        $censor = new WordCensor(['tackle']);
        $once = $censor->censor('Tackle time');

        expect($once)->toBe('T***** time')
            ->and($censor->censor($once))->toBe($once);
    });
});

describe('WordCensor::fromConfig', function (): void {
    it('parses the comma-separated word list, ignoring whitespace and empty entries', function (): void {
        config()->set('censor.words', ' tackle , ramp ,, ');

        expect(WordCensor::fromConfig()->censor('Tackle the ramp'))->toBe('T***** the r***');
    });

    it('censors nothing when the list is empty', function (): void {
        config()->set('censor.words', '');

        expect(WordCensor::fromConfig()->censor('Tackle the ramp'))->toBe('Tackle the ramp');
    });
});
