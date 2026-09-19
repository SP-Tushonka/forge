<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A source feeding the mod stats dashboard. The value doubles as the mod_daily_stats column the source writes.
 */
enum ModStatsSource: string
{
    case Downloads = 'downloads';
    case Views = 'views';
}
