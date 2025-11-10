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
    private ?int $soldTotal = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $sold30d = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $sold7d = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $soldToday = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $volumeBuyOrders = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $volumeSellOrders = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $medianPrice = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $priceBuyOrder = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $priceMedian = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $priceMedian24h = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $priceMedian7d = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $priceMedian30d = null;

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

    public function getSoldTotal(): ?int
    {
        return $this->soldTotal;
    }

    public function setSoldTotal(?int $soldTotal): static
    {
        $this->soldTotal = $soldTotal;
        return $this;
    }

    public function getSold30d(): ?int
    {
        return $this->sold30d;
    }

    public function setSold30d(?int $sold30d): static
    {
        $this->sold30d = $sold30d;
        return $this;
    }

    public function getSold7d(): ?int
    {
        return $this->sold7d;
    }

    public function setSold7d(?int $sold7d): static
    {
        $this->sold7d = $sold7d;
        return $this;
    }

    public function getSoldToday(): ?int
    {
        return $this->soldToday;
    }

    public function setSoldToday(?int $soldToday): static
    {
        $this->soldToday = $soldToday;
        return $this;
    }

    public function getVolumeBuyOrders(): ?int
    {
        return $this->volumeBuyOrders;
    }

    public function setVolumeBuyOrders(?int $volumeBuyOrders): static
    {
        $this->volumeBuyOrders = $volumeBuyOrders;
        return $this;
    }

    public function getVolumeSellOrders(): ?int
    {
        return $this->volumeSellOrders;
    }

    public function setVolumeSellOrders(?int $volumeSellOrders): static
    {
        $this->volumeSellOrders = $volumeSellOrders;
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

    public function getPriceBuyOrder(): ?string
    {
        return $this->priceBuyOrder;
    }

    public function setPriceBuyOrder(?string $priceBuyOrder): static
    {
        $this->priceBuyOrder = $priceBuyOrder;
        return $this;
    }

    public function getPriceBuyOrderAsFloat(): ?float
    {
        return $this->priceBuyOrder !== null ? (float) $this->priceBuyOrder : null;
    }

    public function getPriceMedian(): ?string
    {
        return $this->priceMedian;
    }

    public function setPriceMedian(?string $priceMedian): static
    {
        $this->priceMedian = $priceMedian;
        return $this;
    }

    public function getPriceMedianAsFloat(): ?float
    {
        return $this->priceMedian !== null ? (float) $this->priceMedian : null;
    }

    public function getPriceMedian24h(): ?string
    {
        return $this->priceMedian24h;
    }

    public function setPriceMedian24h(?string $priceMedian24h): static
    {
        $this->priceMedian24h = $priceMedian24h;
        return $this;
    }

    public function getPriceMedian24hAsFloat(): ?float
    {
        return $this->priceMedian24h !== null ? (float) $this->priceMedian24h : null;
    }

    public function getPriceMedian7d(): ?string
    {
        return $this->priceMedian7d;
    }

    public function setPriceMedian7d(?string $priceMedian7d): static
    {
        $this->priceMedian7d = $priceMedian7d;
        return $this;
    }

    public function getPriceMedian7dAsFloat(): ?float
    {
        return $this->priceMedian7d !== null ? (float) $this->priceMedian7d : null;
    }

    public function getPriceMedian30d(): ?string
    {
        return $this->priceMedian30d;
    }

    public function setPriceMedian30d(?string $priceMedian30d): static
    {
        $this->priceMedian30d = $priceMedian30d;
        return $this;
    }

    public function getPriceMedian30dAsFloat(): ?float
    {
        return $this->priceMedian30d !== null ? (float) $this->priceMedian30d : null;
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