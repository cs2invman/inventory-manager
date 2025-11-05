<?php

namespace App\Service;

use App\Entity\Item;
use App\Entity\ItemPrice;
use App\Repository\ItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for synchronizing CS2 item data from JSON files to the database
 *
 * Handles parsing JSON data from SteamWebAPI and syncing it with the database,
 * including creating/updating items and price history records.
 */
class ItemSyncService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ItemRepository $itemRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Sync items from a JSON file to the database
     *
     * @param string $filePath Path to the JSON file containing item data
     * @param bool $skipPrices Whether to skip creating price history records
     * @param callable|null $progressCallback Optional callback to report progress (receives current index)
     * @param array|null $accumulatedExternalIds Array to accumulate external IDs across multiple syncs (for deferred deactivation)
     * @param bool $deferDeactivation Whether to skip deactivation step (for chunked processing)
     * @return array Statistics about the sync operation
     */
    public function syncFromJsonFile(
        string $filePath,
        bool $skipPrices = false,
        ?callable $progressCallback = null,
        ?array &$accumulatedExternalIds = null,
        bool $deferDeactivation = false
    ): array
    {
        $this->logger->info('Starting item sync from JSON file', [
            'file' => $filePath,
            'skip_prices' => $skipPrices,
        ]);

        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("File not found: {$filePath}");
        }

        if (!is_readable($filePath)) {
            throw new \InvalidArgumentException("File is not readable: {$filePath}");
        }

        // Read and parse JSON
        $jsonContent = file_get_contents($filePath);
        $itemsData = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON file: ' . json_last_error_msg());
        }

        if (!is_array($itemsData)) {
            throw new \RuntimeException('Expected JSON array of items');
        }

        // Initialize statistics
        $stats = [
            'added' => 0,
            'updated' => 0,
            'deactivated' => 0,
            'price_records_created' => 0,
            'skipped' => 0,
            'total' => count($itemsData),
        ];

        $processedExternalIds = [];

        // Process in smaller independent batches to avoid memory issues
        $batchSize = 50;

        for ($batchStart = 0; $batchStart < $stats['total']; $batchStart += $batchSize) {
            // Begin transaction for this batch
            $this->entityManager->beginTransaction();

            try {
                $batchEnd = min($batchStart + $batchSize, $stats['total']);

                for ($index = $batchStart; $index < $batchEnd; $index++) {
                    $itemData = $itemsData[$index];

                    try {
                        $result = $this->processItem($itemData, $skipPrices);

                        if ($result['action'] === 'added') {
                            $stats['added']++;
                        } elseif ($result['action'] === 'updated') {
                            $stats['updated']++;
                        } elseif ($result['action'] === 'skipped') {
                            $stats['skipped']++;
                        }

                        if ($result['price_created']) {
                            $stats['price_records_created']++;
                        }

                        if (isset($result['external_id'])) {
                            $processedExternalIds[] = $result['external_id'];

                            // Also add to accumulated IDs if provided (for chunked processing)
                            if ($accumulatedExternalIds !== null) {
                                $accumulatedExternalIds[] = $result['external_id'];
                            }
                        }

                    } catch (\Throwable $e) {
                        $this->logger->warning('Failed to process item', [
                            'index' => $index,
                            'error' => $e->getMessage(),
                            'item_data' => $itemData['id'] ?? 'unknown',
                        ]);
                        $stats['skipped']++;
                    }
                }

                // Flush and commit this batch
                $this->entityManager->flush();
                $this->entityManager->commit();

                // Clear entity manager and force garbage collection
                $this->entityManager->clear();
                gc_collect_cycles();

                // Report progress after each batch
                if ($progressCallback !== null) {
                    $progressCallback($batchEnd);
                }

            } catch (\Throwable $e) {
                // Rollback this batch
                if ($this->entityManager->getConnection()->isTransactionActive()) {
                    $this->entityManager->rollback();
                }

                $this->logger->error('Batch failed', [
                    'batch_start' => $batchStart,
                    'batch_end' => $batchEnd ?? $batchStart,
                    'error' => $e->getMessage(),
                ]);

                // Continue with next batch despite this failure
                $this->entityManager->clear();
                gc_collect_cycles();
            }
        }

        // Deactivate items not in the current sync (separate transaction)
        // Skip this step if deferred deactivation is enabled (for chunked processing)
        if (!$deferDeactivation) {
            try {
                $this->entityManager->beginTransaction();
                $stats['deactivated'] = $this->deactivateMissingItems($processedExternalIds);
                $this->entityManager->commit();
            } catch (\Throwable $e) {
                if ($this->entityManager->getConnection()->isTransactionActive()) {
                    $this->entityManager->rollback();
                }
                $this->logger->warning('Failed to deactivate missing items', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('Item sync completed successfully', $stats);

        return $stats;
    }

    /**
     * Process a single item from the API data
     *
     * @param array $itemData Item data from the API
     * @param bool $skipPrices Whether to skip creating price records
     * @return array Result information
     */
    private function processItem(array $itemData, bool $skipPrices): array
    {
        $result = [
            'action' => 'skipped',
            'price_created' => false,
            'external_id' => null,
        ];

        // Extract external ID (required)
        $externalId = $itemData['id'] ?? null;
        if (!$externalId) {
            $this->logger->debug('Skipping item without external ID');
            return $result;
        }

        $result['external_id'] = $externalId;

        // Find existing item by external ID
        $item = $this->itemRepository->findOneBy(['externalId' => $externalId]);

        if (!$item) {
            // Create new item
            $item = new Item();
            $item->setExternalId($externalId);
            $result['action'] = 'added';

            // Set required fields with defaults for new items
            $item->setSteamId($itemData['classid'] ?? 'unknown');
            $item->setType($itemData['quality'] ?? 'Normal');
            $item->setCategory('Weapon'); // Default, can be improved later
            $item->setRarity($itemData['rarity'] ?? 'Common');
        } else {
            $result['action'] = 'updated';
        }

        // Map API fields to Item entity
        $this->mapItemFields($item, $itemData);

        // Ensure item is active
        $item->setActive(true);

        // Persist item
        $this->entityManager->persist($item);

        // Create price history record if not skipping prices
        if (!$skipPrices && $this->hasPriceData($itemData)) {
            $priceCreated = $this->createPriceHistory($item, $itemData);
            $result['price_created'] = $priceCreated;
        }

        return $result;
    }

    /**
     * Map API fields to Item entity properties
     */
    private function mapItemFields(Item $item, array $data): void
    {
        // Core fields
        if (isset($data['markethashname'])) {
            $item->setHashName($data['markethashname']);
        }

        if (isset($data['marketname'])) {
            $item->setName($data['marketname']);
            $item->setMarketName($data['marketname']);
        }

        if (isset($data['itemimage'])) {
            $item->setImageUrl($data['itemimage']);
        }

        // New API-specific fields
        if (isset($data['slug'])) {
            $item->setSlug($data['slug']);
        }

        if (isset($data['classid'])) {
            $item->setClassId($data['classid']);
        }

        if (isset($data['instanceid'])) {
            $item->setInstanceId($data['instanceid']);
        }

        if (isset($data['groupid'])) {
            $item->setGroupId($data['groupid']);
        }

        if (isset($data['bordercolor'])) {
            $item->setBorderColor($data['bordercolor']);
        }

        if (isset($data['color'])) {
            $item->setItemColor($data['color']);
        }

        if (isset($data['quality'])) {
            $item->setQuality($data['quality']);
        }

        if (isset($data['rarity'])) {
            $item->setRarity($data['rarity']);
            // Set rarity color based on rarity name
            $rarityColor = $this->getRarityColor($data['rarity']);
            $item->setRarityColor($rarityColor);
        }

        if (isset($data['points'])) {
            $item->setPoints((int) $data['points']);
        }
    }

    /**
     * Map CS2 rarity names to their hex colors
     */
    private function getRarityColor(string $rarity): string
    {
        $colorMap = [
            'Covert' => '#eb4b4b',
            'Extraordinary' => '#eb4b4b',
            'Exotic' => '#eb4b4b',
            'Contraband' => '#e4ae39',  // Gold for contraband (special case)
            'Classified' => '#d32ce6',
            'Exceptional' => '#d32ce6',
            'Restricted' => '#8847ff',
            'Master' => '#e4ae39',
            'Remarkable' => '#e4ae39',
            'Mil-Spec Grade' => '#4b69ff',
            'Distinguished' => '#4b69ff',
            'Superior' => '#4b69ff',
            'High Grade' => '#5e98d9',
            'Industrial Grade' => '#5e98d9',
            'Base Grade' => '#b0c3d9',
            'Consumer Grade' => '#b0c3d9',
            'Common' => '#b0c3d9',
        ];

        return $colorMap[$rarity] ?? '#b0c3d9'; // Default to gray if unknown
    }

    /**
     * Check if the item data contains price information
     */
    private function hasPriceData(array $itemData): bool
    {
        return isset($itemData['pricelatestsell'])
            || isset($itemData['pricemedian'])
            || isset($itemData['pricemin'])
            || isset($itemData['pricemax']);
    }

    /**
     * Create a price history record for an item
     *
     * @return bool True if price record was created, false if skipped (duplicate)
     */
    private function createPriceHistory(Item $item, array $priceData): bool
    {
        // Set price date from API or use current time
        $priceDate = null;
        if (isset($priceData['priceupdatedat']['date'])) {
            try {
                $priceDate = new \DateTimeImmutable($priceData['priceupdatedat']['date']);
            } catch (\Exception $e) {
                $this->logger->debug('Failed to parse price date, using current time', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Check if a price record already exists for this timestamp
        // This prevents duplicate records when re-syncing the same data
        if ($priceDate !== null) {
            $existingPrice = $this->entityManager->getRepository(ItemPrice::class)
                ->findOneBy([
                    'item' => $item,
                    'priceDate' => $priceDate,
                    'source' => 'steamwebapi'
                ]);

            if ($existingPrice) {
                $this->logger->debug('Skipping duplicate price record', [
                    'item_id' => $item->getId(),
                    'external_id' => $item->getExternalId(),
                    'price_date' => $priceDate->format('Y-m-d H:i:s'),
                ]);
                return false;
            }
        }

        $itemPrice = new ItemPrice();
        $itemPrice->setItem($item);
        $itemPrice->setSource('steamwebapi');

        if ($priceDate !== null) {
            $itemPrice->setPriceDate($priceDate);
        }

        // Map price fields
        if (isset($priceData['pricelatestsell']) && $priceData['pricelatestsell'] !== null) {
            $itemPrice->setPrice((string) $priceData['pricelatestsell']);
        }

        if (isset($priceData['pricemedian']) && $priceData['pricemedian'] !== null) {
            $itemPrice->setMedianPrice((string) $priceData['pricemedian']);
        }

        if (isset($priceData['pricemin']) && $priceData['pricemin'] !== null) {
            $itemPrice->setLowestPrice((string) $priceData['pricemin']);
        }

        if (isset($priceData['pricemax']) && $priceData['pricemax'] !== null) {
            $itemPrice->setHighestPrice((string) $priceData['pricemax']);
        }

        // Set volume from sold data
        if (isset($priceData['soldtotal']) && $priceData['soldtotal'] !== null) {
            $itemPrice->setVolume((int) $priceData['soldtotal']);
        } elseif (isset($priceData['sold30d']) && $priceData['sold30d'] !== null) {
            $itemPrice->setVolume((int) $priceData['sold30d']);
        }

        // Only persist if we have at least a price value
        if ($itemPrice->getPrice() !== null) {
            $this->entityManager->persist($itemPrice);
            return true;
        }

        return false;
    }

    /**
     * Mark items as inactive if they are not in the current sync
     *
     * @param array $currentExternalIds Array of external IDs from current sync
     * @return int Number of items deactivated
     */
    public function deactivateMissingItems(array $currentExternalIds): int
    {
        if (empty($currentExternalIds)) {
            return 0;
        }

        $qb = $this->entityManager->createQueryBuilder();
        $qb->update(Item::class, 'i')
            ->set('i.active', ':inactive')
            ->where('i.externalId IS NOT NULL')
            ->andWhere($qb->expr()->notIn('i.externalId', ':current_ids'))
            ->andWhere('i.active = :active')
            ->setParameter('inactive', false)
            ->setParameter('current_ids', $currentExternalIds)
            ->setParameter('active', true);

        $count = $qb->getQuery()->execute();

        $this->logger->info('Deactivated missing items', [
            'count' => $count,
        ]);

        return $count;
    }
}