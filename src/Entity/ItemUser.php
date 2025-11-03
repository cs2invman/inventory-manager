<?php

namespace App\Entity;

use App\Repository\ItemUserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ItemUserRepository::class)]
#[ORM\Table(name: 'item_user')]
#[ORM\Index(columns: ['user_id'], name: 'idx_item_user_user')]
#[ORM\Index(columns: ['user_id', 'item_id'], name: 'idx_item_user_composite')]
#[ORM\HasLifecycleCallbacks]
class ItemUser
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Item::class, inversedBy: 'userInstances')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Item $item = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'inventory')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 100, unique: true, nullable: true)]
    private ?string $assetId = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 7, nullable: true)]
    #[Assert\Range(min: 0, max: 1, notInRangeMessage: 'Float value must be between {{ min }} and {{ max }}')]
    private ?string $floatValue = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $paintSeed = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $patternIndex = null;

    #[ORM\ManyToOne(targetEntity: StorageBox::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?StorageBox $storageBox = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $inspectLink = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $stattrakCounter = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isStattrak = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $isSouvenir = false;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $stickers = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $keychain = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nameTag = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $acquiredDate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero(message: 'Acquired price must be zero or greater')]
    private ?string $acquiredPrice = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero(message: 'Current market value must be zero or greater')]
    private ?string $currentMarketValue = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $wearCategory = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function calculateWearCategory(): void
    {
        if ($this->floatValue !== null) {
            $float = (float) $this->floatValue;

            if ($float >= 0.00 && $float < 0.07) {
                $this->wearCategory = 'FN';
            } elseif ($float >= 0.07 && $float < 0.15) {
                $this->wearCategory = 'MW';
            } elseif ($float >= 0.15 && $float < 0.38) {
                $this->wearCategory = 'FT';
            } elseif ($float >= 0.38 && $float < 0.45) {
                $this->wearCategory = 'WW';
            } elseif ($float >= 0.45 && $float <= 1.00) {
                $this->wearCategory = 'BS';
            }
        }
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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getAssetId(): ?string
    {
        return $this->assetId;
    }

    public function setAssetId(?string $assetId): static
    {
        $this->assetId = $assetId;
        return $this;
    }

    public function getFloatValue(): ?string
    {
        return $this->floatValue;
    }

    public function getFloatValueAsFloat(): ?float
    {
        return $this->floatValue !== null ? (float) $this->floatValue : null;
    }

    public function setFloatValue(?string $floatValue): static
    {
        $this->floatValue = $floatValue;
        return $this;
    }

    public function getPaintSeed(): ?int
    {
        return $this->paintSeed;
    }

    public function setPaintSeed(?int $paintSeed): static
    {
        $this->paintSeed = $paintSeed;
        return $this;
    }

    public function getPatternIndex(): ?int
    {
        return $this->patternIndex;
    }

    public function setPatternIndex(?int $patternIndex): static
    {
        $this->patternIndex = $patternIndex;
        return $this;
    }

    public function getStorageBox(): ?StorageBox
    {
        return $this->storageBox;
    }

    public function setStorageBox(?StorageBox $storageBox): static
    {
        $this->storageBox = $storageBox;
        return $this;
    }

    public function getInspectLink(): ?string
    {
        return $this->inspectLink;
    }

    public function setInspectLink(?string $inspectLink): static
    {
        $this->inspectLink = $inspectLink;
        return $this;
    }

    public function getStattrakCounter(): ?int
    {
        return $this->stattrakCounter;
    }

    public function setStattrakCounter(?int $stattrakCounter): static
    {
        $this->stattrakCounter = $stattrakCounter;
        return $this;
    }

    public function isStattrak(): bool
    {
        return $this->isStattrak;
    }

    public function setIsStattrak(bool $isStattrak): static
    {
        $this->isStattrak = $isStattrak;
        return $this;
    }

    public function isSouvenir(): bool
    {
        return $this->isSouvenir;
    }

    public function setIsSouvenir(bool $isSouvenir): static
    {
        $this->isSouvenir = $isSouvenir;
        return $this;
    }

    public function getStickers(): ?array
    {
        return $this->stickers;
    }

    public function setStickers(?array $stickers): static
    {
        $this->stickers = $stickers;
        return $this;
    }

    public function addSticker(int $slot, string $name, ?float $wear = null, ?string $imageUrl = null): static
    {
        if ($this->stickers === null) {
            $this->stickers = [];
        }

        $this->stickers[] = [
            'slot' => $slot,
            'name' => $name,
            'wear' => $wear,
            'image_url' => $imageUrl,
        ];

        return $this;
    }

    public function getKeychain(): ?array
    {
        return $this->keychain;
    }

    public function setKeychain(?array $keychain): static
    {
        $this->keychain = $keychain;
        return $this;
    }

    public function getNameTag(): ?string
    {
        return $this->nameTag;
    }

    public function setNameTag(?string $nameTag): static
    {
        $this->nameTag = $nameTag;
        return $this;
    }

    public function getAcquiredDate(): ?\DateTimeImmutable
    {
        return $this->acquiredDate;
    }

    public function setAcquiredDate(?\DateTimeImmutable $acquiredDate): static
    {
        $this->acquiredDate = $acquiredDate;
        return $this;
    }

    public function getAcquiredPrice(): ?string
    {
        return $this->acquiredPrice;
    }

    public function getAcquiredPriceAsFloat(): ?float
    {
        return $this->acquiredPrice !== null ? (float) $this->acquiredPrice : null;
    }

    public function setAcquiredPrice(?string $acquiredPrice): static
    {
        $this->acquiredPrice = $acquiredPrice;
        return $this;
    }

    public function getCurrentMarketValue(): ?string
    {
        return $this->currentMarketValue;
    }

    public function getCurrentMarketValueAsFloat(): ?float
    {
        return $this->currentMarketValue !== null ? (float) $this->currentMarketValue : null;
    }

    public function setCurrentMarketValue(?string $currentMarketValue): static
    {
        $this->currentMarketValue = $currentMarketValue;
        return $this;
    }

    public function getWearCategory(): ?string
    {
        return $this->wearCategory;
    }

    public function setWearCategory(?string $wearCategory): static
    {
        $this->wearCategory = $wearCategory;
        return $this;
    }

    public function getWearCategoryFullName(): ?string
    {
        return match ($this->wearCategory) {
            'FN' => 'Factory New',
            'MW' => 'Minimal Wear',
            'FT' => 'Field-Tested',
            'WW' => 'Well-Worn',
            'BS' => 'Battle-Scarred',
            default => null,
        };
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
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

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * Calculate profit/loss if item was sold at current market value
     */
    public function calculateProfitLoss(): ?float
    {
        if ($this->acquiredPrice === null || $this->currentMarketValue === null) {
            return null;
        }

        return (float) $this->currentMarketValue - (float) $this->acquiredPrice;
    }

    /**
     * Calculate profit/loss percentage
     */
    public function calculateProfitLossPercentage(): ?float
    {
        if ($this->acquiredPrice === null || $this->currentMarketValue === null) {
            return null;
        }

        $acquiredPrice = (float) $this->acquiredPrice;
        if ($acquiredPrice == 0) {
            return null;
        }

        $profitLoss = $this->calculateProfitLoss();
        return ($profitLoss / $acquiredPrice) * 100;
    }
}