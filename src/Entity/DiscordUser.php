<?php

namespace App\Entity;

use App\Repository\DiscordUserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DiscordUserRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_DISCORD_ID', fields: ['discordId'])]
#[ORM\Index(name: 'IDX_DISCORD_USER_USER', columns: ['user_id'])]
#[UniqueEntity(fields: ['discordId'], message: 'This Discord account is already linked to another user.')]
class DiscordUser
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class, inversedBy: 'discordUser')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 20)]
    #[Assert\NotBlank(message: 'Discord ID cannot be blank.')]
    #[Assert\Regex(
        pattern: '/^\d{17,20}$/',
        message: 'Discord ID must be a numeric string between 17 and 20 characters.'
    )]
    private ?string $discordId = null;

    #[ORM\Column(length: 100)]
    private ?string $discordUsername = null;

    #[ORM\Column(length: 4, nullable: true)]
    private ?string $discordDiscriminator = null;

    #[ORM\Column]
    private ?bool $isVerified = false;

    #[ORM\Column]
    private ?\DateTimeImmutable $linkedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastCommandAt = null;

    public function __construct()
    {
        $this->linkedAt = new \DateTimeImmutable();
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

    public function getDiscordId(): ?string
    {
        return $this->discordId;
    }

    public function setDiscordId(string $discordId): static
    {
        $this->discordId = $discordId;
        return $this;
    }

    public function getDiscordUsername(): ?string
    {
        return $this->discordUsername;
    }

    public function setDiscordUsername(string $discordUsername): static
    {
        $this->discordUsername = $discordUsername;
        return $this;
    }

    public function getDiscordDiscriminator(): ?string
    {
        return $this->discordDiscriminator;
    }

    public function setDiscordDiscriminator(?string $discordDiscriminator): static
    {
        $this->discordDiscriminator = $discordDiscriminator;
        return $this;
    }

    public function getIsVerified(): ?bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): static
    {
        $this->isVerified = $isVerified;

        if ($isVerified && $this->verifiedAt === null) {
            $this->verifiedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getLinkedAt(): ?\DateTimeImmutable
    {
        return $this->linkedAt;
    }

    public function setLinkedAt(\DateTimeImmutable $linkedAt): static
    {
        $this->linkedAt = $linkedAt;
        return $this;
    }

    public function getVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function setVerifiedAt(?\DateTimeImmutable $verifiedAt): static
    {
        $this->verifiedAt = $verifiedAt;
        return $this;
    }

    public function getLastCommandAt(): ?\DateTimeImmutable
    {
        return $this->lastCommandAt;
    }

    public function setLastCommandAt(?\DateTimeImmutable $lastCommandAt): static
    {
        $this->lastCommandAt = $lastCommandAt;
        return $this;
    }

    /**
     * Get formatted Discord username with discriminator (if present).
     */
    public function getFormattedUsername(): string
    {
        if ($this->discordDiscriminator !== null) {
            return $this->discordUsername . '#' . $this->discordDiscriminator;
        }

        return $this->discordUsername ?? '';
    }

    public function __toString(): string
    {
        return $this->getFormattedUsername();
    }
}
