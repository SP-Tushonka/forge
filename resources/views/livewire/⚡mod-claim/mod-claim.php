<?php

declare(strict_types=1);

use App\Enums\ClaimAttemptOutcome;
use App\Enums\ModClaimStatus;
use App\Enums\TrackingEventType;
use App\Facades\Track;
use App\Jobs\VerifyModClaimJob;
use App\Models\Mod;
use App\Models\ModClaim;
use App\Services\Claim\ModClaimService;
use App\Support\ClaimRepositoryUrl;
use App\Support\DataTransferObjects\ClaimAttempt;
use Flux\Flux;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    /**
     * The mod being claimed.
     */
    #[Locked]
    public int $modId;

    /**
     * Whether the current user may start a claim on this mod
     */
    #[Locked]
    public bool $canClaim = false;

    /**
     * Controls whether the claim modal is open
     */
    public bool $showModal = false;

    public function mount(int $modId): void
    {
        $this->modId = $modId;

        $user = auth()->user();
        $mod = Mod::query()->find($modId);

        if ($user && $mod) {
            $this->canClaim = $user->can('initiate', [ModClaim::class, $mod]);
        }
    }

    /**
     * The claim the current user already has against this mod
     */
    #[Computed]
    public function claim(): ?ModClaim
    {
        if (! auth()->check()) {
            return null;
        }

        return ModClaim::query()
            ->where('mod_id', $this->modId)
            ->where('user_id', auth()->id())
            ->first();
    }

    /**
     * Whether any of the mod source links can be checked automatically. A mod with no supported
     * source can only ever be reviewed by hand.
     */
    #[Computed]
    public function hasAutomaticSource(): bool
    {
        $mod = Mod::query()->with('sourceCodeLinks')->find($this->modId);

        if ($mod === null) {
            return false;
        }

        foreach ($mod->sourceCodeLinks as $link) {
            if (ClaimRepositoryUrl::methodFor($link->url) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Start a claim and reveal the token to publish.
     */
    public function initiate(ModClaimService $claims): void
    {
        abort_unless($this->canClaim, 403);

        $mod = Mod::query()->findOrFail($this->modId);
        $attempt = $claims->initiate($mod, auth()->user());

        if ($attempt->outcome === ClaimAttemptOutcome::Started) {
            Track::event(TrackingEventType::MOD_CLAIM_INITIATED, $mod);
            $this->showModal = true;
        }

        unset($this->claim);

        $this->toast($attempt);
    }

    /**
     * Run automated verification against the mods linked repositories.
     */
    public function verify(): void
    {
        abort_unless($this->canClaim, 403);

        $claim = $this->claim;

        if (! $claim instanceof ModClaim || $claim->status !== ModClaimStatus::Pending) {
            return;
        }

        $key = 'mod-claim-verify:'.$claim->id;

        if (RateLimiter::tooManyAttempts($key, config()->integer('claim.retry_max_attempts', 10))) {
            $this->toast(new ClaimAttempt(ClaimAttemptOutcome::RateLimited, retryAfterSeconds: RateLimiter::availableIn($key)));

            return;
        }

        RateLimiter::hit($key, config()->integer('claim.retry_decay_seconds', 600));

        /** @var ClaimAttempt $attempt */
        $attempt = app()->call([new VerifyModClaimJob($claim), 'handle']);

        unset($this->claim);

        $this->toast($attempt);
    }

    /**
     * Hand the claim to a moderator.
     */
    public function escalate(ModClaimService $claims): void
    {
        abort_unless($this->canClaim, 403);

        $claim = $this->claim;

        if (! $claim instanceof ModClaim || $claim->status !== ModClaimStatus::Pending) {
            return;
        }

        $attempt = $claims->escalate($claim);

        unset($this->claim);

        $this->toast($attempt);
    }

    /**
     * Surface an attempt outcome to the claimant.
     */
    private function toast(ClaimAttempt $attempt): void
    {
        Flux::toast(
            heading: $attempt->outcome->toastHeading(),
            text: $attempt->outcome->toastText($attempt->retryAfterSeconds),
            variant: $attempt->outcome->toastVariant(),
        );
    }
};
