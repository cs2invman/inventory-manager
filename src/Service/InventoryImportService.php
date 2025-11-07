<?php

namespace App\Service;

use App\DTO\ImportPreview;
use App\DTO\ImportResult;
use App\Entity\Item;
use App\Entity\ItemPrice;
use App\Entity\ItemUser;
use App\Entity\User;
use App\Repository\ItemPriceRepository;
use App\Repository\ItemRepository;
use App\Repository\ItemUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class InventoryImportService
{
    private const SESSION_PREFIX = 'inventory_import_';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ItemRepository $itemRepository,
        private ItemUserRepository $itemUserRepository,
        private ItemPriceRepository $itemPriceRepository,
        private RequestStack $requestStack,
        private LoggerInterface $logger,
        private StorageBoxService $storageBoxService
    ) {
    }

    /**
     * Parse and prepare import preview data (does not persist to database)
     */
    public function prepareImportPreview(User $user, string $tradeableJson, string $tradeLockedJson): ImportPreview
    {
        $errors = [];
        $parsedItems = [];
        $unmatchedItems = [];
        $storageBoxesData = [];

        // Parse both JSON inputs
        try {
            $tradeableData = json_decode($tradeableJson, true, 512, JSON_THROW_ON_ERROR);
            $parsedTradeableItems = $this->parseInventoryResponse($tradeableData);
            $parsedItems = array_merge($parsedItems, $parsedTradeableItems);

            // Extract storage boxes
            $storageBoxesData = array_merge(
                $storageBoxesData,
                $this->storageBoxService->extractStorageBoxesFromJson($tradeableData)
            );
        } catch (\JsonException $e) {
            $errors[] = 'Invalid JSON in tradeable items: ' . $e->getMessage();
        }

        try {
            $tradeLockedData = json_decode($tradeLockedJson, true, 512, JSON_THROW_ON_ERROR);
            $parsedTradeLockedItems = $this->parseInventoryResponse($tradeLockedData);
            $parsedItems = array_merge($parsedItems, $parsedTradeLockedItems);

            // Extract storage boxes
            $storageBoxesData = array_merge(
                $storageBoxesData,
                $this->storageBoxService->extractStorageBoxesFromJson($tradeLockedData)
            );
        } catch (\JsonException $e) {
            $errors[] = 'Invalid JSON in trade-locked items: ' . $e->getMessage();
        }

        // Deduplicate by assetId
        $uniqueItems = [];
        $seenAssetIds = [];
        foreach ($parsedItems as $item) {
            if (isset($seenAssetIds[$item['asset_id']])) {
                $this->logger->warning('Duplicate assetId detected during import', ['asset_id' => $item['asset_id']]);
                continue;
            }
            $seenAssetIds[$item['asset_id']] = true;
            $uniqueItems[] = $item;
        }
        $parsedItems = $uniqueItems;

        // Map to database entities and identify unmatched items
        $mappedItems = [];
        foreach ($parsedItems as $parsedItem) {
            // Try to find item by market_hash_name (more reliable than classId)
            $item = $this->findItemByHashName($parsedItem['market_hash_name']);

            if ($item === null) {
                $unmatchedItems[] = [
                    'name' => $parsedItem['name'] ?? 'Unknown',
                    'market_hash_name' => $parsedItem['market_hash_name'] ?? 'Unknown',
                    'class_id' => $parsedItem['class_id'],
                    'asset_id' => $parsedItem['asset_id'],
                ];
                continue;
            }

            $mappedItems[] = [
                'item' => $item,
                'data' => $parsedItem,
            ];
        }

        // Get current inventory for comparison
        $currentInventory = $this->itemUserRepository->findUserInventory($user->getId());

        // Get actual items to add/remove (not just counts)
        $itemsToAdd = $this->getItemsToAdd($mappedItems, $currentInventory);
        $itemsToRemove = $this->getItemsToRemove($mappedItems, $currentInventory);

        // Enrich items to add with price data
        $itemsToAddData = [];
        foreach ($itemsToAdd as $mappedItem) {
            $item = $mappedItem['item'];
            $data = $mappedItem['data'];

            // Look up latest price for this item
            $latestPrice = $this->getLatestPriceForItem($item);

            // Enrich stickers and keychain with prices
            $enrichedStickers = $this->enrichStickersWithPrices($data['stickers'] ?? null);
            $enrichedKeychain = $this->enrichKeychainWithPrice($data['keychain'] ?? null);

            // Calculate total price including stickers and keychain
            $basePrice = $latestPrice?->getMedianPrice() ?? 0;
            $stickerPrice = array_sum(array_column($enrichedStickers, 'price'));
            $keychainPrice = $enrichedKeychain['price'] ?? 0;
            $totalPrice = $basePrice + $stickerPrice + $keychainPrice;

            $itemsToAddData[] = [
                'itemUser' => $this->createItemUserFromData($item, $data), // temporary object for display
                'item' => $item,
                'price' => $latestPrice,
                'priceValue' => $totalPrice,
                'stickers' => $enrichedStickers,
                'keychain' => $enrichedKeychain,
                'assetId' => $data['asset_id'],
            ];
        }

        // Enrich items to remove with price data
        $itemsToRemoveData = [];
        foreach ($itemsToRemove as $itemUser) {
            $item = $itemUser->getItem();

            // Look up latest price
            $latestPrice = $this->getLatestPriceForItem($item);

            // Enrich stickers and keychain with prices
            $enrichedStickers = $this->enrichStickersWithPrices($itemUser->getStickers());
            $enrichedKeychain = $this->enrichKeychainWithPrice($itemUser->getKeychain());

            // Calculate total price including stickers and keychain
            $basePrice = $latestPrice?->getMedianPrice() ?? 0;
            $stickerPrice = array_sum(array_column($enrichedStickers, 'price'));
            $keychainPrice = $enrichedKeychain['price'] ?? 0;
            $totalPrice = $basePrice + $stickerPrice + $keychainPrice;

            $itemsToRemoveData[] = [
                'itemUser' => $itemUser,
                'item' => $item,
                'price' => $latestPrice,
                'priceValue' => $totalPrice,
                'stickers' => $enrichedStickers,
                'keychain' => $enrichedKeychain,
                'assetId' => $itemUser->getAssetId(),
            ];
        }

        // Log comparison results for debugging
        $this->logger->info('Import preview comparison results', [
            'total_items_in_import' => count($mappedItems),
            'items_to_add_count' => count($itemsToAddData),
            'items_to_remove_count' => count($itemsToRemoveData),
            'current_inventory_count' => count($currentInventory),
        ]);

        // Store parsed data in session (including storage boxes and enriched item data)
        $sessionKey = $this->storeInSession($mappedItems, $storageBoxesData, $itemsToAddData, $itemsToRemoveData);

        return new ImportPreview(
            totalItems: count($mappedItems),
            itemsToAdd: count($itemsToAddData),
            itemsToRemove: count($itemsToRemoveData),
            itemsToAddData: $itemsToAddData,
            itemsToRemoveData: $itemsToRemoveData,
            unmatchedItems: $unmatchedItems,
            errors: $errors,
            sessionKey: $sessionKey,
            storageBoxCount: count($storageBoxesData),
        );
    }

    /**
     * Execute the actual import from session data
     */
    public function executeImport(
        User $user,
        string $sessionKey,
        array $selectedAddIds = [],
        array $selectedRemoveIds = []
    ): ImportResult {
        $sessionData = $this->retrieveFromSession($sessionKey);

        if ($sessionData === null) {
            return new ImportResult(
                totalProcessed: 0,
                successCount: 0,
                errorCount: 1,
                errors: ['Session data not found or expired'],
                skippedItems: [],
            );
        }

        $mappedItems = $sessionData['items'];
        $storageBoxesData = $sessionData['storage_boxes'] ?? [];
        $itemsToRemoveData = $sessionData['items_to_remove'] ?? [];

        $totalProcessed = 0;
        $successCount = 0;
        $errorCount = 0;
        $errors = [];
        $skippedItems = [];
        $addedCount = 0;
        $removedCount = 0;

        $this->entityManager->beginTransaction();

        try {
            // Sync storage boxes FIRST
            if (!empty($storageBoxesData)) {
                $this->storageBoxService->syncStorageBoxes($user, $storageBoxesData);
            }

            // Extract assetIds from selected remove IDs
            $assetIdsToRemove = [];
            foreach ($selectedRemoveIds as $selectedId) {
                // ID format: "remove-{assetId}"
                $assetId = str_replace('remove-', '', $selectedId);

                // Validate that this assetId exists in items_to_remove
                $found = false;
                foreach ($itemsToRemoveData as $itemData) {
                    if ($itemData['assetId'] === $assetId) {
                        $assetIdsToRemove[] = $assetId;
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    $this->logger->warning('Selected remove ID not found in session', [
                        'selected_id' => $selectedId,
                        'asset_id' => $assetId
                    ]);
                }
            }

            // Delete only selected items
            if (!empty($assetIdsToRemove)) {
                $qb = $this->entityManager->createQueryBuilder();
                $qb->delete(ItemUser::class, 'iu')
                    ->where('iu.user = :user')
                    ->andWhere('iu.storageBox IS NULL')  // NEVER touch items in storage
                    ->andWhere('iu.assetId IN (:asset_ids)')
                    ->setParameter('user', $user)
                    ->setParameter('asset_ids', $assetIdsToRemove);

                $removedCount = $qb->getQuery()->execute();

                $this->logger->info('Deleted selected inventory items during import', [
                    'user_id' => $user->getId(),
                    'deleted_count' => $removedCount,
                    'selected_count' => count($assetIdsToRemove),
                ]);
            }

            // Create new ItemUser entities from selected items only
            foreach ($mappedItems as $mappedItem) {
                $data = $mappedItem['data'];
                $assetId = $data['asset_id'];

                // Check if this item was selected for addition
                $itemId = 'add-' . $assetId;
                if (!in_array($itemId, $selectedAddIds)) {
                    continue; // Skip unselected items
                }

                $totalProcessed++;

                try {
                    $itemUser = new ItemUser();
                    $itemUser->setUser($user);
                    $itemUser->setItem($mappedItem['item']);

                    // Map all fields
                    if (isset($data['asset_id'])) {
                        $itemUser->setAssetId($data['asset_id']);
                    }
                    if (isset($data['float_value'])) {
                        $itemUser->setFloatValue((string) $data['float_value']);
                    }
                    if (isset($data['pattern_index'])) {
                        $itemUser->setPatternIndex($data['pattern_index']);
                    }
                    if (isset($data['inspect_link'])) {
                        $itemUser->setInspectLink($data['inspect_link']);
                    }
                    if (isset($data['is_stattrak'])) {
                        $itemUser->setIsStattrak($data['is_stattrak']);
                    }
                    if (isset($data['is_souvenir'])) {
                        $itemUser->setIsSouvenir($data['is_souvenir']);
                    }
                    if (isset($data['stickers'])) {
                        $itemUser->setStickers($data['stickers']);
                    }
                    if (isset($data['keychain'])) {
                        $itemUser->setKeychain($data['keychain']);
                    }
                    if (isset($data['name_tag'])) {
                        $itemUser->setNameTag($data['name_tag']);
                    }

                    $this->entityManager->persist($itemUser);
                    $successCount++;
                    $addedCount++;
                } catch (\Exception $e) {
                    $errorCount++;
                    $errors[] = sprintf(
                        'Failed to import item %s: %s',
                        $data['name'] ?? $data['asset_id'] ?? 'unknown',
                        $e->getMessage()
                    );
                    $this->logger->error('Failed to import item', [
                        'asset_id' => $data['asset_id'] ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->entityManager->flush();
            $this->entityManager->commit();

            // Clear session data after successful import
            $this->clearFromSession($sessionKey);
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $this->logger->error('Import transaction failed', ['error' => $e->getMessage()]);

            return new ImportResult(
                totalProcessed: $totalProcessed,
                successCount: 0,
                errorCount: $totalProcessed,
                errors: ['Import failed: ' . $e->getMessage()],
                skippedItems: $skippedItems,
                addedCount: 0,
                removedCount: 0,
            );
        }

        return new ImportResult(
            totalProcessed: $totalProcessed,
            successCount: $successCount,
            errorCount: $errorCount,
            errors: $errors,
            skippedItems: $skippedItems,
            addedCount: $addedCount,
            removedCount: $removedCount,
        );
    }

    /**
     * Parse Steam inventory JSON response
     */
    public function parseInventoryResponse(array $jsonData): array
    {
        $assets = $jsonData['assets'] ?? [];
        $descriptions = $jsonData['descriptions'] ?? [];
        $assetProperties = $jsonData['asset_properties'] ?? [];

        // Index descriptions by classid_instanceid for fast lookup
        $descriptionsMap = [];
        foreach ($descriptions as $description) {
            $key = $description['classid'] . '_' . $description['instanceid'];
            $descriptionsMap[$key] = $description;
        }

        // Index asset properties by assetid for fast lookup
        $assetPropertiesMap = [];
        foreach ($assetProperties as $assetProperty) {
            $assetPropertiesMap[$assetProperty['assetid']] = $assetProperty['asset_properties'];
        }

        $parsedItems = [];

        foreach ($assets as $asset) {
            $assetId = $asset['assetid'];
            $classId = $asset['classid'];
            $instanceId = $asset['instanceid'];

            $key = $classId . '_' . $instanceId;
            $description = $descriptionsMap[$key] ?? null;

            if ($description === null) {
                $this->logger->warning('Description not found for asset', [
                    'asset_id' => $assetId,
                    'class_id' => $classId,
                    'instance_id' => $instanceId,
                ]);
                continue;
            }

            $properties = $assetPropertiesMap[$assetId] ?? null;

            // Skip non-tradeable collectibles (Storage Units, Medals, Coins, Graffiti, etc.)
            if ($this->shouldSkipItem($description)) {
                $this->logger->debug('Skipping non-tradeable item', [
                    'name' => $description['name'] ?? 'Unknown',
                    'type' => $this->extractItemType($description),
                ]);
                continue;
            }

            $parsedItem = $this->mapSteamItemToEntity($asset, $description, $properties);
            $parsedItems[] = $parsedItem;
        }

        return $parsedItems;
    }

    /**
     * Map Steam item data to ItemUser entity data
     */
    private function mapSteamItemToEntity(array $asset, array $description, ?array $assetProperties): array
    {
        $data = [
            'asset_id' => $asset['assetid'],
            'class_id' => $asset['classid'],
            'instance_id' => $asset['instanceid'],
            'name' => $description['name'] ?? null,
            'market_hash_name' => $description['market_hash_name'] ?? null,
        ];

        // Extract float value and pattern from asset_properties
        if ($assetProperties !== null) {
            $floatAndPattern = $this->extractFloatAndPattern($assetProperties);
            $data['float_value'] = $floatAndPattern['float_value'];
            $data['pattern_index'] = $floatAndPattern['pattern_index'];
        }

        // Extract inspect link from actions
        if (isset($description['actions']) && is_array($description['actions'])) {
            foreach ($description['actions'] as $action) {
                if (isset($action['link']) && str_contains($action['link'], 'csgo_econ_action_preview')) {
                    $data['inspect_link'] = $action['link'];
                    break;
                }
            }
        }

        // Extract StatTrak and Souvenir from name or tags
        $name = $description['name'] ?? '';
        $data['is_stattrak'] = str_contains($name, 'StatTrak™');
        $data['is_souvenir'] = str_contains($name, 'Souvenir');

        // Also check tags for confirmation
        if (isset($description['tags']) && is_array($description['tags'])) {
            foreach ($description['tags'] as $tag) {
                if (isset($tag['localized_tag_name']) && $tag['localized_tag_name'] === 'StatTrak™') {
                    $data['is_stattrak'] = true;
                }
                if (isset($tag['localized_tag_name']) && $tag['localized_tag_name'] === 'Souvenir') {
                    $data['is_souvenir'] = true;
                }
            }
        }

        // Extract stickers, keychain, and name tag from descriptions
        if (isset($description['descriptions']) && is_array($description['descriptions'])) {
            $stickers = $this->extractStickerInfo($description['descriptions']);
            if ($stickers !== null) {
                $data['stickers'] = $stickers;
            }

            $keychain = $this->extractKeychainInfo($description['descriptions']);
            if ($keychain !== null) {
                $data['keychain'] = $keychain;
            }

            $nameTag = $this->extractNameTag($description['descriptions']);
            if ($nameTag !== null) {
                $data['name_tag'] = $nameTag;
            }
        }

        return $data;
    }

    /**
     * Find Item entity by market hash name
     */
    private function findItemByHashName(?string $hashName): ?Item
    {
        if ($hashName === null) {
            return null;
        }

        return $this->itemRepository->findByHashName($hashName);
    }

    /**
     * Extract sticker information from HTML in descriptions
     */
    private function extractStickerInfo(array $descriptions): ?array
    {
        foreach ($descriptions as $desc) {
            if (($desc['name'] ?? '') === 'sticker_info' && isset($desc['value'])) {
                return $this->parseStickerHtml($desc['value']);
            }
        }

        return null;
    }

    /**
     * Parse sticker HTML to extract sticker data
     */
    private function parseStickerHtml(string $html): ?array
    {
        try {
            $stickers = [];

            // Use regex to extract sticker image URLs and titles
            preg_match_all('/<img[^>]+src="([^"]+)"[^>]+title="([^"]+)"/', $html, $matches, PREG_SET_ORDER);

            $slot = 0;
            foreach ($matches as $match) {
                $imageUrl = $match[1] ?? null;
                $title = $match[2] ?? null;

                if ($imageUrl && $title) {
                    // Detect if it's a sticker or patch and remove the prefix
                    $type = 'Sticker'; // Default to sticker
                    $name = $title;

                    if (preg_match('/^(Sticker|Patch):\s*(.+)$/', $title, $typeMatch)) {
                        $type = $typeMatch[1];
                        $name = $typeMatch[2];
                    }

                    $stickers[] = [
                        'slot' => $slot,
                        'name' => $name,
                        'type' => $type,
                        'image_url' => $imageUrl,
                        'wear' => null, // Not available in basic inventory API
                    ];
                    $slot++;
                }
            }

            return !empty($stickers) ? $stickers : null;
        } catch (\Exception $e) {
            $this->logger->error('Failed to parse sticker HTML', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Extract keychain information from HTML in descriptions
     */
    private function extractKeychainInfo(array $descriptions): ?array
    {
        foreach ($descriptions as $desc) {
            if (($desc['name'] ?? '') === 'keychain_info' && isset($desc['value'])) {
                return $this->parseKeychainHtml($desc['value']);
            }
        }

        return null;
    }

    /**
     * Parse keychain HTML to extract keychain data
     */
    private function parseKeychainHtml(string $html): ?array
    {
        try {
            // Use regex to extract keychain image URL and title
            // Format: <img width=64 height=48 src="..." title="Charm: Name">
            if (preg_match('/<img[^>]+src="([^"]+)"[^>]+title="([^"]+)"/', $html, $matches)) {
                $imageUrl = $matches[1] ?? null;
                $title = $matches[2] ?? null;

                if ($imageUrl && $title) {
                    // Remove "Charm: " prefix from title
                    $name = preg_replace('/^Charm:\s*/', '', $title);

                    return [
                        'name' => $name,
                        'image_url' => $imageUrl,
                    ];
                }
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->error('Failed to parse keychain HTML', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Extract name tag from descriptions
     */
    private function extractNameTag(array $descriptions): ?string
    {
        foreach ($descriptions as $desc) {
            if (($desc['name'] ?? '') === 'nametag' && isset($desc['value'])) {
                // Parse name tag from format: "Name Tag: 'tag_name'"
                if (preg_match("/Name Tag:\s*['\"]([^'\"]+)['\"]|['\"]([^'\"]+)['\"]/", $desc['value'], $matches)) {
                    return $matches[1] ?? $matches[2] ?? null;
                }
                return $desc['value'];
            }
        }

        return null;
    }

    /**
     * Extract float value and pattern from asset_properties
     */
    private function extractFloatAndPattern(array $assetProperties): array
    {
        $result = [
            'float_value' => null,
            'pattern_index' => null,
        ];

        foreach ($assetProperties as $property) {
            $propertyId = $property['propertyid'] ?? null;

            if ($propertyId == 2 && isset($property['float_value'])) {
                $result['float_value'] = (float) $property['float_value'];
            }

            if ($propertyId == 1 && isset($property['int_value'])) {
                $result['pattern_index'] = (int) $property['int_value'];
            }
        }

        return $result;
    }

    /**
     * Get items that will be added (exist in new import but not in current inventory)
     */
    private function getItemsToAdd(array $mappedItems, array $currentInventory): array
    {
        $newAssetIds = array_map(fn($m) => $m['data']['asset_id'], $mappedItems);
        $currentAssetIds = array_map(fn($item) => $item->getAssetId(), $currentInventory);

        $assetIdsToAdd = array_diff($newAssetIds, $currentAssetIds);

        // Return full mapped items that are new
        return array_filter($mappedItems, fn($m) => in_array($m['data']['asset_id'], $assetIdsToAdd));
    }

    /**
     * Get items that will be removed (exist in current inventory but not in new import)
     */
    private function getItemsToRemove(array $mappedItems, array $currentInventory): array
    {
        $newAssetIds = array_map(fn($m) => $m['data']['asset_id'], $mappedItems);
        $currentAssetIds = array_map(fn($item) => $item->getAssetId(), $currentInventory);

        $assetIdsToRemove = array_diff($currentAssetIds, $newAssetIds);

        // Return current ItemUser entities that are no longer present
        return array_filter($currentInventory, fn($item) => in_array($item->getAssetId(), $assetIdsToRemove));
    }

    /**
     * Create temporary ItemUser object from parsed data (not persisted to database)
     */
    private function createItemUserFromData(Item $item, array $data): ItemUser
    {
        $itemUser = new ItemUser();
        $itemUser->setItem($item);
        $itemUser->setAssetId($data['asset_id']);
        $itemUser->setFloatValue($data['float_value'] ?? null);
        $itemUser->setPatternIndex($data['pattern_index'] ?? null);
        $itemUser->setIsStattrak($data['is_stattrak'] ?? false);
        $itemUser->setIsSouvenir($data['is_souvenir'] ?? false);
        $itemUser->setStickers($data['stickers'] ?? null);
        $itemUser->setKeychain($data['keychain'] ?? null);
        $itemUser->setNameTag($data['name_tag'] ?? null);

        // Don't persist - this is just for preview display
        return $itemUser;
    }

    /**
     * Get the latest price for an item
     */
    private function getLatestPriceForItem(Item $item): ?ItemPrice
    {
        return $this->itemPriceRepository->findLatestPriceForItem($item->getId());
    }


    /**
     * Store mapped items in session
     */
    private function storeInSession(array $mappedItems, array $storageBoxesData, array $itemsToAddData, array $itemsToRemoveData): string
    {
        $session = $this->requestStack->getSession();
        $sessionKey = self::SESSION_PREFIX . bin2hex(random_bytes(16));

        $serializableData = [
            'items' => [],
            'storage_boxes' => $storageBoxesData,
            'items_to_add' => $itemsToAddData,
            'items_to_remove' => $itemsToRemoveData,
        ];

        foreach ($mappedItems as $mappedItem) {
            $serializableData['items'][] = [
                'item_id' => $mappedItem['item']->getId(),
                'data' => $mappedItem['data'],
            ];
        }

        $session->set($sessionKey, $serializableData);

        return $sessionKey;
    }

    /**
     * Retrieve mapped items from session
     */
    private function retrieveFromSession(string $sessionKey): ?array
    {
        $session = $this->requestStack->getSession();
        $serializableData = $session->get($sessionKey);

        if ($serializableData === null) {
            return null;
        }

        // Reconstruct mapped items with Item entities
        $mappedItems = [];
        foreach ($serializableData['items'] as $serializedItem) {
            $item = $this->itemRepository->find($serializedItem['item_id']);
            if ($item === null) {
                continue;
            }

            $mappedItems[] = [
                'item' => $item,
                'data' => $serializedItem['data'],
            ];
        }

        return [
            'items' => $mappedItems,
            'storage_boxes' => $serializableData['storage_boxes'] ?? [],
            'items_to_remove' => $serializableData['items_to_remove'] ?? []
        ];
    }

    /**
     * Clear session data
     */
    private function clearFromSession(string $sessionKey): void
    {
        $session = $this->requestStack->getSession();
        $session->remove($sessionKey);
    }

    /**
     * Determine if an item should be skipped (non-tradeable collectibles)
     *
     * Storage boxes ARE skipped here because they're handled separately by StorageBoxService
     */
    private function shouldSkipItem(array $description): bool
    {
        $itemType = $this->extractItemType($description);

        // Special handling for graffiti: allow sealed graffiti, skip unsealed
        if (in_array($itemType, ['CSGO_Type_Spray', 'Type_Spray'])) {
            $marketHashName = $description['market_hash_name'] ?? '';

            // Sealed graffiti have "Sealed Graffiti" in their market hash name
            if (str_contains($marketHashName, 'Sealed Graffiti')) {
                $this->logger->debug('Allowing sealed graffiti import', [
                    'name' => $description['name'] ?? 'Unknown',
                    'market_hash_name' => $marketHashName,
                ]);
                return false; // Do NOT skip - allow import
            }

            // Unsealed graffiti (consumable spray charges) - skip
            $this->logger->debug('Skipping unsealed graffiti', [
                'name' => $description['name'] ?? 'Unknown',
                'market_hash_name' => $marketHashName,
            ]);
            return true; // Skip
        }

        // Special handling for tools: skip Storage Units, allow tradeable tools
        if (in_array($itemType, ['CSGO_Type_Tool', 'Type_Tool'])) {
            $name = $description['name'] ?? '';
            $marketHashName = $description['market_hash_name'] ?? '';

            // Storage Units are handled separately by StorageBoxService
            if (str_contains($name, 'Storage Unit') || str_contains($marketHashName, 'Storage Unit')) {
                $this->logger->debug('Skipping Storage Unit (handled separately)', [
                    'name' => $name,
                    'market_hash_name' => $marketHashName,
                ]);
                return true; // Skip Storage Units
            }

            // Allow other tradeable tools
            $this->logger->debug('Allowing tradeable tool import', [
                'name' => $name,
                'market_hash_name' => $marketHashName,
            ]);
            return false; // Do NOT skip
        }

        // Skip non-tradeable item types (collectibles only)
        // Note: Music kits removed - they're all tradeable
        // Note: Tools removed - handled with special logic above
        $skipTypes = [
            'CSGO_Type_Collectible',       // Medals, Coins, Badges
            'Type_Collectible',            // Alternative collectible type
        ];

        if (in_array($itemType, $skipTypes)) {
            return true;
        }

        // Additional check: Skip items with "Collectible" tag category
        if (isset($description['tags']) && is_array($description['tags'])) {
            foreach ($description['tags'] as $tag) {
                $category = $tag['category'] ?? '';
                $internalName = $tag['internal_name'] ?? '';

                if ($category === 'Type' && in_array($internalName, $skipTypes)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Extract item type from description tags
     */
    private function extractItemType(array $description): ?string
    {
        if (!isset($description['tags']) || !is_array($description['tags'])) {
            return null;
        }

        foreach ($description['tags'] as $tag) {
            if (($tag['category'] ?? '') === 'Type') {
                return $tag['internal_name'] ?? null;
            }
        }

        return null;
    }

    /**
     * Enrich sticker data with price information
     */
    private function enrichStickersWithPrices(?array $stickers): array
    {
        if ($stickers === null || empty($stickers)) {
            return [];
        }

        $enrichedStickers = [];

        foreach ($stickers as $sticker) {
            $stickerName = $sticker['name'] ?? null;
            $stickerType = $sticker['type'] ?? 'Sticker';

            if ($stickerName === null) {
                continue;
            }

            // Construct market hash name (e.g., "Sticker | Name" or "Patch | Name")
            $marketHashName = $stickerType . ' | ' . $stickerName;

            // Look up item in database
            $stickerItem = $this->itemRepository->findByHashName($marketHashName);

            if ($stickerItem !== null) {
                $latestPrice = $this->getLatestPriceForItem($stickerItem);

                $enrichedStickers[] = [
                    'name' => $stickerName,
                    'type' => $stickerType,
                    'slot' => $sticker['slot'] ?? null,
                    'wear' => $sticker['wear'] ?? null,
                    'image_url' => $sticker['image_url'] ?? null,
                    'hash_name' => $marketHashName,
                    'price' => $latestPrice?->getMedianPrice() ?? 0,
                ];
            } else {
                // If not found, still include but with no price
                $enrichedStickers[] = [
                    'name' => $stickerName,
                    'type' => $stickerType,
                    'slot' => $sticker['slot'] ?? null,
                    'wear' => $sticker['wear'] ?? null,
                    'image_url' => $sticker['image_url'] ?? null,
                    'hash_name' => $marketHashName,
                    'price' => 0,
                ];
            }
        }

        return $enrichedStickers;
    }

    /**
     * Enrich keychain data with price information
     */
    private function enrichKeychainWithPrice(?array $keychain): ?array
    {
        if ($keychain === null || empty($keychain)) {
            return null;
        }

        $keychainName = $keychain['name'] ?? null;

        if ($keychainName === null) {
            return null;
        }

        // Construct market hash name (e.g., "Charm | Name")
        $marketHashName = 'Charm | ' . $keychainName;

        // Look up item in database
        $keychainItem = $this->itemRepository->findByHashName($marketHashName);

        if ($keychainItem !== null) {
            $latestPrice = $this->getLatestPriceForItem($keychainItem);

            return [
                'name' => $keychainName,
                'image_url' => $keychain['image_url'] ?? null,
                'hash_name' => $marketHashName,
                'price' => $latestPrice?->getMedianPrice() ?? 0,
            ];
        } else {
            // If not found, still include but with no price
            return [
                'name' => $keychainName,
                'image_url' => $keychain['image_url'] ?? null,
                'hash_name' => $marketHashName,
                'price' => 0,
            ];
        }
    }
}