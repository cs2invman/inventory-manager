<?php

namespace App\DTO;

class ImportResult
{
    public function __construct(
        public readonly int $totalProcessed,
        public readonly int $successCount,
        public readonly int $errorCount,
        public readonly array $errors,
        public readonly array $skippedItems,
        public readonly int $addedCount = 0,
        public readonly int $removedCount = 0,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->errorCount === 0 && $this->successCount > 0;
    }

    public function hasSkippedItems(): bool
    {
        return !empty($this->skippedItems);
    }

    public function toArray(): array
    {
        return [
            'total_processed' => $this->totalProcessed,
            'success_count' => $this->successCount,
            'error_count' => $this->errorCount,
            'errors' => $this->errors,
            'skipped_items' => $this->skippedItems,
            'added_count' => $this->addedCount,
            'removed_count' => $this->removedCount,
        ];
    }
}