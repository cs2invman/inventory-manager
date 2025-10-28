<?php

namespace App\Entity;

use App\Repository\ItemPriceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ItemPriceRepository::class)]
#[ORM\Table(name: 'item_price')]
#[ORM\Index(columns: ['item_id', 'price_date'], name: 'idx_item_price_composite')]
#[ORM\Index(columns: ['price_date'], name: 'idx_price_date')]
#[ORM\Index(columns: ['source'], name: 'idx_price_source')]
class ItemPrice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Item::class, inversedBy: 'priceHistory')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Item $item = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $priceDate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $price = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $volume = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $medianPrice = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $lowestPrice = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $highestPrice = null;

    #[ORM\Column(length: 50)]
    private ?string $source = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->priceDate = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getItem(): ?Item
    {
        return $this->item;
    }

    public function setItem(?Item $item): static
    {
        $this->item = $item;
        return $this;
    }

    public function getPriceDate(): ?\DateTimeImmutable
    {
        return $this->priceDate;
    }

    public function setPriceDate(\DateTimeImmutable $priceDate): static
    {
        $this->priceDate = $priceDate;
        return $this;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(string $price): static
    {
        $this->price = $price;
        return $this;
    }

    public function getPriceAsFloat(): ?float
    {
        return $this->price !== null ? (float) $this->price : null;
    }

    public function getVolume(): ?int
    {
        return $this->volume;
    }

    public function setVolume(?int $volume): static
    {
        $this->volume = $volume;
        return $this;
    }

    public function getMedianPrice(): ?string
    {
        return $this->medianPrice;
    }

    public function setMedianPrice(?string $medianPrice): static
    {
        $this->medianPrice = $medianPrice;
        return $this;
    }

    public function getMedianPriceAsFloat(): ?float
    {
        return $this->medianPrice !== null ? (float) $this->medianPrice : null;
    }

    public function getLowestPrice(): ?string
    {
        return $this->lowestPrice;
    }

    public function setLowestPrice(?string $lowestPrice): static
    {
        $this->lowestPrice = $lowestPrice;
        return $this;
    }

    public function getLowestPriceAsFloat(): ?float
    {
        return $this->lowestPrice !== null ? (float) $this->lowestPrice : null;
    }

    public function getHighestPrice(): ?string
    {
        return $this->highestPrice;
    }

    public function setHighestPrice(?string $highestPrice): static
    {
        $this->highestPrice = $highestPrice;
        return $this;
    }

    public function getHighestPriceAsFloat(): ?float
    {
        return $this->highestPrice !== null ? (float) $this->highestPrice : null;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }
}