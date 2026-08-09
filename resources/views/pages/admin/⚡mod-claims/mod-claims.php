<?php

declare(strict_types=1);

use App\Enums\ClaimVerificationMethod;
use App\Enums\ModClaimStatus;
use App\Enums\TrackingEventType;
use App\Facades\Track;
use App\Models\ModClaim;
use App\Services\Claim\ModClaimService;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::base')] #[Title('Mod Claims - The Forge')] class extends Component
{
    use WithPagination;

    /**
     * Show every claim rather than only those awaiting review.
     */
    public bool $showAll = false;

    /**
     * Claims awaiting a decision. Only escalated claims appear by default: an unescalated pending claim is still with
     * the claimant, who may yet verify it automatically
     *
     * @return LengthAwarePaginator<int, ModClaim>
     */
    #[Computed]
    public function claims(): LengthAwarePaginator
    {
        return ModClaim::query()
            ->with(['user', 'mod.sourceCodeLinks'])
            ->when(! $this->showAll, fn ($query) => $query
                ->where('status', ModClaimStatus::Pending)
                ->whereNotNull('escalated_at'))
            ->orderByDesc('escalated_at')
            ->orderByDesc('created_at')
            ->paginate(25);
    }

    /**
     * Grant the claim, provided the mod is still unowned
     */
    public function approve(int $claimId, ModClaimService $claims): void
    {
        $claim = ModClaim::query()->findOrFail($claimId);

        $this->authorize('moderate', $claim);

        if ($claims->award($claim, ClaimVerificationMethod::Manual)) {
            Track::eventSync(TrackingEventType::MOD_CLAIM_VERIFIED, $claim->mod, isModerationAction: true);

            Flux::toast(heading: __('Claim approved'), text: __('The mod has been assigned to the claimant.'), variant: 'success');
        } else {
            Flux::toast(heading: __('Already owned'), text: __('This mod gained an owner before the claim was approved.'), variant: 'danger');
        }

        unset($this->claims);
    }

    /**
     * Reject the claim
     */
    public function reject(int $claimId, ModClaimService $claims): void
    {
        $claim = ModClaim::query()->findOrFail($claimId);

        $this->authorize('moderate', $claim);

        $claims->reject($claim);

        Track::eventSync(TrackingEventType::MOD_CLAIM_REJECTED, $claim->mod, isModerationAction: true);

        Flux::toast(heading: __('Claim rejected'), text: __('The claimant has not been given ownership.'), variant: 'default');

        unset($this->claims);
    }
};
