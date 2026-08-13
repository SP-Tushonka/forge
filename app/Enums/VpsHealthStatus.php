<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The severity of a single VPS health finding, and the overall verdict for the host.
 *
 * Unknown is deliberately distinct from Ok: a reading that could not be taken must never render as a healthy one.
 */
enum VpsHealthStatus: string
{
    /**
     * Measured, and within its threshold.
     */
    case Ok = 'ok';

    /**
     * The reading failed or has not been collected yet. Nothing can be concluded from it.
     */
    case Unknown = 'unknown';

    /**
     * Measured and outside its comfortable range, but not yet an emergency.
     */
    case Warning = 'warning';

    /**
     * Measured and breached. Needs attention now.
     */
    case Critical = 'critical';

    /**
     * Fold a set of statuses into the single worst one. An empty set is healthy.
     *
     * @param  iterable<self>  $statuses
     */
    public static function worst(iterable $statuses): self
    {
        $worst = self::Ok;

        foreach ($statuses as $status) {
            if ($status->rank() > $worst->rank()) {
                $worst = $status;
            }
        }

        return $worst;
    }

    /**
     * Ordering used to rank findings and to fold many statuses into one verdict. Higher wins.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Unknown => 1,
            self::Warning => 2,
            self::Critical => 3,
        };
    }

    /**
     * Get a human-readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Ok => 'Healthy',
            self::Unknown => 'Unknown',
            self::Warning => 'Needs attention',
            self::Critical => 'Critical',
        };
    }

    /**
     * Get the Flux colour for this status.
     */
    public function color(): string
    {
        return match ($this) {
            self::Ok => 'green',
            self::Unknown => 'zinc',
            self::Warning => 'amber',
            self::Critical => 'red',
        };
    }

    /**
     * Get the Heroicon name for this status.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Ok => 'check-circle',
            self::Unknown => 'question-mark-circle',
            self::Warning => 'exclamation-triangle',
            self::Critical => 'exclamation-circle',
        };
    }

    /**
     * Tailwind text colour used for inline metric values.
     */
    public function textClass(): string
    {
        return match ($this) {
            self::Ok => 'text-green-400',
            self::Unknown => 'text-gray-500',
            self::Warning => 'text-amber-400',
            self::Critical => 'text-red-400',
        };
    }
}
