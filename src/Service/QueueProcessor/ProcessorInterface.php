<?php

namespace App\Service\QueueProcessor;

use App\Entity\ProcessQueue;

interface ProcessorInterface
{
    /**
     * Process a single queue item
     *
     * @param ProcessQueue $queueItem
     * @throws \Exception on processing failure
     */
    public function process(ProcessQueue $queueItem): void;

    /**
     * Get the process type this processor handles
     *
     * @return string
     */
    public function getProcessType(): string;

    /**
     * Get unique name for this processor
     * Used to track which processors have completed for each queue item
     *
     * @return string
     */
    public function getProcessorName(): string;
}
