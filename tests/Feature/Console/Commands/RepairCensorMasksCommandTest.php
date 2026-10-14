<?php

declare(strict_types=1);

use App\Models\CommentVersion;
use App\Models\Message;
use App\Models\Mod;

/**
 * Store content as the censor used to mask it, with ASCII asterisks, then configure the words for the repair
 */
function storeLegacyMasked(string $model, array $attributes): mixed
{
    config()->set('censor.words', '');
    $record = $model::factory()->create($attributes);
    config()->set('censor.words', 'bsg,bear,bears,usec,tarkov,tarkovs,escape from tarkov');

    return $record;
}

it('converts stored masks in markdown content and leaves plain fields alone', function (): void {
    $mod = storeLegacyMasked(Mod::class, ['name' => 'B** Overhaul', 'description' => '**prior to B** adding zoom, B** claimed**']);
    $version = storeLegacyMasked(CommentVersion::class, ['body' => 'thanks b**', 'translated_body' => 'danke b**']);
    $message = storeLegacyMasked(Message::class, ['content' => "B**'s fault"]);

    $this->artisan('censor:repair-masks', ['--force' => true])->assertSuccessful();

    expect($mod->refresh()->getRawOriginal('name'))->toBe('B** Overhaul')
        ->and($mod->getRawOriginal('description'))->toBe('**prior to B∗∗ adding zoom, B∗∗ claimed**')
        ->and($version->refresh()->getRawOriginal('body'))->toBe('thanks b∗∗')
        ->and($version->getRawOriginal('translated_body'))->toBe('danke b∗∗')
        ->and($message->refresh()->getRawOriginal('content'))->toBe("B∗∗'s fault");
});

it('tells masks apart from the author\'s emphasis around them', function (string $stored, string $repaired): void {
    $version = storeLegacyMasked(CommentVersion::class, ['body' => $stored, 'translated_body' => null]);

    $this->artisan('censor:repair-masks', ['--force' => true])->assertSuccessful();

    expect($version->refresh()->getRawOriginal('body'))->toBe($repaired);
})->with([
    'bolded word' => ['**prior to B****', '**prior to B∗∗**'],
    'bold opened by the word' => ['**B** adding zoom**', '**B∗∗ adding zoom**'],
    'italic word' => ['*thanks B***', '*thanks B∗∗*'],
    'longer word, no emphasis' => ['B**** said', 'B∗∗∗∗ said'],
    'bolded long word' => ['**I love T*******', '**I love T∗∗∗∗∗**'],
    'two bolded words' => ['**B**** vs **U*****', '**B∗∗** vs **U∗∗∗**'],
    'inline code' => ['`B**` in code', '`B∗∗` in code'],
    'code block' => ["```\n{\"side\": \"B**\"}\n```", "```\n{\"side\": \"B∗∗\"}\n```"],
    'separate paragraphs' => ["**prior to B****\r\n\r\nB** said, **Plan B** too", "**prior to B∗∗**\r\n\r\nB∗∗ said, **Plan B** too"],
    'multi-word entry' => ['Play E'.str_repeat('*', 17).' today', 'Play E'.str_repeat('∗', 17).' today'],
]);

it('leaves the author\'s own markdown alone', function (string $text): void {
    $version = storeLegacyMasked(CommentVersion::class, ['body' => $text, 'translated_body' => null]);

    $this->artisan('censor:repair-masks', ['--force' => true])->assertSuccessful();

    expect($version->refresh()->getRawOriginal('body'))->toBe($text);
})->with([
    'Use **Plan B** here',
    '**B** is the answer',
    '***Type U***',
    'pick **8**',
    'a** stays',
]);

it('repairs hidden mods without touching their updated_at', function (): void {
    $mod = storeLegacyMasked(Mod::class, ['description' => 'thanks B**', 'disabled' => true, 'published_at' => null, 'updated_at' => now()->subYear()]);
    $updatedAt = $mod->refresh()->updated_at->toDateTimeString();

    $this->artisan('censor:repair-masks', ['--force' => true])->assertSuccessful();

    expect($mod->refresh()->getRawOriginal('description'))->toBe('thanks B∗∗')
        ->and($mod->updated_at->toDateTimeString())->toBe($updatedAt);
});

it('changes nothing when run a second time', function (): void {
    $version = storeLegacyMasked(CommentVersion::class, ['body' => '**prior to B****', 'translated_body' => null]);

    $this->artisan('censor:repair-masks', ['--force' => true])->assertSuccessful();

    $this->artisan('censor:repair-masks', ['--force' => true])
        ->expectsOutputToContain('CommentVersion: 0 record(s) updated')
        ->assertSuccessful();

    expect($version->refresh()->getRawOriginal('body'))->toBe('**prior to B∗∗**');
});

it('saves nothing when the confirmation is declined', function (): void {
    $version = storeLegacyMasked(CommentVersion::class, ['body' => 'thanks B**', 'translated_body' => null]);

    $this->artisan('censor:repair-masks')
        ->expectsConfirmation('This rewrites stored markdown content containing censor masks. Continue?', 'no')
        ->assertSuccessful();

    expect($version->refresh()->getRawOriginal('body'))->toBe('thanks B**');
});

it('warns and exits when no words are configured', function (): void {
    config()->set('censor.words', '');

    $this->artisan('censor:repair-masks', ['--force' => true])
        ->expectsOutputToContain('No censored words configured')
        ->assertSuccessful();
});

it('saves nothing on a dry run', function (): void {
    $version = storeLegacyMasked(CommentVersion::class, ['body' => 'thanks B**', 'translated_body' => null]);

    $this->artisan('censor:repair-masks', ['--dry-run' => true])
        ->expectsOutputToContain('CommentVersion: 1 record(s) would change')
        ->assertSuccessful();

    expect($version->refresh()->getRawOriginal('body'))->toBe('thanks B**');
});
