<?php

namespace App\DTO;

readonly class DepositPreview
{
    public function __construct(
        public array $itemsToDeposit,      // Items that will move to storage
        public int $currentItemCount,      // Current count in storage box
        public int $newItemCount,          // New count after deposit
        public array $errors,              // Any errors encountered
        public string $sessionKey,         // Session key for confirmation
    ) {}

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }
}
