<?php

declare(strict_types=1);

use App\Actions\AltMonitoring\SaveAltWatch;
use App\Enums\AltWatchMatchMode;
use App\Exceptions\AltWatchTooBroadException;
use App\Models\AltWatch;
use App\Models\AltWatchIndicator;
use App\Models\User;
use App\Services\AltIndicatorService;
use App\Services\AltWatchMatchService;
use App\Support\DataTransferObjects\AltIndicator;
use App\Support\DataTransferObjects\AltWatchDraft;
use App\Support\DataTransferObjects\AltWatchPreview;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::base')] #[Title('Alt Watch - The Forge')] class extends Component
{
    #[Locked]
    public ?int $watchId = null;

    #[Url(as: 'user')]
    public ?int $watchedUserId = null;

    public string $search = '';

    /**
     * Keys of the ticked indicators.
     *
     * @var list<string>
     */
    public array $selected = [];

    public string $mode = 'any';

    public string $reason = '';

    /**
     * Days until the watch expires. Null on edit keeps the current expiry.
     */
    public ?int $days = 90;

    public function mount(?AltWatch $watch = null): void
    {
        abort_unless((bool) auth()->user()?->isAdmin(), 403, 'Access denied. Staff privileges required.');

        if ($watch instanceof AltWatch) {
            abort_if($watch->ended_at !== null, 404);

            $this->watchId = $watch->id;
            $this->watchedUserId = $watch->watched_user_id;
            $this->selected = array_values($watch->indicators()->get()->map(fn (AltWatchIndicator $indicator): string => $indicator->toIndicator()->key())->all());
            $this->mode = $watch->match_mode->value;
            $this->reason = $watch->reason;
            $this->days = null;

            return;
        }

        if ($this->watchedUserId !== null && ! User::query()->whereKey($this->watchedUserId)->exists()) {
            $this->watchedUserId = null;
        }
    }

    #[Computed]
    public function watch(): ?AltWatch
    {
        return $this->watchId === null ? null : AltWatch::query()->with('indicators')->find($this->watchId);
    }

    #[Computed]
    public function watchedUser(): ?User
    {
        return $this->watchedUserId === null ? null : User::query()->find($this->watchedUserId);
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function searchResults(): Collection
    {
        $term = mb_trim($this->search);

        if ($this->watchId !== null || $this->watchedUserId !== null || mb_strlen($term) < 2) {
            return new Collection;
        }

        return User::query()->lookup($term)->orderBy('name')->limit(10)->get();
    }

    /**
     * The values that can be ticked: the watched user's history, plus any ticked value that has since left it.
     *
     * @return list<AltIndicator>
     */
    #[Computed]
    public function indicators(): array
    {
        return array_map(AltIndicator::fromArray(...), $this->indicatorRows);
    }

    /**
     * The checklist as plain arrays, cached across requests because building it is expensive. The cache is configured
     * with serializable_classes = false, so a cached object would come back as __PHP_Incomplete_Class.
     *
     * @return list<array{type: string, value: string, label: string, first_seen: string|null, last_seen: string|null, shared_with: int|null}>
     */
    #[Computed(persist: true)]
    public function indicatorRows(): array
    {
        $user = $this->watchedUser;
        $indicators = $user instanceof User ? resolve(AltIndicatorService::class)->forUser($user) : [];
        $known = array_flip(array_map(static fn (AltIndicator $indicator): string => $indicator->key(), $indicators));

        foreach ($this->watch->indicators ?? [] as $stored) {
            $indicator = $stored->toIndicator();

            if (! isset($known[$indicator->key()])) {
                $indicators[] = $indicator;
            }
        }

        return array_map(static fn (AltIndicator $indicator): array => $indicator->toArray(), $indicators);
    }

    #[Computed]
    public function preview(): ?AltWatchPreview
    {
        $draft = $this->draft();

        return $draft->identifiers() === [] ? null : resolve(AltWatchMatchService::class)->preview($draft);
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function previewAccounts(): Collection
    {
        $ids = array_slice(array_keys($this->preview->matches ?? []), 0, 50);

        return User::query()->whereIn('id', $ids)->orderBy('name')->get();
    }

    /**
     * @return array<string, list<AltIndicator>> Indicators per type label, in type order
     */
    public function groupedIndicators(): array
    {
        $groups = [];

        foreach ($this->indicators as $indicator) {
            $groups[$indicator->type->label()][] = $indicator;
        }

        return $groups;
    }

    public function describe(AltIndicator $indicator): string
    {
        $parts = [];

        if ($indicator->lastSeen !== null && $indicator->firstSeen !== null) {
            $parts[] = sprintf('Seen %s to %s', CarbonImmutable::parse($indicator->firstSeen)->toFormattedDateString(), CarbonImmutable::parse($indicator->lastSeen)->toFormattedDateString());
        }

        if ($indicator->sharedWith !== null) {
            $parts[] = $indicator->sharedWith === 0
                ? 'No other account'
                : sprintf('Shared by %d other %s', $indicator->sharedWith, Str::plural('account', $indicator->sharedWith));
        }

        if (($indicator->sharedWith ?? 0) > AltIndicatorService::NOISY_ACCOUNT_THRESHOLD) {
            $parts[] = 'Widely shared: expect noise';
        }

        return implode(' · ', $parts);
    }

    /**
     * @return list<int>
     */
    public function expiryOptions(): array
    {
        return [30, 90, 180, 365];
    }

    public function selectUser(int $userId): void
    {
        if (User::query()->whereKey($userId)->exists()) {
            $this->redirect(route('admin.alt-monitoring.watches.create', ['user' => $userId]), navigate: true);
        }
    }

    public function clearUser(): void
    {
        $this->redirect(route('admin.alt-monitoring.watches.create'), navigate: true);
    }

    public function save(): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->isAdmin(), 403);

        if ($this->watchId !== null && ! $this->watch instanceof AltWatch) {
            abort(404);
        }

        abort_if($this->watch?->ended_at !== null, 404);

        $this->validate([
            'reason' => ['required', 'string', 'max:500'],
            'mode' => ['required', Rule::enum(AltWatchMatchMode::class)],
            'days' => [$this->watchId === null ? 'required' : 'nullable', 'integer', Rule::in($this->expiryOptions())],
            'selected' => ['array'],
        ]);

        $user = $this->watchedUser;
        $watch = $this->watch;

        if (! $watch instanceof AltWatch && ! $user instanceof User) {
            $this->addError('selected', 'Pick a user to watch.');

            return;
        }

        $draft = $this->draft();

        if ($draft->identifiers() === []) {
            $this->addError('selected', 'Tick at least one device, IP address, IP range or email domain.');

            return;
        }

        $watch ??= new AltWatch([
            'watched_user_id' => $user->id,
            'watched_user_name' => $user->name,
            'created_by' => $actor->id,
        ]);
        $watch->reason = $this->reason;

        try {
            $watch = resolve(SaveAltWatch::class)->handle($watch, $draft, $this->days);
        } catch (AltWatchTooBroadException) {
            $this->addError('selected', 'This watch matches too many accounts to be useful. Narrow it down.');

            return;
        }

        Flux::toast(text: 'Watch saved.', variant: 'success');

        $this->redirect(route('admin.alt-monitoring.watches.show', $watch), navigate: true);
    }

    private function draft(): AltWatchDraft
    {
        $ticked = array_flip(array_filter($this->selected, is_string(...)));

        return new AltWatchDraft(
            $this->watchedUserId,
            AltWatchMatchMode::tryFrom($this->mode) ?? AltWatchMatchMode::Any,
            array_values(array_filter($this->indicators, static fn (AltIndicator $indicator): bool => isset($ticked[$indicator->key()]))),
        );
    }
};
