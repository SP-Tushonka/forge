<?php

declare(strict_types=1);

namespace App\View\Components\Mod;

use App\Models\ModIssue;
use App\Models\ModVersion;
use Illuminate\View\Component;
use Illuminate\View\View;

final class VersionCard extends Component
{
    /**
     * Create a new component instance.
     *
     * @param  list<ModIssue>  $fixedIssues
     */
    public function __construct(
        public ModVersion $version,
        public ?int $latestVersionId = null,
        public ?bool $showActions = null,
        public array $fixedIssues = [],
    ) {
        //
    }

    /**
     * Whether this version is the latest version of the mod.
     */
    public function isLatest(): bool
    {
        return $this->latestVersionId !== null && $this->version->id === $this->latestVersionId;
    }

    /**
     * The unique modal name for this version's download modal.
     */
    public function modalName(): string
    {
        return 'version-download-'.$this->version->id;
    }

    /**
     * The unique modal name for this version's verification details modal.
     */
    public function verificationModalName(): string
    {
        return 'version-verification-'.$this->version->id;
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View
    {
        return view('components.mod.version-card');
    }
}
