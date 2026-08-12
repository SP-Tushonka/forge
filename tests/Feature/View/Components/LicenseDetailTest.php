<?php

declare(strict_types=1);

use App\Models\License;
use App\Models\Mod;
use App\Models\ModVersion;
use App\Models\SourceCodeLink;
use App\Models\SptVersion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Blade;

/**
 * Render the component against unsaved models: nothing it reads is persisted.
 *
 * @param  list<array{url: string, label?: string}>  $links
 */
function renderLicenseDetail(License $license, array $links = []): string
{
    $sourceCodeLinks = new Collection(array_map(
        static fn (array $link): SourceCodeLink => new SourceCodeLink([
            'url' => $link['url'],
            'label' => $link['label'] ?? '',
        ]),
        $links,
    ));

    return Blade::render(
        '<ul><x-license-detail :license="$license" :source-code-links="$sourceCodeLinks" /></ul>',
        ['license' => $license, 'sourceCodeLinks' => $sourceCodeLinks],
    );
}

describe('LicenseDetail Blade Component', function (): void {
    it('links a custom license to the license file in every source repository', function (): void {
        $html = renderLicenseDetail(
            new License(['name' => License::CUSTOM_NAME, 'link' => '']),
            [
                ['url' => 'https://github.com/owner/repo'],
                ['url' => 'https://gitlab.com/other/mod', 'label' => 'Client'],
            ],
        );

        expect($html)
            ->toContain(License::CUSTOM_NAME)
            ->toContain('https://github.com/owner/repo/blob/HEAD/LICENSE.md')
            ->toContain('owner/repo')
            ->toContain('https://gitlab.com/other/mod/-/blob/HEAD/LICENSE.md')
            ->toContain('Client');
    });

    it('omits repositories with no readable license file URL', function (): void {
        $html = renderLicenseDetail(
            new License(['name' => License::CUSTOM_NAME, 'link' => '']),
            [['url' => 'https://example.test/owner/repo']],
        );

        expect($html)
            ->toContain(License::CUSTOM_NAME)
            ->not->toContain('LICENSE.md');
    });

    it('leaves a named license as a single link to its canonical text', function (): void {
        $html = renderLicenseDetail(
            new License(['name' => 'MIT', 'link' => 'https://opensource.org/license/mit']),
            [['url' => 'https://github.com/owner/repo']],
        );

        expect($html)
            ->toContain('https://opensource.org/license/mit')
            ->not->toContain('LICENSE.md');
    });

    it('renders on the mod show page', function (): void {
        $license = License::query()->firstOrCreate(['name' => License::CUSTOM_NAME], ['link' => '']);
        $mod = Mod::factory()->create(['license_id' => $license->id]);
        $mod->sourceCodeLinks()->delete();
        $mod->sourceCodeLinks()->create(['url' => 'https://github.com/owner/repo', 'label' => '']);

        $sptVersion = SptVersion::query()->firstOrCreate(
            ['version' => '3.9.0'],
            SptVersion::factory()->make(['version' => '3.9.0'])->toArray(),
        );
        $modVersion = ModVersion::factory()->create([
            'mod_id' => $mod->id,
            'published_at' => now()->subDay(),
            'spt_version_constraint' => '>=3.0.0',
        ]);
        $modVersion->sptVersions()->sync($sptVersion->id);

        $this->get($mod->refresh()->detail_url)
            ->assertOk()
            ->assertSee('https://github.com/owner/repo/blob/HEAD/LICENSE.md', false);
    });
});
