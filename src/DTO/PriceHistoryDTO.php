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
        public readonly ?int $soldTotal,
        public readonly ?float $medianPrice,
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
            soldTotal: $itemPrice->getSoldTotal(),
            medianPrice: $itemPrice->getMedianPriceAsFloat(),
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
            'sold_total' => $this->soldTotal,
            'median_price' => $this->medianPrice,
            'source' => $this->source,
            'created_at' => $this->createdAt->format('c'),
        ];
    }
}