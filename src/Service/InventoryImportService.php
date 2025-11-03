<?php

namespace App\Service;

use App\DTO\ImportPreview;
use App\DTO\ImportResult;
use App\Entity\Item;
use App\Entity\ItemUser;
use App\Entity\User;
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

        // Generate preview statistics
        $stats = $this->generatePreviewStats($mappedItems, $currentInventory);

        // Store parsed data in session (including storage boxes)
        $sessionKey = $this->storeInSession($mappedItems, $storageBoxesData);

        return new ImportPreview(
            totalItems: count($mappedItems),
            itemsToAdd: $stats['items_to_add'],
            itemsToRemove: $stats['items_to_remove'],
            statsByRarity: $stats['by_rarity'],
            statsByType: $stats['by_type'],
            notableItems: $stats['notable_items'],
            unmatchedItems: $unmatchedItems,
            errors: $errors,
            sessionKey: $sessionKey,
            storageBoxCount: count($storageBoxesData),
        );
    }

    /**
     * Execute the actual import from session data
     */
    public function executeImport(User $user, string $sessionKey): ImportResult
    {
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

        $totalProcessed = 0;
        $successCount = 0;
        $errorCount = 0;
        $errors = [];
        $skippedItems = [];

        $this->entityManager->beginTransaction();

        try {
            // Sync storage boxes FIRST
            if (!empty($storageBoxesData)) {
                $this->storageBoxService->syncStorageBoxes($user, $storageBoxesData);
            }

            // IMPORTANT: Delete ONLY items in main inventory (storageBox IS NULL)
            // Items in storage containers (both Steam and manual) are preserved
            $qb = $this->entityManager->createQueryBuilder();
            $qb->delete(ItemUser::class, 'iu')
                ->where('iu.user = :user')
                ->andWhere('iu.storageBox IS NULL')  // Only delete items NOT in storage
                ->setParameter('user', $user);

            $deletedCount = $qb->getQuery()->execute();

            $this->logger->info('Deleted main inventory items during import', [
                'user_id' => $user->getId(),
                'deleted_count' => $deletedCount,
                'preserved_in_storage' => true
            ]);

            // Create new ItemUser entities from parsed data
            foreach ($mappedItems as $mappedItem) {
                $totalProcessed++;

                try {
                    $itemUser = new ItemUser();
                    $itemUser->setUser($user);
                    $itemUser->setItem($mappedItem['item']);

                    $data = $mappedItem['data'];

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
            );
        }

        return new ImportResult(
            totalProcessed: $totalProcessed,
            successCount: $successCount,
            errorCount: $errorCount,
            errors: $errors,
            skippedItems: $skippedItems,
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
     * Generate statistics for preview
     */
    private function generatePreviewStats(array $mappedItems, array $currentInventory): array
    {
        $byRarity = [];
        $byType = [];
        $notableItems = [];

        foreach ($mappedItems as $mappedItem) {
            $item = $mappedItem['item'];

            // Count by rarity
            $rarity = $item->getRarity();
            $byRarity[$rarity] = ($byRarity[$rarity] ?? 0) + 1;

            // Count by type
            $type = $item->getType();
            $byType[$type] = ($byType[$type] ?? 0) + 1;

            // Identify notable items (knives, gloves, high rarity)
            if ($type === 'Knife' || $type === 'Gloves' || in_array($rarity, ['Covert', 'Extraordinary', 'Master'])) {
                $notableItems[] = [
                    'name' => $item->getName(),
                    'type' => $type,
                    'rarity' => $rarity,
                    'image_url' => $item->getImageUrl(),
                ];
            }
        }

        // Calculate items to add/remove
        $newAssetIds = array_map(fn($m) => $m['data']['asset_id'], $mappedItems);
        $currentAssetIds = array_map(fn($item) => $item->getAssetId(), $currentInventory);

        $itemsToAdd = count(array_diff($newAssetIds, $currentAssetIds));
        $itemsToRemove = count($currentInventory); // We're replacing all

        return [
            'by_rarity' => $byRarity,
            'by_type' => $byType,
            'notable_items' => $notableItems,
            'items_to_add' => $itemsToAdd,
            'items_to_remove' => $itemsToRemove,
        ];
    }

    /**
     * Store mapped items in session
     */
    private function storeInSession(array $mappedItems, array $storageBoxesData): string
    {
        $session = $this->requestStack->getSession();
        $sessionKey = self::SESSION_PREFIX . bin2hex(random_bytes(16));

        $serializableData = [
            'items' => [],
            'storage_boxes' => $storageBoxesData
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
            'storage_boxes' => $serializableData['storage_boxes'] ?? []
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

        // Skip non-tradeable item types (including storage boxes - handled separately)
        $skipTypes = [
            'CSGO_Type_Tool',              // Storage Units and other tools
            'CSGO_Type_Collectible',       // Medals, Coins, Badges
            'CSGO_Type_MusicKit',          // Music Kits
            'CSGO_Type_Spray',             // Graffiti
            'Type_Collectible',            // Alternative collectible type
            'Type_Spray',                  // Alternative spray type
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
}