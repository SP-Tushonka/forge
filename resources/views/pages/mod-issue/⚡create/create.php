<?php

declare(strict_types=1);

use App\Actions\ModIssues\CreateModIssue;
use App\Enums\ModIssueType;
use App\Livewire\Concerns\RendersMarkdownPreview;
use App\Models\Mod;
use App\Models\ModIssue;
use App\Models\ModVersion;
use App\Models\Scopes\PublishedScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Spatie\Honeypot\Http\Livewire\Concerns\HoneypotData;
use Spatie\Honeypot\Http\Livewire\Concerns\UsesSpamProtection;

new #[Layout('layouts::base')] class extends Component
{
    use RendersMarkdownPreview;
    use UsesSpamProtection;

    private const string BUG_TEMPLATE = "**Steps to reproduce**\n1. \n\n**What you expected**\n\n\n**What happened**\n\n\n**Logs**\nUpload your log files to https://codepaste.sp-mod.com and paste the link here.";

    public HoneypotData $honeypotData;

    #[Locked]
    public Mod $mod;

    public string $type = 'bug';

    public string $title = '';

    public string $body = self::BUG_TEMPLATE;

    public ?int $affectedVersionId = null;

    /**
     * Set once the issue exists. The form submits through Alpine rather than wire:submit, so Livewire does not lock
     * it while saving, and a double click queues a second save() behind the first.
     */
    #[Locked]
    public bool $submitted = false;

    public function mount(int $modId, string $slug): void
    {
        $this->honeypotData = new HoneypotData;
        $this->mod = Mod::query()->withoutGlobalScope(PublishedScope::class)->findOrFail($modId);

        Gate::authorize('create', [ModIssue::class, $this->mod]);

        if ($this->mod->slug !== $slug) {
            $this->redirectRoute('mod.issue.create', ['modId' => $this->mod->id, 'slug' => $this->mod->slug]);

            return;
        }

        $this->affectedVersionId = $this->affectedVersions->first()?->id;
    }

    /**
     * A ban or issues being switched off takes effect on an open form too.
     */
    public function hydrate(): void
    {
        Gate::authorize('create', [ModIssue::class, $this->mod]);
    }

    /**
     * The template only swaps while the body is untouched, so switching type never discards what they wrote.
     */
    public function updatedType(string $value): void
    {
        if ($value === ModIssueType::Feature->value && $this->body === self::BUG_TEMPLATE) {
            $this->body = '';
        }

        if ($value === ModIssueType::Bug->value && mb_trim($this->body) === '') {
            $this->body = self::BUG_TEMPLATE;
        }
    }

    /**
     * Versions the reporter can see, newest first. Managers may file against versions that are not public yet.
     *
     * @return Collection<int, ModVersion>
     */
    #[Computed]
    public function affectedVersions(): Collection
    {
        $user = Auth::user();
        $isManager = $user instanceof User && ($user->isModOrAdmin() || $this->mod->isAuthorOrOwner($user));

        return $this->mod->versions()
            ->unless($isManager, function (Builder $query): void {
                $query->where(function (Builder $visible): void {
                    $visible->publiclyVisible()->orWhere(function (Builder $legacy): void {
                        $legacy->legacyPubliclyVisible();
                    });
                });
            })
            ->get(['id', 'version']);
    }

    public function save(): void
    {
        if ($this->submitted) {
            return;
        }

        Gate::authorize('create', [ModIssue::class, $this->mod]);
        $this->protectAgainstSpam();

        /** @var User $user */
        $user = Auth::user();
        $rateLimited = ! $user->isModOrAdmin() && ! $this->mod->isAuthorOrOwner($user);
        $key = 'mod-issues:'.$user->id;

        if ($rateLimited && RateLimiter::tooManyAttempts($key, config()->integer('mod-issues.rate_limiting.max_attempts'))) {
            $this->addError('title', __('You are opening issues too quickly. Try again in :minutes minutes.', [
                'minutes' => (int) ceil(RateLimiter::availableIn($key) / 60),
            ]));

            return;
        }

        $this->validate([
            'type' => ['required', Rule::enum(ModIssueType::class)],
            'title' => ['required', 'string', 'min:5', 'max:'.config()->integer('mod-issues.validation.title_max')],
            'body' => ['required', 'string', 'min:10', 'max:'.config()->integer('mod-issues.validation.body_max'), Rule::notIn([self::BUG_TEMPLATE])],
            'affectedVersionId' => [
                Rule::requiredIf($this->type === ModIssueType::Bug->value),
                'nullable',
                'integer',
                Rule::in($this->affectedVersions->pluck('id')->all()),
            ],
        ], [
            'body.not_in' => __('Fill in the template before opening the issue.'),
        ]);

        if ($rateLimited) {
            RateLimiter::hit($key, config()->integer('mod-issues.rate_limiting.decay_seconds'));
        }

        $issue = resolve(CreateModIssue::class)->execute(
            $user,
            $this->mod,
            ModIssueType::from($this->type),
            mb_trim($this->title),
            mb_trim($this->body),
            $this->affectedVersionId,
        );

        $this->submitted = true;
        $this->redirect($issue->url());
    }
};
