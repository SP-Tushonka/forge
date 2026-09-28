<?php

declare(strict_types=1);

namespace App\Support\DataTransferObjects;

use App\Enums\AltIndicatorType;
use App\Support\DataTransferObjects\Concerns\CoercesArrayValues;

/**
 * One value from a user's activity that a watch can look for.
 */
final readonly class AltIndicator
{
    use CoercesArrayValues;

    public function __construct(
        public AltIndicatorType $type,
        public string $value,
        public string $label,
        public ?string $firstSeen = null,
        public ?string $lastSeen = null,
        public ?int $sharedWith = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $sharedWith = $data['shared_with'] ?? null;

        return new self(
            type: AltIndicatorType::from(self::coerceString($data['type'] ?? null)),
            value: self::coerceString($data['value'] ?? null),
            label: self::coerceString($data['label'] ?? null),
            firstSeen: self::coerceNullableString($data['first_seen'] ?? null),
            lastSeen: self::coerceNullableString($data['last_seen'] ?? null),
            sharedWith: is_numeric($sharedWith) ? (int) $sharedWith : null,
        );
    }

    /**
     * @return array{type: string, value: string, label: string, first_seen: string|null, last_seen: string|null, shared_with: int|null}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'value' => $this->value,
            'label' => $this->label,
            'first_seen' => $this->firstSeen,
            'last_seen' => $this->lastSeen,
            'shared_with' => $this->sharedWith,
        ];
    }

    /**
     * A stable key that is safe to put in markup, used to tick the value in the watch form.
     */
    public function key(): string
    {
        return hash('sha256', $this->type->value.'|'.$this->value);
    }
}
