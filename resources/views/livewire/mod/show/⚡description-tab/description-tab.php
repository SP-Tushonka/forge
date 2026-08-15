<?php

declare(strict_types=1);

use App\Traits\Livewire\AuthorizesModTab;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Component;

new #[Lazy] class extends Component
{
    use AuthorizesModTab;

    /**
     * Mount the component.
     */
    public function mount(int $modId): void
    {
        $this->modId = $modId;
        $this->authorizeModTab();
    }

    /**
     * Get the mod's description HTML.
     */
    #[Computed]
    public function descriptionHtml(): string
    {
        return $this->mod->description_html;
    }
};
