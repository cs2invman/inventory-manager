<?php

namespace App\DTO;

use App\Entity\ItemUser;

class InventoryItemDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $itemId,
        public readonly string $itemName,
        public readonly string $itemImageUrl,
        public readonly int $userId,
        public readonly ?string $assetId,
        public readonly ?float $floatValue,
        public readonly ?int $paintSeed,
        public readonly ?int $patternIndex,
        public readonly ?string $storageBoxName,
        public readonly ?string $inspectLink,
        public readonly ?int $stattrakCounter,
        public readonly bool $isStattrak,
        public readonly bool $isSouvenir,
        public readonly ?array $stickers,
        public readonly ?string $nameTag,
        public readonly ?\DateTimeImmutable $acquiredDate,
        public readonly ?float $acquiredPrice,
        public readonly ?float $currentMarketValue,
        public readonly ?string $wearCategory,
        public readonly ?string $wearCategoryFullName,
        public readonly ?string $notes,
        public readonly ?float $profitLoss,
        public readonly ?float $profitLossPercentage,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
    ) {
    }

    public static function fromEntity(ItemUser $itemUser): self
    {
        return new self(
            id: $itemUser->getId(),
            itemId: $itemUser->getItem()->getId(),
            itemName: $itemUser->getItem()->getName(),
            itemImageUrl: $itemUser->getItem()->getImageUrl(),
            userId: $itemUser->getUser()->getId(),
            assetId: $itemUser->getAssetId(),
            floatValue: $itemUser->getFloatValueAsFloat(),
            paintSeed: $itemUser->getPaintSeed(),
            patternIndex: $itemUser->getPatternIndex(),
            storageBoxName: $itemUser->getStorageBoxName(),
            inspectLink: $itemUser->getInspectLink(),
            stattrakCounter: $itemUser->getStattrakCounter(),
            isStattrak: $itemUser->isStattrak(),
            isSouvenir: $itemUser->isSouvenir(),
            stickers: $itemUser->getStickers(),
            nameTag: $itemUser->getNameTag(),
            acquiredDate: $itemUser->getAcquiredDate(),
            acquiredPrice: $itemUser->getAcquiredPriceAsFloat(),
            currentMarketValue: $itemUser->getCurrentMarketValueAsFloat(),
            wearCategory: $itemUser->getWearCategory(),
            wearCategoryFullName: $itemUser->getWearCategoryFullName(),
            notes: $itemUser->getNotes(),
            profitLoss: $itemUser->calculateProfitLoss(),
            profitLossPercentage: $itemUser->calculateProfitLossPercentage(),
            createdAt: $itemUser->getCreatedAt(),
            updatedAt: $itemUser->getUpdatedAt(),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'item_id' => $this->itemId,
            'item_name' => $this->itemName,
            'item_image_url' => $this->itemImageUrl,
            'user_id' => $this->userId,
            'asset_id' => $this->assetId,
            'float_value' => $this->floatValue,
            'paint_seed' => $this->paintSeed,
            'pattern_index' => $this->patternIndex,
            'storage_box_name' => $this->storageBoxName,
            'inspect_link' => $this->inspectLink,
            'stattrak_counter' => $this->stattrakCounter,
            'is_stattrak' => $this->isStattrak,
            'is_souvenir' => $this->isSouvenir,
            'stickers' => $this->stickers,
            'name_tag' => $this->nameTag,
            'acquired_date' => $this->acquiredDate?->format('c'),
            'acquired_price' => $this->acquiredPrice,
            'current_market_value' => $this->currentMarketValue,
            'wear_category' => $this->wearCategory,
            'wear_category_full_name' => $this->wearCategoryFullName,
            'notes' => $this->notes,
            'profit_loss' => $this->profitLoss,
            'profit_loss_percentage' => $this->profitLossPercentage,
            'created_at' => $this->createdAt->format('c'),
            'updated_at' => $this->updatedAt->format('c'),
        ];
    }
}