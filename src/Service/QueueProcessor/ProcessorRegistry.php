<?php

namespace App\Service\QueueProcessor;

class ProcessorRegistry
{
    /** @var array<string, array<ProcessorInterface>> */
    private array $processors = [];

    /**
     * Register a processor
     */
    public function register(ProcessorInterface $processor): void
    {
        $processType = $processor->getProcessType();

        if (!isset($this->processors[$processType])) {
            $this->processors[$processType] = [];
        }

        $this->processors[$processType][] = $processor;
    }

    /**
     * Get all processors for a process type
     *
     * @return ProcessorInterface[]
     * @throws \RuntimeException if no processors found for type
     */
    public function getProcessors(string $processType): array
    {
        if (!isset($this->processors[$processType]) || empty($this->processors[$processType])) {
            throw new \RuntimeException(
                sprintf('No processors registered for type: %s', $processType)
            );
        }

        return $this->processors[$processType];
    }

    /**
     * Get specific processor by name
     *
     * @throws \RuntimeException if processor not found
     */
    public function getProcessorByName(string $processorName): ProcessorInterface
    {
        foreach ($this->processors as $processors) {
            foreach ($processors as $processor) {
                if ($processor->getProcessorName() === $processorName) {
                    return $processor;
                }
            }
        }

        throw new \RuntimeException(
            sprintf('No processor found with name: %s', $processorName)
        );
    }

    /**
     * Check if processor exists for type
     */
    public function hasProcessor(string $processType): bool
    {
        return isset($this->processors[$processType]) && !empty($this->processors[$processType]);
    }

    /**
     * Get all registered processor types
     *
     * @return array<string>
     */
    public function getRegisteredTypes(): array
    {
        return array_keys($this->processors);
    }

    /**
     * Get processor names for a specific process type
     *
     * @return array<string>
     */
    public function getProcessorNames(string $processType): array
    {
        if (!isset($this->processors[$processType])) {
            return [];
        }

        return array_map(
            fn(ProcessorInterface $p) => $p->getProcessorName(),
            $this->processors[$processType]
        );
    }
}
