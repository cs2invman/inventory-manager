<?php

namespace App\DTO;

use App\Entity\Item;

class ItemDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $imageUrl,
        public readonly string $steamId,
        public readonly string $type,
        public readonly string $hashName,
        public readonly string $category,
        public readonly ?string $subcategory,
        public readonly string $rarity,
        public readonly ?string $rarityColor,
        public readonly ?string $collection,
        public readonly bool $stattrakAvailable,
        public readonly bool $souvenirAvailable,
        public readonly ?string $description,
        public readonly ?string $iconUrlLarge,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
    ) {
    }

    public static function fromEntity(Item $item): self
    {
        return new self(
            id: $item->getId(),
            name: $item->getName(),
            imageUrl: $item->getImageUrl(),
            steamId: $item->getSteamId(),
            type: $item->getType(),
            hashName: $item->getHashName(),
            category: $item->getCategory(),
            subcategory: $item->getSubcategory(),
            rarity: $item->getRarity(),
            rarityColor: $item->getRarityColor(),
            collection: $item->getCollection(),
            stattrakAvailable: $item->isStattrakAvailable(),
            souvenirAvailable: $item->isSouvenirAvailable(),
            description: $item->getDescription(),
            iconUrlLarge: $item->getIconUrlLarge(),
            createdAt: $item->getCreatedAt(),
            updatedAt: $item->getUpdatedAt(),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'image_url' => $this->imageUrl,
            'steam_id' => $this->steamId,
            'type' => $this->type,
            'hash_name' => $this->hashName,
            'category' => $this->category,
            'subcategory' => $this->subcategory,
            'rarity' => $this->rarity,
            'rarity_color' => $this->rarityColor,
            'collection' => $this->collection,
            'stattrak_available' => $this->stattrakAvailable,
            'souvenir_available' => $this->souvenirAvailable,
            'description' => $this->description,
            'icon_url_large' => $this->iconUrlLarge,
            'created_at' => $this->createdAt->format('c'),
            'updated_at' => $this->updatedAt->format('c'),
        ];
    }
}