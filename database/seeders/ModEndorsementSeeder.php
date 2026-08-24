<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Mod;
use App\Models\ModEndorsement;
use App\Models\User;
use Database\Factories\ModEndorsementFactory;
use Database\Seeders\Traits\SeederHelpers;
use Illuminate\Database\Seeder;

final class ModEndorsementSeeder extends Seeder
{
    use SeederHelpers;

    private const int MAX_ENDORSERS_PER_MOD = 12;

    private const int RECENT_WINDOW_DAYS = 7;

    private const int MONTHLY_WINDOW_DAYS = 30;

    private const int REVOKED_PERCENT = 10;

    private const int INSERT_CHUNK = 1000;

    /**
     * Run the database seeds.
     *
     * Nothing here touches mods.endorsements_count: the counter is maintained by a query-builder write on the click
     * path, not by an observer, and DatabaseSeeder runs WithoutModelEvents anyway. DatabaseSeeder calls
     * app:update-endorsements at its tail to reconcile the counters from these rows.
     */
    public function run(): void
    {
        /** @var list<int> $modIds */
        $modIds = Mod::query()->pluck('id')->all();
        /** @var list<int> $userIds */
        $userIds = User::query()->pluck('id')->all();

        if ($modIds === [] || $userIds === []) {
            return;
        }

        $maxEndorsers = min(self::MAX_ENDORSERS_PER_MOD, count($userIds));
        $factory = ModEndorsement::factory();

        $rows = [];

        foreach ($modIds as $modId) {
            $endorserCount = random_int(0, $maxEndorsers);

            if ($endorserCount === 0) {
                continue;
            }

            // Distinct users per mod, because mod_endorsements is unique on (user_id, mod_id).
            foreach ($this->randomElements($userIds, $endorserCount) as $userId) {
                $rows[] = $this->buildEndorsement($factory, $userId, $modId);
            }
        }

        $this->bulkInsert('mod_endorsements', $rows, self::INSERT_CHUNK);
    }

    /**
     * Build one endorsement row, anchored so that the 7 day, 30 day and all time leaderboards genuinely differ.
     *
     * @return array<string, mixed>
     */
    private function buildEndorsement(ModEndorsementFactory $factory, int $userId, int $modId): array
    {
        $state = match (random_int(1, 10)) {
            1, 2 => $factory->within(self::RECENT_WINDOW_DAYS),
            3, 4, 5 => $factory->within(self::MONTHLY_WINDOW_DAYS),
            default => $factory,
        };

        if (random_int(1, 100) <= self::REVOKED_PERCENT) {
            $state = $state->revoked();
        }

        $endorsement = $state->makeOne(['user_id' => $userId, 'mod_id' => $modId]);

        // revoked() picks a withdrawal date independently of the anchor, so it can land before it. Leave the
        // endorsement standing rather than seed a withdrawal that predates the endorsement it withdrew.
        if ($endorsement->revoked_at?->lessThan($endorsement->endorsed_at) === true) {
            $endorsement->revoked_at = null;
        }

        return $endorsement->getAttributes();
    }
}
