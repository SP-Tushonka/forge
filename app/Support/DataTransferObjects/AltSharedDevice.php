<?php

declare(strict_types=1);

namespace App\Support\DataTransferObjects;

use App\Support\DataTransferObjects\Concerns\CoercesArrayValues;

/**
 * A browser, identified by its device cookie, that both the suspect and a candidate were signed in from.
 */
final readonly class AltSharedDevice
{
    use CoercesArrayValues;

    /**
     * @param  list<string>  $otherAccounts
     */
    public function __construct(
        public string $label,
        public int $breadth,
        public string $firstSeen,
        public string $lastSeen,
        public array $otherAccounts,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            label: self::coerceString($data['label'] ?? null),
            breadth: self::coerceInt($data['breadth'] ?? null),
            firstSeen: self::coerceString($data['first_seen'] ?? null),
            lastSeen: self::coerceString($data['last_seen'] ?? null),
            otherAccounts: self::coerceStringList($data['other_accounts'] ?? null),
        );
    }

    /**
     * @return array{label: string, breadth: int, first_seen: string, last_seen: string, other_accounts: list<string>}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'breadth' => $this->breadth,
            'first_seen' => $this->firstSeen,
            'last_seen' => $this->lastSeen,
            'other_accounts' => $this->otherAccounts,
        ];
    }
}
