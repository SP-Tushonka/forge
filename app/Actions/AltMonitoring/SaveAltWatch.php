<?php

declare(strict_types=1);

namespace App\Actions\AltMonitoring;

use App\Exceptions\AltWatchTooBroadException;
use App\Models\AltWatch;
use App\Services\AltWatchMatchService;
use App\Support\DataTransferObjects\AltIndicator;
use App\Support\DataTransferObjects\AltWatchDraft;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates or edits a watch. Accounts that already match when it is saved are recorded as baseline matches without an
 * alert, so the watch only alerts on accounts that match later.
 */
final readonly class SaveAltWatch
{
    public function __construct(
        private AltWatchMatchService $matcher,
    ) {}

    /**
     * @param  AltWatch  $watch  A new watch, or an existing one, with its reason (and for a new one its owner fields) filled in
     * @param  int|null  $days  Days until the watch expires, counted from now; null keeps the current expiry
     *
     * @throws AltWatchTooBroadException
     */
    public function handle(AltWatch $watch, AltWatchDraft $draft, ?int $days): AltWatch
    {
        $preview = $this->matcher->preview($draft);

        if ($preview->tooBroad()) {
            throw AltWatchTooBroadException::matching($preview);
        }

        return DB::transaction(function () use ($watch, $draft, $days, $preview): AltWatch {
            if ($days !== null) {
                $watch->expires_at = CarbonImmutable::now()->addDays($days);
            }

            $watch->match_mode = $draft->mode;
            $watch->save();

            $idsByKey = $this->syncIndicators($watch, $draft->indicators);
            $this->recordBaseline($watch, $preview->matches, $idsByKey);

            return $watch;
        });
    }

    /**
     * Keep the indicators still ticked, so match rows keep pointing at them; drop the rest and add the new ones.
     *
     * @param  list<AltIndicator>  $indicators
     * @return array<string, int> Indicator ids by AltIndicator key
     */
    private function syncIndicators(AltWatch $watch, array $indicators): array
    {
        $wanted = [];

        foreach ($indicators as $indicator) {
            $wanted[$indicator->key()] = $indicator;
        }

        $idsByKey = [];

        foreach ($watch->indicators()->get() as $existing) {
            $key = $existing->toIndicator()->key();

            if (isset($wanted[$key])) {
                $idsByKey[$key] = $existing->id;
                unset($wanted[$key]);

                continue;
            }

            $existing->delete();
        }

        foreach ($wanted as $key => $indicator) {
            $idsByKey[$key] = $watch->indicators()->create([
                'type' => $indicator->type,
                'value' => $indicator->value,
                'label' => Str::limit($indicator->label, 250),
            ])->id;
        }

        $watch->unsetRelation('indicators');

        return $idsByKey;
    }

    /**
     * @param  array<int, list<string>>  $matches
     * @param  array<string, int>  $idsByKey
     */
    private function recordBaseline(AltWatch $watch, array $matches, array $idsByKey): void
    {
        $known = array_fill_keys(array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $watch->matches()->pluck('user_id')->all()), true);
        $now = now();

        foreach ($matches as $userId => $keys) {
            if (isset($known[$userId])) {
                continue;
            }

            $watch->matches()->create([
                'user_id' => $userId,
                'matched_indicator_ids' => array_map(static fn (string $key): int => $idsByKey[$key], $keys),
                'baseline' => true,
                'first_matched_at' => $now,
                'last_matched_at' => $now,
            ]);
        }
    }
}
