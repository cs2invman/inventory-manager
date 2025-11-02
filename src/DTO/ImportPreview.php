<?php

namespace App\DTO;

class ImportPreview
{
    public function __construct(
        public readonly int $totalItems,
        public readonly int $itemsToAdd,
        public readonly int $itemsToRemove,
        public readonly array $statsByRarity,
        public readonly array $statsByType,
        public readonly array $notableItems,
        public readonly array $unmatchedItems,
        public readonly array $errors,
        public readonly string $sessionKey,
    ) {
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    public function hasUnmatchedItems(): bool
    {
        return !empty($this->unmatchedItems);
    }

    public function toArray(): array
    {
        return [
            'total_items' => $this->totalItems,
            'items_to_add' => $this->itemsToAdd,
            'items_to_remove' => $this->itemsToRemove,
            'stats_by_rarity' => $this->statsByRarity,
            'stats_by_type' => $this->statsByType,
            'notable_items' => $this->notableItems,
            'unmatched_items' => $this->unmatchedItems,
            'errors' => $this->errors,
            'session_key' => $this->sessionKey,
        ];
    }
}