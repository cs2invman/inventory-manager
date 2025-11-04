<?php

namespace App\DTO;

class ImportPreview
{
    public function __construct(
        public readonly int $totalItems,
        public readonly int $itemsToAdd,
        public readonly int $itemsToRemove,
        public readonly array $itemsToAddData,
        public readonly array $itemsToRemoveData,
        public readonly array $unmatchedItems,
        public readonly array $errors,
        public readonly string $sessionKey,
        public readonly int $storageBoxCount = 0,
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
            'items_to_add_data' => $this->itemsToAddData,
            'items_to_remove_data' => $this->itemsToRemoveData,
            'unmatched_items' => $this->unmatchedItems,
            'errors' => $this->errors,
            'session_key' => $this->sessionKey,
            'storage_box_count' => $this->storageBoxCount,
        ];
    }
}