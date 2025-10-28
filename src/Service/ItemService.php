<?php

namespace App\Service;

use App\DTO\ItemDTO;
use App\Entity\Item;
use App\Repository\ItemRepository;
use Doctrine\ORM\EntityManagerInterface;

class ItemService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ItemRepository $itemRepository
    ) {
    }

    /**
     * Get all items
     *
     * @return Item[]
     */
    public function getAllItems(): array
    {
        return $this->itemRepository->findAll();
    }

    /**
     * Get item by ID
     */
    public function getItemById(int $id): ?Item
    {
        return $this->itemRepository->find($id);
    }

    /**
     * Get item by hash name
     */
    public function getItemByHashName(string $hashName): ?Item
    {
        return $this->itemRepository->findByHashName($hashName);
    }

    /**
     * Search items by name
     *
     * @return Item[]
     */
    public function searchItemsByName(string $searchTerm): array
    {
        return $this->itemRepository->searchByName($searchTerm);
    }

    /**
     * Get items by category
     *
     * @return Item[]
     */
    public function getItemsByCategory(string $category): array
    {
        return $this->itemRepository->findByCategory($category);
    }

    /**
     * Get items by type
     *
     * @return Item[]
     */
    public function getItemsByType(string $type): array
    {
        return $this->itemRepository->findByType($type);
    }

    /**
     * Get items by rarity
     *
     * @return Item[]
     */
    public function getItemsByRarity(string $rarity): array
    {
        return $this->itemRepository->findByRarity($rarity);
    }

    /**
     * Get StatTrak-enabled items
     *
     * @return Item[]
     */
    public function getStattrakItems(): array
    {
        return $this->itemRepository->findStattrakItems();
    }

    /**
     * Get Souvenir-enabled items
     *
     * @return Item[]
     */
    public function getSouvenirItems(): array
    {
        return $this->itemRepository->findSouvenirItems();
    }

    /**
     * Get items by collection
     *
     * @return Item[]
     */
    public function getItemsByCollection(string $collection): array
    {
        return $this->itemRepository->findByCollection($collection);
    }

    /**
     * Get all categories
     *
     * @return string[]
     */
    public function getAllCategories(): array
    {
        return $this->itemRepository->findAllCategories();
    }

    /**
     * Get all types
     *
     * @return string[]
     */
    public function getAllTypes(): array
    {
        return $this->itemRepository->findAllTypes();
    }

    /**
     * Get all rarities
     *
     * @return string[]
     */
    public function getAllRarities(): array
    {
        return $this->itemRepository->findAllRarities();
    }

    /**
     * Get all collections
     *
     * @return string[]
     */
    public function getAllCollections(): array
    {
        return $this->itemRepository->findAllCollections();
    }

    /**
     * Count items by category
     *
     * @return array<string, int>
     */
    public function countByCategory(): array
    {
        return $this->itemRepository->countByCategory();
    }

    /**
     * Count items by type
     *
     * @return array<string, int>
     */
    public function countByType(): array
    {
        return $this->itemRepository->countByType();
    }

    /**
     * Create a new item
     */
    public function createItem(Item $item): Item
    {
        $this->entityManager->persist($item);
        $this->entityManager->flush();

        return $item;
    }

    /**
     * Update an item
     */
    public function updateItem(Item $item): Item
    {
        $this->entityManager->flush();

        return $item;
    }

    /**
     * Delete an item
     */
    public function deleteItem(Item $item): void
    {
        $this->entityManager->remove($item);
        $this->entityManager->flush();
    }

    /**
     * Convert entity to DTO
     */
    public function toDTO(Item $item): ItemDTO
    {
        return ItemDTO::fromEntity($item);
    }

    /**
     * Convert multiple entities to DTOs
     *
     * @param Item[] $items
     * @return ItemDTO[]
     */
    public function toDTOs(array $items): array
    {
        return array_map(fn(Item $item) => $this->toDTO($item), $items);
    }
}