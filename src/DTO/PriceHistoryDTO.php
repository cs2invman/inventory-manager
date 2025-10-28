<?php

namespace App\DTO;

use App\Entity\ItemPrice;

class PriceHistoryDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $itemId,
        public readonly \DateTimeImmutable $priceDate,
        public readonly float $price,
        public readonly ?int $volume,
        public readonly ?float $medianPrice,
        public readonly ?float $lowestPrice,
        public readonly ?float $highestPrice,
        public readonly string $source,
        public readonly \DateTimeImmutable $createdAt,
    ) {
    }

    public static function fromEntity(ItemPrice $itemPrice): self
    {
        return new self(
            id: $itemPrice->getId(),
            itemId: $itemPrice->getItem()->getId(),
            priceDate: $itemPrice->getPriceDate(),
            price: $itemPrice->getPriceAsFloat(),
            volume: $itemPrice->getVolume(),
            medianPrice: $itemPrice->getMedianPriceAsFloat(),
            lowestPrice: $itemPrice->getLowestPriceAsFloat(),
            highestPrice: $itemPrice->getHighestPriceAsFloat(),
            source: $itemPrice->getSource(),
            createdAt: $itemPrice->getCreatedAt(),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'item_id' => $this->itemId,
            'price_date' => $this->priceDate->format('c'),
            'price' => $this->price,
            'volume' => $this->volume,
            'median_price' => $this->medianPrice,
            'lowest_price' => $this->lowestPrice,
            'highest_price' => $this->highestPrice,
            'source' => $this->source,
            'created_at' => $this->createdAt->format('c'),
        ];
    }
}