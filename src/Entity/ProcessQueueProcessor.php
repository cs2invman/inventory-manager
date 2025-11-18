<?php

namespace App\Entity;

use App\Repository\ProcessQueueProcessorRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProcessQueueProcessorRepository::class)]
#[ORM\Table(name: 'process_queue_processor')]
#[ORM\Index(name: 'idx_queue_status', columns: ['process_queue_id', 'status'])]
#[ORM\UniqueConstraint(
    name: 'uniq_queue_processor',
    columns: ['process_queue_id', 'processor_name']
)]
class ProcessQueueProcessor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ProcessQueue::class, inversedBy: 'processorTracking')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ProcessQueue $processQueue;

    #[ORM\Column(type: 'string', length: 100)]
    private string $processorName;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = 'pending'; // pending, processing, completed, failed

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $completedAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $failedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: 'integer')]
    private int $attempts = 0;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProcessQueue(): ProcessQueue
    {
        return $this->processQueue;
    }

    public function setProcessQueue(ProcessQueue $processQueue): self
    {
        $this->processQueue = $processQueue;
        return $this;
    }

    public function getProcessorName(): string
    {
        return $this->processorName;
    }

    public function setProcessorName(string $processorName): self
    {
        $this->processorName = $processorName;
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

    public function getCompletedAt(): ?\DateTimeInterface
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeInterface $completedAt): self
    {
        $this->completedAt = $completedAt;
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
}
