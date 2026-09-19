<?php

declare(strict_types=1);

use App\Actions\ModIssues\ChangeModIssueStatus;
use App\Actions\ModIssues\EditModIssue;
use App\Enums\EmojiSurface;
use App\Enums\ModIssueStatus;
use App\Models\Mod;
use App\Models\ModIssue;
use App\Models\ModIssueEvent;
use App\Models\Scopes\PublishedScope;
use App\Models\User;
use App\Traits\Livewire\HandlesReactions;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new #[Layout('layouts::base')] class extends Component
{
    use HandlesReactions;

    #[Locked]
    public ModIssue $issue;

    public bool $editing = false;

    public string $editTitle = '';

    public string $editBody = '';

    public function mount(int $modId, string $slug, int $number): void
    {
        $mod = Mod::query()->withoutGlobalScope(PublishedScope::class)->findOrFail($modId);

        $this->issue = ModIssue::withTrashed()
            ->where('mod_id', $mod->id)
            ->where('number', $number)
            ->firstOrFail();
        $this->issue->setRelation('mod', $mod);

        Gate::authorize('view', $this->issue);

        if ($mod->slug !== $slug) {
            $this->redirectRoute('mod.issue.show', ['modId' => $mod->id, 'slug' => $mod->slug, 'number' => $number]);
        }
    }

    /**
     * The issue can be deleted, or its mod unpublished, while the page is open.
     */
    public function hydrate(): void
    {
        Gate::authorize('view', $this->issue);
    }

    #[On('mod-issue-updated')]
    public function refreshIssue(): void
    {
        $this->issue->refresh();

        unset($this->events, $this->isSubscribed);
    }

    /**
     * @return Collection<int, ModIssueEvent>
     */
    #[Computed]
    public function events(): Collection
    {
        return $this->issue->events()->with('user')->get();
    }

    #[Computed]
    public function isSubscribed(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $this->issue->isUserSubscribed($user);
    }

    public function toggleSubscription(): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return;
        }

        if ($this->isSubscribed) {
            $this->issue->unsubscribeUser($user);
        } else {
            $this->issue->subscribeUser($user);
        }

        unset($this->isSubscribed);
    }

    public function startEditing(): void
    {
        $this->authorize('update', $this->issue);

        $this->editTitle = $this->issue->title;
        $this->editBody = $this->issue->body;
        $this->editing = true;
    }

    public function saveEdit(): void
    {
        $this->authorize('update', $this->issue);

        $this->validate([
            'editTitle' => ['required', 'string', 'min:5', 'max:'.config()->integer('mod-issues.validation.title_max')],
            'editBody' => ['required', 'string', 'min:10', 'max:'.config()->integer('mod-issues.validation.body_max')],
        ]);

        resolve(EditModIssue::class)->execute($this->issue, mb_trim($this->editTitle), mb_trim($this->editBody));

        $this->editing = false;
    }

    public function close(): void
    {
        $this->authorize('close', $this->issue);

        resolve(ChangeModIssueStatus::class)->execute($this->issue, ModIssueStatus::Closed, $this->actor());

        $this->refreshIssue();
    }

    public function reopen(): void
    {
        $this->authorize('reopen', $this->issue);

        resolve(ChangeModIssueStatus::class)->execute($this->issue, ModIssueStatus::New, $this->actor());

        $this->refreshIssue();
    }

    protected function reactionSurface(): EmojiSurface
    {
        return EmojiSurface::CommentReactions;
    }

    /**
     * @return class-string<ModIssue>
     */
    protected function reactableClass(): string
    {
        return ModIssue::class;
    }

    /**
     * @return list<int>
     */
    protected function reactableIds(): array
    {
        return [$this->issue->id];
    }

    private function actor(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
};
