<?php

declare(strict_types=1);

use App\Models\License;
use App\Models\Mod;
use App\Models\Scopes\PublishedScope;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::base')] #[Title('License Management - The Forge')] class extends Component
{
    public bool $showCreateModal = false;

    public string $formName = '';

    public string $formLink = '';

    public function mount(): void
    {
        $this->authorize('admin');
    }

    /**
     * Every license, ordered by name.
     *
     * @return Collection<int, License>
     */
    #[Computed]
    public function licenses(): Collection
    {
        return License::query()
            ->withCount(['mods' => $this->countEveryMod(...)])
            ->orderBy('name')
            ->get();
    }

    public function showCreateLicense(): void
    {
        $this->resetValidation();
        $this->reset('formName', 'formLink');
        $this->showCreateModal = true;
    }

    /**
     * Create a license
     */
    public function createLicense(): void
    {
        $this->authorize('admin');

        $this->formName = Str::squish($this->formName);
        $this->formLink = mb_trim($this->formLink);

        $this->validate([
            'formName' => ['required', 'string', 'max:255', $this->nameNotAlreadyTaken(...)],
            'formLink' => ['required', 'url:http,https', 'max:255'],
        ]);

        try {
            License::query()->create([
                'name' => $this->formName,
                'link' => $this->formLink,
            ]);
        } catch (UniqueConstraintViolationException) {

            throw ValidationException::withMessages([
                'formName' => __('validation.unique', ['attribute' => 'name']),
            ]);
        }

        $this->closeModal();

        unset($this->licenses);

        Flux::toast(heading: 'License Created', text: 'The license is now available to mod authors.', variant: 'success');
    }

    public function closeModal(): void
    {
        $this->showCreateModal = false;
        $this->reset('formName', 'formLink');
    }

    /**
     * Reject a name that already exists, ignoring case
     */
    private function nameNotAlreadyTaken(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (License::query()->whereRaw('lower(name) = ?', [mb_strtolower($value)])->exists()) {
            $fail(__('validation.unique', ['attribute' => 'name']));
        }
    }

    /**
     * Mods are limited to published rows for the public site
     *
     * @param  Builder<Mod>  $query
     * @return Builder<Mod>
     */
    private function countEveryMod(Builder $query): Builder
    {
        return $query->withoutGlobalScope(PublishedScope::class);
    }
};
