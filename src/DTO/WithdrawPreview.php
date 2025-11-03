<?php

namespace App\DTO;

readonly class WithdrawPreview
{
    public function __construct(
        public array $itemsToWithdraw,     // Items that will move to inventory
        public int $currentItemCount,      // Current count in storage box
        public int $newItemCount,          // New count after withdrawal
        public array $errors,              // Any errors encountered
        public string $sessionKey,         // Session key for confirmation
    ) {}

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }
}
