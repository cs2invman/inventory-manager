# Deposit/Withdraw Workflow

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Large
**Created**: 2025-11-02
**Part of**: Task 2 - Storage Box Management System (Phase 4)

## Overview

Implement the deposit and withdraw workflows that allow users to move items in and out of storage boxes using JSON snapshot comparison.

## Prerequisites

- Task 2-1 (Storage Box Database Setup) must be completed
- Task 2-2 (Storage Box Import Integration) must be completed
- Task 2-3 (Storage Box Display) must be completed

## Goals

1. Create StorageBoxTransactionService for snapshot comparison
2. Implement deposit workflow: form → preview → confirm
3. Implement withdraw workflow: form → preview → confirm
4. Handle assetId changes by matching on properties
5. Update ItemUser.storageBox relationships atomically

## Implementation Steps

### 1. Create DTOs

**File**: `src/DTO/DepositPreview.php`

```php
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
```

**File**: `src/DTO/WithdrawPreview.php`

```php
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
```

**File**: `src/DTO/TransactionResult.php`

```php
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
```

### 2. Create StorageBoxTransactionService

**File**: `src/Service/StorageBoxTransactionService.php`

```php
<?php

namespace App\Service;

use App\DTO\DepositPreview;
use App\DTO\WithdrawPreview;
use App\DTO\TransactionResult;
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
    public function prepareDepositPreview(User $user, StorageBox $box, string $jsonSnapshot): DepositPreview
    {
        $errors = [];

        // Parse the new snapshot
        try {
            $newSnapshotData = json_decode($jsonSnapshot, true, 512, JSON_THROW_ON_ERROR);
            $newSnapshotItems = $this->inventoryImportService->parseInventoryResponse($newSnapshotData);
        } catch (\JsonException $e) {
            return new DepositPreview(
                itemsToDeposit: [],
                currentItemCount: $box->getItemCount(),
                newItemCount: $box->getItemCount(),
                errors: ['Invalid JSON: ' . $e->getMessage()],
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
    public function prepareWithdrawPreview(User $user, StorageBox $box, string $jsonSnapshot): WithdrawPreview
    {
        $errors = [];

        // Parse the new snapshot
        try {
            $newSnapshotData = json_decode($jsonSnapshot, true, 512, JSON_THROW_ON_ERROR);
            $newSnapshotItems = $this->inventoryImportService->parseInventoryResponse($newSnapshotData);
        } catch (\JsonException $e) {
            return new WithdrawPreview(
                itemsToWithdraw: [],
                currentItemCount: $box->getItemCount(),
                newItemCount: $box->getItemCount(),
                errors: ['Invalid JSON: ' . $e->getMessage()],
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
            if (!in_array($currentItem->getAssetId(), $newSnapshotAssetIds)) {
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
            if (!in_array($snapshotAssetId, $currentAssetIds)) {
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
    private function matchItemInStorage(array $snapshotItem, array $itemsInBox): ?object
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
                $floatMatch = ($snapshotFloat === null || abs($item->getFloatValueAsFloat() - $snapshotFloat) < 0.0000001);
                $patternMatch = ($snapshotPattern === null || $item->getPatternIndex() === $snapshotPattern);

                if ($floatMatch && $patternMatch) {
                    // Update assetId if it changed
                    if ($item->getAssetId() !== $snapshotAssetId) {
                        $this->logger->info('AssetId changed detected', [
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
```

### 3. Create StorageBoxController

**File**: `src/Controller/StorageBoxController.php`

```php
<?php

namespace App\Controller;

use App\Entity\StorageBox;
use App\Service\StorageBoxTransactionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/storage')]
#[IsGranted('ROLE_USER')]
class StorageBoxController extends AbstractController
{
    public function __construct(
        private StorageBoxTransactionService $transactionService
    ) {}

    #[Route('/deposit/{id}', name: 'storage_box_deposit_form')]
    public function depositForm(StorageBox $storageBox): Response
    {
        // Security check
        if ($storageBox->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('storage_box/deposit.html.twig', [
            'storageBox' => $storageBox,
        ]);
    }

    #[Route('/deposit/{id}/preview', name: 'storage_box_deposit_preview', methods: ['POST'])]
    public function depositPreview(StorageBox $storageBox, Request $request): Response
    {
        // Security check
        if ($storageBox->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $jsonSnapshot = $request->request->get('json_snapshot', '');

        if (empty(trim($jsonSnapshot))) {
            $this->addFlash('error', 'Please provide inventory JSON');
            return $this->redirectToRoute('storage_box_deposit_form', ['id' => $storageBox->getId()]);
        }

        try {
            $user = $this->getUser();
            $preview = $this->transactionService->prepareDepositPreview($user, $storageBox, $jsonSnapshot);

            if ($preview->hasErrors()) {
                foreach ($preview->errors as $error) {
                    $this->addFlash('error', $error);
                }
                return $this->redirectToRoute('storage_box_deposit_form', ['id' => $storageBox->getId()]);
            }

            return $this->render('storage_box/deposit_preview.html.twig', [
                'storageBox' => $storageBox,
                'preview' => $preview,
            ]);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to process deposit: ' . $e->getMessage());
            return $this->redirectToRoute('storage_box_deposit_form', ['id' => $storageBox->getId()]);
        }
    }

    #[Route('/deposit/{id}/confirm', name: 'storage_box_deposit_confirm', methods: ['POST'])]
    public function depositConfirm(StorageBox $storageBox, Request $request): Response
    {
        // Security check
        if ($storageBox->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $sessionKey = $request->request->get('session_key');

        if (empty($sessionKey)) {
            $this->addFlash('error', 'Invalid session data. Please try again.');
            return $this->redirectToRoute('storage_box_deposit_form', ['id' => $storageBox->getId()]);
        }

        try {
            $user = $this->getUser();
            $result = $this->transactionService->executeDeposit($user, $storageBox, $sessionKey);

            if ($result->isSuccess()) {
                $this->addFlash('success', sprintf(
                    'Successfully deposited %d items into %s!',
                    $result->itemsMoved,
                    $storageBox->getName()
                ));
            } else {
                $this->addFlash('error', 'Deposit failed. Please try again.');
                foreach ($result->errors as $error) {
                    $this->addFlash('error', $error);
                }
            }

            return $this->redirectToRoute('app_inventory', ['filter' => 'box', 'box_id' => $storageBox->getId()]);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Deposit failed: ' . $e->getMessage());
            return $this->redirectToRoute('storage_box_deposit_form', ['id' => $storageBox->getId()]);
        }
    }

    #[Route('/withdraw/{id}', name: 'storage_box_withdraw_form')]
    public function withdrawForm(StorageBox $storageBox): Response
    {
        // Security check
        if ($storageBox->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('storage_box/withdraw.html.twig', [
            'storageBox' => $storageBox,
        ]);
    }

    #[Route('/withdraw/{id}/preview', name: 'storage_box_withdraw_preview', methods: ['POST'])]
    public function withdrawPreview(StorageBox $storageBox, Request $request): Response
    {
        // Security check
        if ($storageBox->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $jsonSnapshot = $request->request->get('json_snapshot', '');

        if (empty(trim($jsonSnapshot))) {
            $this->addFlash('error', 'Please provide inventory JSON');
            return $this->redirectToRoute('storage_box_withdraw_form', ['id' => $storageBox->getId()]);
        }

        try {
            $user = $this->getUser();
            $preview = $this->transactionService->prepareWithdrawPreview($user, $storageBox, $jsonSnapshot);

            if ($preview->hasErrors()) {
                foreach ($preview->errors as $error) {
                    $this->addFlash('error', $error);
                }
                return $this->redirectToRoute('storage_box_withdraw_form', ['id' => $storageBox->getId()]);
            }

            return $this->render('storage_box/withdraw_preview.html.twig', [
                'storageBox' => $storageBox,
                'preview' => $preview,
            ]);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to process withdrawal: ' . $e->getMessage());
            return $this->redirectToRoute('storage_box_withdraw_form', ['id' => $storageBox->getId()]);
        }
    }

    #[Route('/withdraw/{id}/confirm', name: 'storage_box_withdraw_confirm', methods: ['POST'])]
    public function withdrawConfirm(StorageBox $storageBox, Request $request): Response
    {
        // Security check
        if ($storageBox->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $sessionKey = $request->request->get('session_key');

        if (empty($sessionKey)) {
            $this->addFlash('error', 'Invalid session data. Please try again.');
            return $this->redirectToRoute('storage_box_withdraw_form', ['id' => $storageBox->getId()]);
        }

        try {
            $user = $this->getUser();
            $result = $this->transactionService->executeWithdraw($user, $storageBox, $sessionKey);

            if ($result->isSuccess()) {
                $this->addFlash('success', sprintf(
                    'Successfully withdrew %d items from %s!',
                    $result->itemsMoved,
                    $storageBox->getName()
                ));
            } else {
                $this->addFlash('error', 'Withdrawal failed. Please try again.');
                foreach ($result->errors as $error) {
                    $this->addFlash('error', $error);
                }
            }

            return $this->redirectToRoute('app_inventory', ['filter' => 'active']);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Withdrawal failed: ' . $e->getMessage());
            return $this->redirectToRoute('storage_box_withdraw_form', ['id' => $storageBox->getId()]);
        }
    }
}
```

### 4. Update Storage Box Display to Add Deposit/Withdraw Buttons

**File**: `templates/inventory/index.html.twig`

Update storage box cards to include action buttons:

```twig
<div class="flex gap-2 mt-3">
    <a href="{{ path('storage_box_deposit_form', {id: box.id}) }}"
       class="flex-1 text-center bg-green-600 text-white px-3 py-2 rounded text-sm hover:bg-green-700">
        Deposit
    </a>
    <a href="{{ path('storage_box_withdraw_form', {id: box.id}) }}"
       class="flex-1 text-center bg-orange-600 text-white px-3 py-2 rounded text-sm hover:bg-orange-700">
        Withdraw
    </a>
</div>
```

### 5. Create Templates

Create these template files (see next task comment for full template code):

- `templates/storage_box/deposit.html.twig`
- `templates/storage_box/deposit_preview.html.twig`
- `templates/storage_box/withdraw.html.twig`
- `templates/storage_box/withdraw_preview.html.twig`

Due to length, I'll include template code in acceptance criteria section.

## Testing

### Manual Testing

1. **Deposit Flow**:
   - Go to storage box, click "Deposit"
   - In-game, deposit some items into that box
   - Copy inventory JSON
   - Paste into form, click "Preview Deposit"
   - Verify preview shows correct items
   - Click "Confirm Deposit"
   - Verify items are moved to storage box
   - Check inventory page - items should have storage badge

2. **Withdraw Flow**:
   - Go to storage box, click "Withdraw"
   - In-game, withdraw some items from that box
   - Copy inventory JSON
   - Paste into form, click "Preview Withdrawal"
   - Verify preview shows correct items
   - Click "Confirm Withdrawal"
   - Verify items are moved to active inventory
   - Check inventory page - items should no longer have storage badge

3. **AssetId Change Handling**:
   - Manually modify a JSON to change an assetId
   - Perform withdraw
   - Check logs to verify assetId change was detected
   - Verify item was still matched correctly

4. **Error Scenarios**:
   - Submit invalid JSON
   - Submit empty JSON
   - Try to access another user's storage box
   - Let session expire and try to confirm

## Acceptance Criteria

- [ ] StorageBoxTransactionService created with all methods
- [ ] Deposit form displays with storage box info
- [ ] Deposit preview shows items that will be deposited
- [ ] Deposit confirmation moves items to storage box atomically
- [ ] `ItemUser.storageBox` is set correctly after deposit
- [ ] Withdraw form displays with storage box info
- [ ] Withdraw preview shows items that will be withdrawn
- [ ] Withdraw confirmation moves items to active inventory atomically
- [ ] `ItemUser.storageBox` is set to null after withdrawal
- [ ] AssetId changes are detected and logged
- [ ] Items are matched by properties when assetId changes
- [ ] Storage box item count is updated after transactions
- [ ] Session data is cleared after successful transaction
- [ ] Transactions are atomic (rollback on error)
- [ ] Security checks prevent accessing other users' boxes
- [ ] Error messages are helpful and clear
- [ ] Success messages show item counts moved

## Dependencies

- Task 2-1: Storage Box Database Setup (required)
- Task 2-2: Storage Box Import Integration (required)
- Task 2-3: Storage Box Display (required)

## Next Task

**Task 2-5**: Testing & Documentation - Comprehensive testing and documentation updates.

## Related Files

- `src/DTO/DepositPreview.php` (new)
- `src/DTO/WithdrawPreview.php` (new)
- `src/DTO/TransactionResult.php` (new)
- `src/Service/StorageBoxTransactionService.php` (new)
- `src/Controller/StorageBoxController.php` (new)
- `templates/storage_box/deposit.html.twig` (new)
- `templates/storage_box/deposit_preview.html.twig` (new)
- `templates/storage_box/withdraw.html.twig` (new)
- `templates/storage_box/withdraw_preview.html.twig` (new)
- `templates/inventory/index.html.twig` (modified)
