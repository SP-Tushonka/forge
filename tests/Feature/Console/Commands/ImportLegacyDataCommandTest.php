<?php

declare(strict_types=1);

use App\Models\Mod;
use App\Models\ModVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Write one JSON object per line into an ndjson fixture file.
 *
 * @param  array<int, array<string, mixed>>  $rows
 */
function writeNdjson(string $path, array $rows): void
{
    File::put($path, implode(PHP_EOL, array_map(fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $rows)));
}

beforeEach(function (): void {
    $this->source = storage_path('app/testing-legacy-import-'.Str::random(8));
    File::makeDirectory($this->source, recursive: true);
    writeNdjson("{$this->source}/spt_versions.ndjson", []);
    writeNdjson("{$this->source}/mod_categories.ndjson", []);
});

afterEach(function (): void {
    File::deleteDirectory($this->source);
});

describe('incremental import', function (): void {
    it('adds missing records but never touches existing ones', function (): void {
        $mod = Mod::factory()->create(['description' => 'local edit stays']);
        $version = ModVersion::factory()->recycle($mod)->create(['description' => 'local version stays']);

        writeNdjson("{$this->source}/mods.ndjson", [
            ['id' => $mod->id, 'name' => 'Archive Name', 'slug' => 'archive-name', 'description' => 'archive must not win'],
            ['id' => $mod->id + 1000, 'name' => 'Fresh Mod', 'slug' => 'fresh-mod', 'description' => 'brand new'],
        ]);
        writeNdjson("{$this->source}/mod_versions.ndjson", [
            ['id' => $version->id, 'mod_id' => $mod->id, 'version' => '9.9.9', 'description' => 'archive must not win'],
            ['id' => $version->id + 1000, 'mod_id' => $mod->id, 'version' => '1.2.3', 'description' => 'new version'],
        ]);

        $this->artisan('app:import-legacy', ['--source' => $this->source])
            ->expectsOutputToContain('mods: 1 added, 1 already present')
            ->expectsOutputToContain('mod_versions: 1 added, 1 already present')
            ->assertSuccessful();

        expect($mod->refresh()->getRawOriginal('description'))->toBe('local edit stays')
            ->and($version->refresh()->getRawOriginal('description'))->toBe('local version stays')
            ->and(DB::table('mods')->where('id', $mod->id + 1000)->value('description'))->toBe('brand new')
            ->and(DB::table('mod_versions')->where('id', $version->id + 1000)->value('version'))->toBe('1.2.3');
    });

    it('does not clobber source code links of an existing mod', function (): void {
        $mod = Mod::factory()->create();
        $mod->sourceCodeLinks()->delete();
        $mod->addSourceCodeLink('https://github.com/claimant/repo');

        writeNdjson("{$this->source}/mods.ndjson", [
            ['id' => $mod->id, 'name' => 'Archive', 'slug' => 'archive', 'description' => '', 'source_code_links' => [
                ['url' => 'https://example.com/archive-link', 'label' => 'old'],
            ]],
        ]);
        writeNdjson("{$this->source}/mod_versions.ndjson", []);

        $this->artisan('app:import-legacy', ['--source' => $this->source])->assertSuccessful();

        expect($mod->refresh()->sourceCodeLinks->pluck('url')->all())->toBe(['https://github.com/claimant/repo']);
    });

    it('skips rows with a missing or invalid id instead of inserting them', function (): void {
        writeNdjson("{$this->source}/mods.ndjson", [
            ['id' => null, 'name' => 'No Id', 'slug' => 'no-id', 'description' => ''],
            ['id' => 0, 'name' => 'Zero Id', 'slug' => 'zero-id', 'description' => ''],
            ['id' => 515151, 'name' => 'Valid', 'slug' => 'valid', 'description' => ''],
        ]);
        writeNdjson("{$this->source}/mod_versions.ndjson", []);

        $this->artisan('app:import-legacy', ['--source' => $this->source])
            ->expectsOutputToContain('mods: row with missing or invalid id skipped')
            ->assertSuccessful();

        expect(DB::table('mods')->whereIn('slug', ['no-id', 'zero-id'])->count())->toBe(0)
            ->and(DB::table('mods')->where('id', 515151)->exists())->toBeTrue();
    });

    it('rewrites retired-domain self-links in newly imported descriptions', function (): void {
        writeNdjson("{$this->source}/mods.ndjson", [
            ['id' => 424242, 'name' => 'Linked Mod', 'slug' => 'linked-mod',
                'description' => '<p>See <a href="https://forge.sp-tarkov.com/mod/1/other">other</a></p>'],
        ]);
        writeNdjson("{$this->source}/mod_versions.ndjson", []);

        $this->artisan('app:import-legacy', ['--source' => $this->source])->assertSuccessful();

        $description = (string) DB::table('mods')->where('id', 424242)->value('description');
        expect($description)->toContain(mb_rtrim(config()->string('app.url'), '/').'/mod/1/other')
            ->not->toContain('sp-tarkov');
    });
});
