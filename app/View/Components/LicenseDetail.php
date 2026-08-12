<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Models\License;
use App\Models\SourceCodeLink;
use App\Support\ClaimRepositoryUrl;
use Illuminate\View\Component;
use Illuminate\View\View;

final class LicenseDetail extends Component
{
    /**
     * The licence text
     *
     * @var list<array{label: string, url: string}>
     */
    public array $customLicenseFiles;

    /**
     * @param  iterable<int, SourceCodeLink>  $sourceCodeLinks  Every repository the mod or addon lists.
     */
    public function __construct(
        public License $license,
        iterable $sourceCodeLinks = [],
    ) {
        $this->customLicenseFiles = $license->isCustom() ? $this->resolveCustomLicenseFiles($sourceCodeLinks) : [];
    }

    /**
     * Get the view / contents that represent the component
     */
    public function render(): View
    {
        return view('components.license-detail');
    }

    /**
     * A custom licence is proven by a licence file in every listed repository
     *
     * @param  iterable<int, SourceCodeLink>  $sourceCodeLinks
     * @return list<array{label: string, url: string}>
     */
    private function resolveCustomLicenseFiles(iterable $sourceCodeLinks): array
    {
        $fileName = config()->string('custom-license.file_name', 'LICENSE.md');
        $files = [];

        foreach ($sourceCodeLinks as $link) {
            $url = ClaimRepositoryUrl::defaultBranchFileUrl($link->url, $fileName);

            if ($url === null) {
                continue;
            }

            $files[] = [
                'label' => $link->label !== null && $link->label !== ''
                    ? $link->label
                    : ClaimRepositoryUrl::repository($link->url) ?? $link->url,
                'url' => $url,
            ];
        }

        return $files;
    }
}
