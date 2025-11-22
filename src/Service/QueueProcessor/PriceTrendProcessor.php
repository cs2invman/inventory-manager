<?php

namespace App\Service\QueueProcessor;

use App\Entity\ProcessQueue;
use App\Service\PriceTrendCalculator;
use Psr\Log\LoggerInterface;

class PriceTrendProcessor implements ProcessorInterface
{
    public function __construct(
        private PriceTrendCalculator $trendCalculator,
        private LoggerInterface $logger
    ) {}

    public function process(ProcessQueue $queueItem): void
    {
        $item = $queueItem->getItem();

        try {
            $trends = $this->trendCalculator->calculateAndUpdateTrends($item);

            if ($trends['skipped']) {
                // Item has no current price - skip silently
                $this->logger->debug('Skipped price trend calculation (no current price)', [
                    'item_id' => $item->getId(),
                    'item_name' => $item->getName()
                ]);
                return;
            }

            $this->logger->info('Price trends calculated', [
                'item_id' => $item->getId(),
                'trend_24h' => $trends['trend24h'],
                'trend_7d' => $trends['trend7d'],
                'trend_30d' => $trends['trend30d']
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to calculate price trends', [
                'item_id' => $item->getId(),
                'error' => $e->getMessage()
            ]);

            throw $e; // Re-throw to mark this processor as failed
        }
    }

    public function getProcessType(): string
    {
        return ProcessQueue::TYPE_PRICE_UPDATED;
    }

    public function getProcessorName(): string
    {
        return 'price_trend_calculator';
    }
}
