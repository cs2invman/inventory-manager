<?php

namespace App\Entity;

use App\Repository\ItemRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ItemRepository::class)]
#[ORM\Table(name: 'item')]
#[ORM\Index(columns: ['type'], name: 'idx_item_type')]
#[ORM\Index(columns: ['category'], name: 'idx_item_category')]
#[ORM\Index(columns: ['rarity'], name: 'idx_item_rarity')]
#[ORM\HasLifecycleCallbacks]
class Item
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 500)]
    private ?string $imageUrl = null;

    #[ORM\Column(length: 100, unique: true)]
    private ?string $steamId = null;

    #[ORM\Column(length: 50)]
    private ?string $type = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $hashName = null;

    #[ORM\Column(length: 100)]
    private ?string $category = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $subcategory = null;

    #[ORM\Column(length: 50)]
    private ?string $rarity = null;

    #[ORM\Column(length: 7, nullable: true)]
    private ?string $rarityColor = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $collection = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $stattrakAvailable = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $souvenirAvailable = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $iconUrlLarge = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @var Collection<int, ItemPrice>
     */
    #[ORM\OneToMany(targetEntity: ItemPrice::class, mappedBy: 'item', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $priceHistory;

    /**
     * @var Collection<int, ItemUser>
     */
    #[ORM\OneToMany(targetEntity: ItemUser::class, mappedBy: 'item', cascade: ['persist'])]
    private Collection $userInstances;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->priceHistory = new ArrayCollection();
        $this->userInstances = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function setImageUrl(string $imageUrl): static
    {
        $this->imageUrl = $imageUrl;
        return $this;
    }

    public function getSteamId(): ?string
    {
        return $this->steamId;
    }

    public function setSteamId(string $steamId): static
    {
        $this->steamId = $steamId;
        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getHashName(): ?string
    {
        return $this->hashName;
    }

    public function setHashName(string $hashName): static
    {
        $this->hashName = $hashName;
        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function getSubcategory(): ?string
    {
        return $this->subcategory;
    }

    public function setSubcategory(?string $subcategory): static
    {
        $this->subcategory = $subcategory;
        return $this;
    }

    public function getRarity(): ?string
    {
        return $this->rarity;
    }

    public function setRarity(string $rarity): static
    {
        $this->rarity = $rarity;
        return $this;
    }

    public function getRarityColor(): ?string
    {
        return $this->rarityColor;
    }

    public function setRarityColor(?string $rarityColor): static
    {
        $this->rarityColor = $rarityColor;
        return $this;
    }

    public function getCollection(): ?string
    {
        return $this->collection;
    }

    public function setCollection(?string $collection): static
    {
        $this->collection = $collection;
        return $this;
    }

    public function isStattrakAvailable(): bool
    {
        return $this->stattrakAvailable;
    }

    public function setStattrakAvailable(bool $stattrakAvailable): static
    {
        $this->stattrakAvailable = $stattrakAvailable;
        return $this;
    }

    public function isSouvenirAvailable(): bool
    {
        return $this->souvenirAvailable;
    }

    public function setSouvenirAvailable(bool $souvenirAvailable): static
    {
        $this->souvenirAvailable = $souvenirAvailable;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getIconUrlLarge(): ?string
    {
        return $this->iconUrlLarge;
    }

    public function setIconUrlLarge(?string $iconUrlLarge): static
    {
        $this->iconUrlLarge = $iconUrlLarge;
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
     * @return Collection<int, ItemPrice>
     */
    public function getPriceHistory(): Collection
    {
        return $this->priceHistory;
    }

    public function addPriceHistory(ItemPrice $priceHistory): static
    {
        if (!$this->priceHistory->contains($priceHistory)) {
            $this->priceHistory->add($priceHistory);
            $priceHistory->setItem($this);
        }

        return $this;
    }

    public function removePriceHistory(ItemPrice $priceHistory): static
    {
        if ($this->priceHistory->removeElement($priceHistory)) {
            if ($priceHistory->getItem() === $this) {
                $priceHistory->setItem(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ItemUser>
     */
    public function getUserInstances(): Collection
    {
        return $this->userInstances;
    }

    public function addUserInstance(ItemUser $userInstance): static
    {
        if (!$this->userInstances->contains($userInstance)) {
            $this->userInstances->add($userInstance);
            $userInstance->setItem($this);
        }

        return $this;
    }

    public function removeUserInstance(ItemUser $userInstance): static
    {
        if ($this->userInstances->removeElement($userInstance)) {
            if ($userInstance->getItem() === $this) {
                $userInstance->setItem(null);
            }
        }

        return $this;
    }
}