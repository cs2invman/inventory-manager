<?php

namespace App\Entity;

use App\Repository\UserConfigRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * User configuration entity to store user-specific settings and preferences.
 *
 * This entity has a one-to-one relationship with the User entity and stores
 * configuration data such as Steam ID for dynamic inventory links. It is designed
 * to be easily extensible for future configuration fields without requiring
 * database migrations for every new setting.
 */
#[ORM\Entity(repositoryClass: UserConfigRepository::class)]
#[ORM\Table(name: 'user_config')]
class UserConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * One-to-one relationship with User entity.
     * This is the owning side of the relationship.
     */
    #[ORM\OneToOne(inversedBy: 'config', targetEntity: User::class, cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    /**
     * User's Steam ID (SteamID64 format - 17 digits).
     * Used to generate dynamic Steam inventory URLs.
     */
    #[ORM\Column(length: 17, nullable: true)]
    private ?string $steamId = null;

    /**
     * Timestamp when the configuration was created.
     */
    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    /**
     * Timestamp of the last configuration update.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * User's preferred currency for displaying prices (USD or CAD).
     * Defaults to 'USD' in the database.
     */
    #[ORM\Column(length: 3, nullable: true, options: ['default' => 'USD'])]
    private ?string $preferredCurrency = null;

    /**
     * User-configurable exchange rate for CAD conversion.
     * Defaults to 1.3800 in the database.
     */
    #[ORM\Column(type: 'decimal', precision: 8, scale: 4, nullable: true, options: ['default' => '1.3800'])]
    private ?string $cadExchangeRate = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getSteamId(): ?string
    {
        return $this->steamId;
    }

    public function setSteamId(?string $steamId): static
    {
        $this->steamId = $steamId;
        $this->updatedAt = new \DateTimeImmutable();
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

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getPreferredCurrency(): ?string
    {
        return $this->preferredCurrency;
    }

    public function setPreferredCurrency(?string $preferredCurrency): static
    {
        $this->preferredCurrency = $preferredCurrency;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getCadExchangeRate(): ?string
    {
        return $this->cadExchangeRate;
    }

    public function setCadExchangeRate(?string $cadExchangeRate): static
    {
        $this->cadExchangeRate = $cadExchangeRate;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }
}
