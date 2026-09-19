<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How the mod stats charts bucket days: one point per UTC day, or one per ISO week (Monday start).
 */
enum StatsGrain: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
}
