<?php

declare(strict_types=1);

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::base')] #[Title('Staff Tools - The Forge')] class extends Component
{
    public function mount(): void
    {
        abort_unless((bool) auth()->user()?->isAdmin(), 403, 'Access denied. Staff privileges required.');
    }
};
