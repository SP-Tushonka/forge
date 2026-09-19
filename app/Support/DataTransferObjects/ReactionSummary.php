<?php

declare(strict_types=1);

namespace App\Support\DataTransferObjects;

/**
 * One page's worth of reaction data, keyed by reactable id, so a card can be handed its slice without another query.
 */
final readonly class ReactionSummary
{
    /**
     * @param  array<int, array<int, int>>  $counts  reactable id => emoji id => count
     * @param  array<int, list<int>>  $mine  reactable id => emoji ids the viewer picked
     */
    public function __construct(
        public array $counts = [],
        public array $mine = [],
    ) {}

    /**
     * @return array<int, int>
     */
    public function countsFor(int $reactableId): array
    {
        return $this->counts[$reactableId] ?? [];
    }

    /**
     * @return list<int>
     */
    public function mineFor(int $reactableId): array
    {
        return $this->mine[$reactableId] ?? [];
    }
}
