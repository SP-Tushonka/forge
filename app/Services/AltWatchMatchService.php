<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AltIndicatorType;
use App\Enums\AltWatchMatchMode;
use App\Models\User;
use App\Support\DataTransferObjects\AltIndicator;
use App\Support\DataTransferObjects\AltIndicatorSet;
use App\Support\DataTransferObjects\AltWatchDraft;
use App\Support\DataTransferObjects\AltWatchPreview;

/**
 * Applies a watch's rule: the ticked identifiers (any or all of them, by the watch's mode) and every ticked qualifier
 * must appear in an account's own activity.
 */
final readonly class AltWatchMatchService
{
    private const int MAX_CANDIDATES = 500;

    public function __construct(
        private AltIndicatorService $indicators,
    ) {}

    /**
     * The keys of the indicators the account matched, or null when it does not match the rule.
     *
     * @return list<string>|null
     */
    public function matchedKeys(AltWatchDraft $draft, AltIndicatorSet $set): ?array
    {
        $identifiers = $draft->identifiers();
        $hits = array_values(array_filter($identifiers, static fn (AltIndicator $indicator): bool => $set->has($indicator->type, $indicator->value)));

        if (! $this->identifiersPass($draft->mode, count($hits), count($identifiers))) {
            return null;
        }

        foreach ($draft->qualifiers() as $qualifier) {
            if (! $set->has($qualifier->type, $qualifier->value)) {
                return null;
            }
        }

        return array_map(static fn (AltIndicator $indicator): string => $indicator->key(), [...$hits, ...$draft->qualifiers()]);
    }

    /**
     * The accounts matching the draft today, found through its identifiers so no unindexed column is scanned.
     */
    public function preview(AltWatchDraft $draft): AltWatchPreview
    {
        $identifiers = $draft->identifiers();

        /** @var array<int, list<string>> $hits */
        $hits = [];

        foreach ($identifiers as $identifier) {
            foreach ($this->indicators->accountsWith($identifier->type, $identifier->value) as $userId) {
                if ($userId !== $draft->watchedUserId) {
                    $hits[$userId][] = $identifier->key();
                }
            }
        }

        $truncated = count($hits) > self::MAX_CANDIDATES;
        $hits = array_slice($hits, 0, self::MAX_CANDIDATES, true);
        $hits = array_filter($hits, fn (array $keys): bool => $this->identifiersPass($draft->mode, count($keys), count($identifiers)));
        $hits = $this->existingAccounts($hits);

        if ($truncated) {
            return new AltWatchPreview($hits, true);
        }

        $qualifiers = $draft->qualifiers();

        if ($qualifiers !== [] && $hits !== []) {
            $sets = $this->indicators->forUsers(array_keys($hits), array_map(static fn (AltIndicator $indicator): AltIndicatorType => $indicator->type, $qualifiers));
            $qualifierKeys = array_map(static fn (AltIndicator $indicator): string => $indicator->key(), $qualifiers);

            foreach ($hits as $userId => $keys) {
                $set = $sets[$userId] ?? new AltIndicatorSet;

                foreach ($qualifiers as $qualifier) {
                    if (! $set->has($qualifier->type, $qualifier->value)) {
                        unset($hits[$userId]);

                        continue 2;
                    }
                }

                $hits[$userId] = [...$keys, ...$qualifierKeys];
            }
        }

        return new AltWatchPreview($hits, false);
    }

    /**
     * Activity rows outlive a deleted account until the nightly prune, but a match must reference an existing user.
     *
     * @param  array<int, list<string>>  $hits
     * @return array<int, list<string>>
     */
    private function existingAccounts(array $hits): array
    {
        if ($hits === []) {
            return [];
        }

        $existing = User::query()->whereIn('id', array_keys($hits))->pluck('id')->all();

        return array_intersect_key($hits, array_flip(array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $existing)));
    }

    private function identifiersPass(AltWatchMatchMode $mode, int $hits, int $identifiers): bool
    {
        if ($identifiers === 0) {
            return false;
        }

        return $mode === AltWatchMatchMode::Any ? $hits > 0 : $hits === $identifiers;
    }
}
