<?php

namespace App\Entity;

use App\Repository\StorageBoxRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StorageBoxRepository::class)]
#[ORM\HasLifecycleCallbacks]
class StorageBox
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'storageBoxes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 100, unique: true, nullable: true)]
    private ?string $assetId = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column]
    private int $itemCount = 0;

    #[ORM\Column(nullable: true)]
    private ?int $reportedCount = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $modificationDate = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
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

    public function getId(): ?int
    {
        return $this->id;
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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getItemCount(): int
    {
        return $this->itemCount;
    }

    public function setItemCount(int $itemCount): static
    {
        $this->itemCount = $itemCount;
        return $this;
    }

    public function getReportedCount(): ?int
    {
        return $this->reportedCount;
    }

    public function setReportedCount(?int $reportedCount): static
    {
        $this->reportedCount = $reportedCount;
        return $this;
    }

    public function getModificationDate(): ?\DateTimeImmutable
    {
        return $this->modificationDate;
    }

    public function setModificationDate(?\DateTimeImmutable $modificationDate): static
    {
        $this->modificationDate = $modificationDate;
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
     * Check if this is a manually created storage box (not from Steam import)
     */
    public function isManualBox(): bool
    {
        return $this->assetId === null;
    }

    /**
     * Check if this is a Steam-imported storage box
     */
    public function isSteamBox(): bool
    {
        return $this->assetId !== null;
    }
}
