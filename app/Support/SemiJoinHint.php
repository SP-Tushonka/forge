<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * Select expression for a correlated `EXISTS` subquery, carrying MySQL's FIRSTMATCH semi-join hint.
 *
 * MySQL optimization that improves performance in some cases up to 20x. This is a hint for the DB,
 * not a turn on/off setting.
 */
final class SemiJoinHint
{
    /**
     * The constant `1` an EXISTS subquery selects, hinted to use the FIRSTMATCH semi-join strategy.
     *
     * Only MySQL understands the hint. Other drivers would read it as a comment and ignore it, but it is emitted
     * solely for MySQL so the generated SQL states what it means on the connection that acts on it.
     */
    public static function firstMatch(): Expression
    {
        return DB::connection()->getDriverName() === 'mysql'
            ? DB::raw('/*+ SEMIJOIN(FIRSTMATCH) */ 1')
            : DB::raw('1');
    }
}
