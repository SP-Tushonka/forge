<?php

declare(strict_types=1);

use App\Enums\AccountRecoveryOutcome;
use App\Services\AccountRecoveryService;
use App\Support\ArchivedAccountLookupLimiter;
use App\Support\DataTransferObjects\RecoveryAttempt;
use Flux\Flux;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('layouts::base', ['variant' => 'simple', 'robots' => 'noindex'])] #[Title('Recover Your Account - The Forge')] class extends Component
{
    /**
     * The address the claimant used on the retired Forge
     */
    #[Validate(['required', 'string', 'email', 'max:255'])]
    public string $email = '';

    /**
     * Request a recovery link for the address
     */
    public function submit(AccountRecoveryService $recovery): void
    {
        $this->validate();

        if (ArchivedAccountLookupLimiter::tooManyAttempts()) {
            $this->toast(new RecoveryAttempt(AccountRecoveryOutcome::RateLimited, ArchivedAccountLookupLimiter::availableIn()));

            return;
        }

        ArchivedAccountLookupLimiter::hit();

        $this->toast($recovery->requestRecovery($this->email));
    }

    /**
     * Surface an attempt outcome to the claimant
     */
    private function toast(RecoveryAttempt $attempt): void
    {
        Flux::toast(
            heading: $attempt->outcome->toastHeading(),
            text: $attempt->outcome->toastText($attempt->retryAfterSeconds),
            variant: $attempt->outcome->toastVariant(),
        );
    }
};
