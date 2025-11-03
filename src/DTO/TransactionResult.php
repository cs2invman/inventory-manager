<?php

namespace App\DTO;

readonly class TransactionResult
{
    public function __construct(
        public int $itemsMoved,            // Number of items moved
        public bool $success,              // Overall success status
        public array $errors,              // Any errors encountered
    ) {}

    public function isSuccess(): bool
    {
        return $this->success && empty($this->errors);
    }
}
