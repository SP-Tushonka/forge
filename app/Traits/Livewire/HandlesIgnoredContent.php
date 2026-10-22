<?php

declare(strict_types=1);

namespace App\Traits\Livewire;

use App\Models\Addon;
use App\Models\Mod;
use App\Models\ModIssue;
use App\Models\ModList;
use App\Models\User;
use App\Support\DataTransferObjects\IgnoreReason;
use App\Support\IgnoredContent;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * Replaces a page with the ignored-content notice while its subject is hidden from the viewer, until they choose to
 * show it anyway (for this visit) or stop ignoring it. Both choices reload the page, because the layout header is
 * only rendered on a full page load and would otherwise stay hidden.
 *
 * @phpstan-ignore trait.unused
 */
trait HandlesIgnoredContent
{
    #[Url(as: 'show-ignored')]
    public bool $showIgnored = false;

    abstract protected function ignorableSubject(): Mod|Addon|ModList|ModIssue;

    abstract protected function ignorableUrl(): string;

    #[Computed]
    public function ignoreReason(): ?IgnoreReason
    {
        return IgnoredContent::reasonFor($this->ignorableSubject(), auth()->user());
    }

    #[Computed]
    public function hidesIgnoredContent(): bool
    {
        return ! $this->showIgnored && $this->ignoreReason instanceof IgnoreReason;
    }

    public function showIgnoredContent(): void
    {
        $url = $this->ignorableUrl();

        $this->redirect($url.(str_contains($url, '?') ? '&' : '?').'show-ignored=1', navigate: true);
    }

    /**
     * Removes the ignore behind the reason shown. If another ignore still hides the page, its reason is shown next.
     */
    public function stopIgnoring(): void
    {
        $viewer = auth()->user();
        $reason = $this->ignoreReason;

        if (! $viewer instanceof User || ! $reason instanceof IgnoreReason) {
            return;
        }

        if ($reason->user instanceof User) {
            $viewer->unignoreUser($reason->user);
        } elseif ($reason->mod instanceof Mod) {
            $viewer->unignoreMod($reason->mod);
        }

        Flux::toast(heading: __('No longer ignored'), text: __('This is visible across the site again.'), variant: 'success');

        $this->redirect($this->ignorableUrl(), navigate: true);
    }
}
