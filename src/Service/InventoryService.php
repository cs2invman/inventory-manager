<?php

namespace App\Service;

use App\DTO\InventoryItemDTO;
use App\Entity\Item;
use App\Entity\ItemUser;
use App\Entity\User;
use App\Repository\ItemUserRepository;
use Doctrine\ORM\EntityManagerInterface;

class InventoryService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ItemUserRepository $itemUserRepository
    ) {
    }

    /**
     * Get user's inventory with optional filters
     *
     * @return ItemUser[]
     */
    public function getUserInventory(User $user, array $filters = []): array
    {
        return $this->itemUserRepository->findUserInventory($user->getId(), $filters);
    }

    /**
     * Get user's items in a specific storage box
     *
     * @return ItemUser[]
     */
    public function getItemsByStorageBox(User $user, string $boxName): array
    {
        return $this->itemUserRepository->findByStorageBox($user->getId(), $boxName);
    }

    /**
     * Get user's storage boxes
     *
     * @return string[]
     */
    public function getUserStorageBoxes(User $user): array
    {
        return $this->itemUserRepository->findUserStorageBoxes($user->getId());
    }

    /**
     * Calculate total inventory value
     */
    public function calculateInventoryValue(User $user): float
    {
        return $this->itemUserRepository->calculateInventoryValue($user->getId());
    }

    /**
     * Get StatTrak items in user's inventory
     *
     * @return ItemUser[]
     */
    public function getStattrakItems(User $user): array
    {
        return $this->itemUserRepository->findStattrakItems($user->getId());
    }

    /**
     * Get Souvenir items in user's inventory
     *
     * @return ItemUser[]
     */
    public function getSouvenirItems(User $user): array
    {
        return $this->itemUserRepository->findSouvenirItems($user->getId());
    }

    /**
     * Get items with custom name tags
     *
     * @return ItemUser[]
     */
    public function getNameTaggedItems(User $user): array
    {
        return $this->itemUserRepository->findNameTaggedItems($user->getId());
    }

    /**
     * Get items with stickers
     *
     * @return ItemUser[]
     */
    public function getItemsWithStickers(User $user): array
    {
        return $this->itemUserRepository->findItemsWithStickers($user->getId());
    }

    /**
     * Get items by wear category
     *
     * @return ItemUser[]
     */
    public function getItemsByWearCategory(User $user, string $wearCategory): array
    {
        return $this->itemUserRepository->findByWearCategory($user->getId(), $wearCategory);
    }

    /**
     * Get most valuable items
     *
     * @return ItemUser[]
     */
    public function getMostValuableItems(User $user, int $limit = 10): array
    {
        return $this->itemUserRepository->findMostValuableItems($user->getId(), $limit);
    }

    /**
     * Get recently acquired items
     *
     * @return ItemUser[]
     */
    public function getRecentlyAcquired(User $user, int $days = 7, int $limit = 20): array
    {
        return $this->itemUserRepository->findRecentlyAcquired($user->getId(), $days, $limit);
    }

    /**
     * Get inventory statistics by category
     *
     * @return array<string, array{count: int, total_value: float}>
     */
    public function getInventoryStatsByCategory(User $user): array
    {
        return $this->itemUserRepository->getInventoryStatsByCategory($user->getId());
    }

    /**
     * Get inventory statistics by rarity
     *
     * @return array<string, array{count: int, total_value: float}>
     */
    public function getInventoryStatsByRarity(User $user): array
    {
        return $this->itemUserRepository->getInventoryStatsByRarity($user->getId());
    }

    /**
     * Calculate total profit/loss
     */
    public function calculateTotalProfitLoss(User $user): float
    {
        return $this->itemUserRepository->calculateTotalProfitLoss($user->getId());
    }

    /**
     * Find items with significant profit/loss
     *
     * @return ItemUser[]
     */
    public function findItemsWithSignificantProfitLoss(User $user, float $minPercentage = 10.0): array
    {
        return $this->itemUserRepository->findItemsWithSignificantProfitLoss($user->getId(), $minPercentage);
    }

    /**
     * Count user's items
     */
    public function countUserItems(User $user): int
    {
        return $this->itemUserRepository->countUserItems($user->getId());
    }

    /**
     * Search inventory by item name
     *
     * @return ItemUser[]
     */
    public function searchInventory(User $user, string $searchTerm): array
    {
        return $this->itemUserRepository->searchInventoryByName($user->getId(), $searchTerm);
    }

    /**
     * Add item to user's inventory
     */
    public function addItemToInventory(User $user, Item $item, array $data = []): ItemUser
    {
        $itemUser = new ItemUser();
        $itemUser->setUser($user);
        $itemUser->setItem($item);

        if (isset($data['asset_id'])) {
            $itemUser->setAssetId($data['asset_id']);
        }
        if (isset($data['float_value'])) {
            $itemUser->setFloatValue($data['float_value']);
        }
        if (isset($data['paint_seed'])) {
            $itemUser->setPaintSeed($data['paint_seed']);
        }
        if (isset($data['pattern_index'])) {
            $itemUser->setPatternIndex($data['pattern_index']);
        }
        if (isset($data['storage_box_name'])) {
            $itemUser->setStorageBoxName($data['storage_box_name']);
        }
        if (isset($data['inspect_link'])) {
            $itemUser->setInspectLink($data['inspect_link']);
        }
        if (isset($data['stattrak_counter'])) {
            $itemUser->setStattrakCounter($data['stattrak_counter']);
        }
        if (isset($data['is_stattrak'])) {
            $itemUser->setIsStattrak($data['is_stattrak']);
        }
        if (isset($data['is_souvenir'])) {
            $itemUser->setIsSouvenir($data['is_souvenir']);
        }
        if (isset($data['stickers'])) {
            $itemUser->setStickers($data['stickers']);
        }
        if (isset($data['name_tag'])) {
            $itemUser->setNameTag($data['name_tag']);
        }
        if (isset($data['acquired_date'])) {
            $itemUser->setAcquiredDate($data['acquired_date']);
        }
        if (isset($data['acquired_price'])) {
            $itemUser->setAcquiredPrice($data['acquired_price']);
        }
        if (isset($data['current_market_value'])) {
            $itemUser->setCurrentMarketValue($data['current_market_value']);
        }
        if (isset($data['notes'])) {
            $itemUser->setNotes($data['notes']);
        }

        $this->entityManager->persist($itemUser);
        $this->entityManager->flush();

        return $itemUser;
    }

    /**
     * Update an inventory item
     */
    public function updateInventoryItem(ItemUser $itemUser): ItemUser
    {
        $this->entityManager->flush();

        return $itemUser;
    }

    /**
     * Remove item from inventory
     */
    public function removeItemFromInventory(ItemUser $itemUser): void
    {
        $this->entityManager->remove($itemUser);
        $this->entityManager->flush();
    }

    /**
     * Get comprehensive inventory summary
     */
    public function getInventorySummary(User $user): array
    {
        return [
            'total_items' => $this->countUserItems($user),
            'total_value' => $this->calculateInventoryValue($user),
            'total_profit_loss' => $this->calculateTotalProfitLoss($user),
            'stats_by_category' => $this->getInventoryStatsByCategory($user),
            'stats_by_rarity' => $this->getInventoryStatsByRarity($user),
            'storage_boxes' => $this->getUserStorageBoxes($user),
            'most_valuable' => $this->getMostValuableItems($user, 5),
            'recently_acquired' => $this->getRecentlyAcquired($user, 7, 5),
        ];
    }

    /**
     * Convert entity to DTO
     */
    public function toDTO(ItemUser $itemUser): InventoryItemDTO
    {
        return InventoryItemDTO::fromEntity($itemUser);
    }

    /**
     * Convert multiple entities to DTOs
     *
     * @param ItemUser[] $items
     * @return InventoryItemDTO[]
     */
    public function toDTOs(array $items): array
    {
        return array_map(fn(ItemUser $item) => $this->toDTO($item), $items);
    }
}