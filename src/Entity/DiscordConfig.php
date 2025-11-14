<?php

namespace App\Entity;

use App\Repository\DiscordConfigRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DiscordConfigRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_CONFIG_KEY', fields: ['configKey'])]
#[UniqueEntity(fields: ['configKey'], message: 'This configuration key already exists.')]
class DiscordConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Configuration key cannot be blank.')]
    private ?string $configKey = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $configValue = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private ?bool $isEnabled = true;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConfigKey(): ?string
    {
        return $this->configKey;
    }

    public function setConfigKey(string $configKey): static
    {
        $this->configKey = $configKey;
        return $this;
    }

    public function getConfigValue(): ?string
    {
        return $this->configValue;
    }

    public function setConfigValue(?string $configValue): static
    {
        $this->configValue = $configValue;
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getIsEnabled(): ?bool
    {
        return $this->isEnabled;
    }

    public function setIsEnabled(bool $isEnabled): static
    {
        $this->isEnabled = $isEnabled;
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

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * Helper method to get value as JSON array.
     *
     * @return array<mixed>|null
     */
    public function getValueAsJson(): ?array
    {
        if ($this->configValue === null) {
            return null;
        }

        $decoded = json_decode($this->configValue, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Helper method to set value from JSON array.
     *
     * @param array<mixed> $value
     */
    public function setValueAsJson(array $value): static
    {
        $this->configValue = json_encode($value);
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function __toString(): string
    {
        return $this->configKey ?? '';
    }
}
