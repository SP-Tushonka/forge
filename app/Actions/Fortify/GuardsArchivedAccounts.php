<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use App\Support\ArchivedAccountLookupLimiter;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

trait GuardsArchivedAccounts
{
    /**
     * Guard that will refuse emails that are still in the archive
     */
    protected function guardArchivedAccount(#[SensitiveParameter] string $email, string $errorBag = 'default'): void
    {
        if (ArchivedAccountLookupLimiter::tooManyAttempts()) {
            throw ValidationException::withMessages([
                'email' => __('Too many attempts. Please try again later.'),
            ])->errorBag($errorBag);
        }

        ArchivedAccountLookupLimiter::hit();

        if (! $this->accountRecovery->findArchivedAccount($email) instanceof User) {
            return;
        }

        throw ValidationException::withMessages([
            'email' => __('This address belonged to an account on the old Forge. Recover that account at :url instead.', [
                'url' => route('account.recovery.request'),
            ]),
        ])->errorBag($errorBag);
    }
}
