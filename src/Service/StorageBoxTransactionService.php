<?php

namespace App\Service;

use App\DTO\DepositPreview;
use App\DTO\WithdrawPreview;
use App\DTO\TransactionResult;
use App\Entity\ItemUser;
use App\Entity\StorageBox;
use App\Entity\User;
use App\Repository\ItemUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class StorageBoxTransactionService
{
    private const SESSION_PREFIX = 'storage_transaction_';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ItemUserRepository $itemUserRepository,
        private InventoryImportService $inventoryImportService,
        private RequestStack $requestStack,
        private LoggerInterface $logger
    ) {}

    /**
     * Prepare deposit preview by comparing current inventory with new snapshot
     */
    public function prepareDepositPreview(User $user, StorageBox $box, string $tradeableJson, string $tradeLockedJson): DepositPreview
    {
        $errors = [];

        // Parse both JSON inputs and merge items
        $newSnapshotItems = [];

        if (!empty($tradeableJson)) {
            try {
                $tradeableData = json_decode($tradeableJson, true, 512, JSON_THROW_ON_ERROR);
                $parsedTradeable = $this->inventoryImportService->parseInventoryResponse($tradeableData);
                $newSnapshotItems = array_merge($newSnapshotItems, $parsedTradeable);
            } catch (\JsonException $e) {
                return new DepositPreview(
                    itemsToDeposit: [],
                    currentItemCount: $box->getItemCount(),
                    newItemCount: $box->getItemCount(),
                    errors: ['Invalid tradeable JSON: ' . $e->getMessage()],
                    sessionKey: ''
                );
            }
        }

        if (!empty($tradeLockedJson)) {
            try {
                $tradeLockedData = json_decode($tradeLockedJson, true, 512, JSON_THROW_ON_ERROR);
                $parsedTradeLocked = $this->inventoryImportService->parseInventoryResponse($tradeLockedData);
                $newSnapshotItems = array_merge($newSnapshotItems, $parsedTradeLocked);
            } catch (\JsonException $e) {
                return new DepositPreview(
                    itemsToDeposit: [],
                    currentItemCount: $box->getItemCount(),
                    newItemCount: $box->getItemCount(),
                    errors: ['Invalid trade-locked JSON: ' . $e->getMessage()],
                    sessionKey: ''
                );
            }
        }

        // Validate we have at least one JSON
        if (empty($newSnapshotItems)) {
            return new DepositPreview(
                itemsToDeposit: [],
                currentItemCount: $box->getItemCount(),
                newItemCount: $box->getItemCount(),
                errors: ['Please provide at least one JSON file (tradeable or trade-locked)'],
                sessionKey: ''
            );
        }

        // Get current active inventory (items NOT in any storage box)
        $currentActiveItems = $this->itemUserRepository->findBy([
            'user' => $user,
            'storageBox' => null
        ]);

        // Compare: find items that disappeared (were deposited)
        $itemsToDeposit = $this->findDisappearedItems($currentActiveItems, $newSnapshotItems);

        $currentItemCount = $box->getItemCount();
        $newItemCount = $currentItemCount + count($itemsToDeposit);

        // Store in session
        $sessionKey = $this->storeTransactionInSession([
            'type' => 'deposit',
            'storage_box_id' => $box->getId(),
            'items_to_move' => array_map(fn($item) => $item->getId(), $itemsToDeposit)
        ]);

        return new DepositPreview(
            itemsToDeposit: $itemsToDeposit,
            currentItemCount: $currentItemCount,
            newItemCount: $newItemCount,
            errors: $errors,
            sessionKey: $sessionKey
        );
    }

    /**
     * Prepare withdraw preview by comparing current inventory with new snapshot
     */
    public function prepareWithdrawPreview(User $user, StorageBox $box, string $tradeableJson, string $tradeLockedJson): WithdrawPreview
    {
        $errors = [];

        // Parse both JSON inputs and merge items
        $newSnapshotItems = [];

        if (!empty($tradeableJson)) {
            try {
                $tradeableData = json_decode($tradeableJson, true, 512, JSON_THROW_ON_ERROR);
                $parsedTradeable = $this->inventoryImportService->parseInventoryResponse($tradeableData);
                $newSnapshotItems = array_merge($newSnapshotItems, $parsedTradeable);
            } catch (\JsonException $e) {
                return new WithdrawPreview(
                    itemsToWithdraw: [],
                    currentItemCount: $box->getItemCount(),
                    newItemCount: $box->getItemCount(),
                    errors: ['Invalid tradeable JSON: ' . $e->getMessage()],
                    sessionKey: ''
                );
            }
        }

        if (!empty($tradeLockedJson)) {
            try {
                $tradeLockedData = json_decode($tradeLockedJson, true, 512, JSON_THROW_ON_ERROR);
                $parsedTradeLocked = $this->inventoryImportService->parseInventoryResponse($tradeLockedData);
                $newSnapshotItems = array_merge($newSnapshotItems, $parsedTradeLocked);
            } catch (\JsonException $e) {
                return new WithdrawPreview(
                    itemsToWithdraw: [],
                    currentItemCount: $box->getItemCount(),
                    newItemCount: $box->getItemCount(),
                    errors: ['Invalid trade-locked JSON: ' . $e->getMessage()],
                    sessionKey: ''
                );
            }
        }

        // Validate we have at least one JSON
        if (empty($newSnapshotItems)) {
            return new WithdrawPreview(
                itemsToWithdraw: [],
                currentItemCount: $box->getItemCount(),
                newItemCount: $box->getItemCount(),
                errors: ['Please provide at least one JSON file (tradeable or trade-locked)'],
                sessionKey: ''
            );
        }

        // Get current active inventory
        $currentActiveItems = $this->itemUserRepository->findBy([
            'user' => $user,
            'storageBox' => null
        ]);

        // Compare: find items that appeared (were withdrawn)
        $itemsToWithdraw = $this->findAppearedItems($currentActiveItems, $newSnapshotItems, $box);

        $currentItemCount = $box->getItemCount();
        $newItemCount = $currentItemCount - count($itemsToWithdraw);

        // Store in session
        $sessionKey = $this->storeTransactionInSession([
            'type' => 'withdraw',
            'storage_box_id' => $box->getId(),
            'items_to_move' => array_map(fn($item) => $item->getId(), $itemsToWithdraw)
        ]);

        return new WithdrawPreview(
            itemsToWithdraw: $itemsToWithdraw,
            currentItemCount: $currentItemCount,
            newItemCount: $newItemCount,
            errors: $errors,
            sessionKey: $sessionKey
        );
    }

    /**
     * Execute deposit transaction
     */
    public function executeDeposit(User $user, StorageBox $box, string $sessionKey): TransactionResult
    {
        $transactionData = $this->retrieveTransactionFromSession($sessionKey);

        if ($transactionData === null || $transactionData['type'] !== 'deposit') {
            return new TransactionResult(
                itemsMoved: 0,
                success: false,
                errors: ['Transaction data not found or expired']
            );
        }

        $itemIds = $transactionData['items_to_move'];
        $itemsMoved = 0;
        $errors = [];

        $this->entityManager->beginTransaction();

        try {
            foreach ($itemIds as $itemId) {
                $itemUser = $this->itemUserRepository->find($itemId);

                if ($itemUser === null) {
                    $errors[] = "Item with ID {$itemId} not found";
                    continue;
                }

                if ($itemUser->getUser()->getId() !== $user->getId()) {
                    $errors[] = "Unauthorized: Item {$itemId} does not belong to user";
                    continue;
                }

                // Set storage box
                $itemUser->setStorageBox($box);
                $this->entityManager->persist($itemUser);
                $itemsMoved++;
            }

            // Update storage box item count
            $actualCount = $this->itemUserRepository->count(['storageBox' => $box]);
            $box->setItemCount($actualCount);
            $this->entityManager->persist($box);

            $this->entityManager->flush();
            $this->entityManager->commit();

            // Clear session
            $this->clearTransactionFromSession($sessionKey);

            $this->logger->info('Deposit completed', [
                'user_id' => $user->getId(),
                'storage_box_id' => $box->getId(),
                'items_moved' => $itemsMoved
            ]);

            return new TransactionResult(
                itemsMoved: $itemsMoved,
                success: true,
                errors: $errors
            );
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $this->logger->error('Deposit failed', [
                'error' => $e->getMessage(),
                'user_id' => $user->getId(),
                'storage_box_id' => $box->getId()
            ]);

            return new TransactionResult(
                itemsMoved: 0,
                success: false,
                errors: ['Transaction failed: ' . $e->getMessage()]
            );
        }
    }

    /**
     * Execute withdraw transaction
     */
    public function executeWithdraw(User $user, StorageBox $box, string $sessionKey): TransactionResult
    {
        $transactionData = $this->retrieveTransactionFromSession($sessionKey);

        if ($transactionData === null || $transactionData['type'] !== 'withdraw') {
            return new TransactionResult(
                itemsMoved: 0,
                success: false,
                errors: ['Transaction data not found or expired']
            );
        }

        $itemIds = $transactionData['items_to_move'];
        $itemsMoved = 0;
        $errors = [];

        $this->entityManager->beginTransaction();

        try {
            foreach ($itemIds as $itemId) {
                $itemUser = $this->itemUserRepository->find($itemId);

                if ($itemUser === null) {
                    $errors[] = "Item with ID {$itemId} not found";
                    continue;
                }

                if ($itemUser->getUser()->getId() !== $user->getId()) {
                    $errors[] = "Unauthorized: Item {$itemId} does not belong to user";
                    continue;
                }

                // Clear storage box (move to active inventory)
                $itemUser->setStorageBox(null);
                $this->entityManager->persist($itemUser);
                $itemsMoved++;
            }

            // Update storage box item count
            $actualCount = $this->itemUserRepository->count(['storageBox' => $box]);
            $box->setItemCount($actualCount);
            $this->entityManager->persist($box);

            $this->entityManager->flush();
            $this->entityManager->commit();

            // Clear session
            $this->clearTransactionFromSession($sessionKey);

            $this->logger->info('Withdraw completed', [
                'user_id' => $user->getId(),
                'storage_box_id' => $box->getId(),
                'items_moved' => $itemsMoved
            ]);

            return new TransactionResult(
                itemsMoved: $itemsMoved,
                success: true,
                errors: $errors
            );
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $this->logger->error('Withdraw failed', [
                'error' => $e->getMessage(),
                'user_id' => $user->getId(),
                'storage_box_id' => $box->getId()
            ]);

            return new TransactionResult(
                itemsMoved: 0,
                success: false,
                errors: ['Transaction failed: ' . $e->getMessage()]
            );
        }
    }

    /**
     * Find items that disappeared (were deposited into storage)
     */
    private function findDisappearedItems(array $currentItems, array $newSnapshotItems): array
    {
        $newSnapshotAssetIds = array_column($newSnapshotItems, 'asset_id');
        $disappeared = [];

        foreach ($currentItems as $currentItem) {
            if (!in_array($currentItem->getAssetId(), $newSnapshotAssetIds, true)) {
                $disappeared[] = $currentItem;
            }
        }

        return $disappeared;
    }

    /**
     * Find items that appeared (were withdrawn from storage)
     */
    private function findAppearedItems(array $currentActiveItems, array $newSnapshotItems, StorageBox $box): array
    {
        $currentAssetIds = array_map(fn($item) => $item->getAssetId(), $currentActiveItems);
        $appeared = [];

        // Get items currently in this storage box
        $itemsInBox = $this->itemUserRepository->findBy(['storageBox' => $box]);

        foreach ($newSnapshotItems as $snapshotItem) {
            $snapshotAssetId = $snapshotItem['asset_id'];

            // If item appeared in active inventory (not there before)
            if (!in_array($snapshotAssetId, $currentAssetIds, true)) {
                // Find matching item in storage box
                $matchedItem = $this->matchItemInStorage($snapshotItem, $itemsInBox);
                if ($matchedItem) {
                    $appeared[] = $matchedItem;
                }
            }
        }

        return $appeared;
    }

    /**
     * Match snapshot item to item in storage box (handles assetId changes)
     */
    private function matchItemInStorage(array $snapshotItem, array $itemsInBox): ?ItemUser
    {
        $snapshotAssetId = $snapshotItem['asset_id'];
        $snapshotHashName = $snapshotItem['market_hash_name'];
        $snapshotFloat = $snapshotItem['float_value'] ?? null;
        $snapshotPattern = $snapshotItem['pattern_index'] ?? null;

        foreach ($itemsInBox as $item) {
            // Try exact assetId match first
            if ($item->getAssetId() === $snapshotAssetId) {
                return $item;
            }

            // Try property match (for assetId changes)
            if ($item->getItem()->getMarketHashName() === $snapshotHashName) {
                $floatMatch = ($snapshotFloat === null || abs((float)$item->getFloatValue() - $snapshotFloat) < 0.0000001);
                $patternMatch = ($snapshotPattern === null || $item->getPatternIndex() === $snapshotPattern);

                if ($floatMatch && $patternMatch) {
                    // Update assetId if it changed
                    if ($item->getAssetId() !== $snapshotAssetId) {
                        $this->logger->info('AssetId change detected', [
                            'old_asset_id' => $item->getAssetId(),
                            'new_asset_id' => $snapshotAssetId,
                            'item_name' => $snapshotHashName
                        ]);
                        $item->setAssetId($snapshotAssetId);
                        $this->entityManager->persist($item);
                    }
                    return $item;
                }
            }
        }

        return null;
    }

    private function storeTransactionInSession(array $data): string
    {
        $session = $this->requestStack->getSession();
        $sessionKey = self::SESSION_PREFIX . bin2hex(random_bytes(16));
        $session->set($sessionKey, $data);
        return $sessionKey;
    }

    private function retrieveTransactionFromSession(string $sessionKey): ?array
    {
        $session = $this->requestStack->getSession();
        return $session->get($sessionKey);
    }

    private function clearTransactionFromSession(string $sessionKey): void
    {
        $session = $this->requestStack->getSession();
        $session->remove($sessionKey);
    }
}
