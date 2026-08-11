<?php

declare(strict_types=1);

use App\Actions\Fortify\PasswordValidationRules;
use App\Enums\AccountRecoveryOutcome;
use App\Models\User;
use App\Services\AccountRecoveryService;
use App\Support\ArchivedAccountLookupLimiter;
use App\Support\DataTransferObjects\RecoveryAttempt;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::base', ['variant' => 'simple', 'robots' => 'noindex'])] #[Title('Recover Your Account - The Forge')] class extends Component
{
    use PasswordValidationRules;

    /**
     * The emailed token, which is the only proof the claimant controls the address
     */
    #[Locked]
    public string $token = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
    }

    /**
     * Redeem the link, signing the claimant into the account it hands back
     */
    public function submit(AccountRecoveryService $recovery): void
    {
        $this->validate(['password' => $this->passwordRules()]);

        if (ArchivedAccountLookupLimiter::tooManyAttempts('redeem')) {
            $this->toast(new RecoveryAttempt(AccountRecoveryOutcome::RateLimited, ArchivedAccountLookupLimiter::availableIn('redeem')));

            return;
        }

        ArchivedAccountLookupLimiter::hit('redeem');

        $attempt = $recovery->completeRecovery($this->token, $this->password);

        $this->reset('password', 'password_confirmation');

        if ($attempt->outcome === AccountRecoveryOutcome::Recovered && $attempt->user instanceof User) {
            session()->invalidate();

            Auth::login($attempt->user);

            $this->redirect(route('dashboard'));

            return;
        }

        $this->toast($attempt);
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
