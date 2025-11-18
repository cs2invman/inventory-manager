<?php

namespace App\Entity;

use App\Repository\ProcessQueueRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProcessQueueRepository::class)]
#[ORM\Table(name: 'process_queue')]
#[ORM\Index(name: 'idx_status_created', columns: ['status', 'created_at'])]
#[ORM\Index(name: 'idx_process_type', columns: ['process_type'])]
class ProcessQueue
{
    public const TYPE_PRICE_UPDATED = 'PRICE_UPDATED';
    public const TYPE_NEW_ITEM = 'NEW_ITEM';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 50)]
    private string $processType;

    #[ORM\ManyToOne(targetEntity: Item::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Item $item;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = 'pending'; // pending, processing, failed

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $failedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: 'integer')]
    private int $attempts = 0;

    /** @var Collection<int, ProcessQueueProcessor> */
    #[ORM\OneToMany(targetEntity: ProcessQueueProcessor::class, mappedBy: 'processQueue', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $processorTracking;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->processorTracking = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProcessType(): string
    {
        return $this->processType;
    }

    public function setProcessType(string $processType): self
    {
        $this->processType = $processType;
        return $this;
    }

    public function getItem(): Item
    {
        return $this->item;
    }

    public function setItem(Item $item): self
    {
        $this->item = $item;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getFailedAt(): ?\DateTimeInterface
    {
        return $this->failedAt;
    }

    public function setFailedAt(?\DateTimeInterface $failedAt): self
    {
        $this->failedAt = $failedAt;
        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;
        return $this;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function setAttempts(int $attempts): self
    {
        $this->attempts = $attempts;
        return $this;
    }

    /**
     * @return Collection<int, ProcessQueueProcessor>
     */
    public function getProcessorTracking(): Collection
    {
        return $this->processorTracking;
    }

    public function addProcessorTracking(ProcessQueueProcessor $processorTracking): self
    {
        if (!$this->processorTracking->contains($processorTracking)) {
            $this->processorTracking->add($processorTracking);
            $processorTracking->setProcessQueue($this);
        }

        return $this;
    }

    public function removeProcessorTracking(ProcessQueueProcessor $processorTracking): self
    {
        if ($this->processorTracking->removeElement($processorTracking)) {
            // set the owning side to null (unless already changed)
            if ($processorTracking->getProcessQueue() === $this) {
                $processorTracking->setProcessQueue(null);
            }
        }

        return $this;
    }
}
