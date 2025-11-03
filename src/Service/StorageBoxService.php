<?php

namespace App\Service;

use App\Entity\StorageBox;
use App\Entity\User;
use App\Repository\StorageBoxRepository;
use App\Repository\ItemUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class StorageBoxService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private StorageBoxRepository $storageBoxRepository,
        private ItemUserRepository $itemUserRepository,
        private LoggerInterface $logger
    ) {}

    /**
     * Extract storage box data from Steam inventory JSON response
     */
    public function extractStorageBoxesFromJson(array $jsonData): array
    {
        $assets = $jsonData['assets'] ?? [];
        $descriptions = $jsonData['descriptions'] ?? [];

        $storageBoxes = [];

        // Find storage box descriptions
        foreach ($descriptions as $description) {
            if ($this->isStorageBox($description)) {
                $storageBoxes[] = $this->parseStorageBoxData($description, $assets);
            }
        }

        return $storageBoxes;
    }

    /**
     * Check if a description represents a storage box
     */
    private function isStorageBox(array $description): bool
    {
        // Check for Tool type
        $tags = $description['tags'] ?? [];
        foreach ($tags as $tag) {
            if (($tag['category'] ?? '') === 'Type'
                && ($tag['internal_name'] ?? '') === 'CSGO_Type_Tool'
                && ($description['market_hash_name'] ?? '') === 'Storage Unit') {
                return true;
            }
        }
        return false;
    }

    /**
     * Parse storage box metadata from description
     */
    private function parseStorageBoxData(array $description, array $assets): array
    {
        $classId = $description['classid'];
        $instanceId = $description['instanceid'];

        // Find matching asset
        $assetId = null;
        foreach ($assets as $asset) {
            if ($asset['classid'] === $classId && $asset['instanceid'] === $instanceId) {
                $assetId = $asset['assetid'];
                break;
            }
        }

        // Extract name from nametag
        $name = 'Storage Unit';
        $itemCount = 0;
        $modificationDate = null;

        $descriptions = $description['descriptions'] ?? [];
        foreach ($descriptions as $desc) {
            $descName = $desc['name'] ?? '';
            $value = $desc['value'] ?? '';

            if ($descName === 'nametag') {
                // Parse storage box name from Steam's format
                // Format from Steam JSON: "Name Tag: ''BOX_NAME''"
                // The name is wrapped in double single quotes: ''NAME''

                // First try to match double single quotes (Steam's format)
                if (preg_match("/Name Tag:\s*''([^']+)''/", $value, $matches)) {
                    $name = $matches[1];
                }
                // Try single quotes
                elseif (preg_match("/Name Tag:\s*'([^']+)'/", $value, $matches)) {
                    $name = $matches[1];
                }
                // Try double quotes
                elseif (preg_match('/Name Tag:\s*"([^"]+)"/', $value, $matches)) {
                    $name = $matches[1];
                }
                // Fallback: extract everything after "Name Tag: " and clean it
                elseif (preg_match("/Name Tag:\s*(.+?)(?:<\/|$)/", $value, $matches)) {
                    $cleanName = trim(strip_tags($matches[1]));
                    // Remove any remaining quotes
                    $cleanName = trim($cleanName, "'\" ");
                    if (!empty($cleanName)) {
                        $name = $cleanName;
                    }
                }
            } elseif ($descName === 'attr: items count') {
                // Parse: "Number of Items: 73"
                if (preg_match('/(\d+)/', $value, $matches)) {
                    $itemCount = (int) $matches[1];
                }
            } elseif ($descName === 'attr: modification date') {
                // Parse: "Modification Date: Sep 11, 2025 (22:25:42) GMT"
                if (preg_match('/Modification Date:\s*(.+)\s+GMT/', $value, $matches)) {
                    try {
                        $modificationDate = new \DateTimeImmutable($matches[1] . ' GMT');
                    } catch (\Exception $e) {
                        $this->logger->warning('Failed to parse storage box modification date', [
                            'value' => $matches[1],
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }
        }

        return [
            'asset_id' => $assetId,
            'name' => $name,
            'item_count' => $itemCount,
            'modification_date' => $modificationDate,
        ];
    }

    /**
     * Sync storage boxes for a user (create new, update existing)
     *
     * IMPORTANT BEHAVIORS:
     * - Only syncs Steam-imported boxes (with assetId). Manual boxes are never touched.
     * - Items in storage boxes are NEVER deleted by the import process.
     * - Import only replaces items in main inventory (storageBox = null).
     */
    public function syncStorageBoxes(User $user, array $storageBoxesData): void
    {
        foreach ($storageBoxesData as $data) {
            if (empty($data['asset_id'])) {
                $this->logger->warning('Storage box missing assetId, skipping', ['data' => $data]);
                continue;
            }

            // Find existing Steam box or create new
            $storageBox = $this->storageBoxRepository->findByAssetId($user, $data['asset_id']);

            if ($storageBox === null) {
                $storageBox = new StorageBox();
                $storageBox->setUser($user);
                $storageBox->setAssetId($data['asset_id']);
                $this->logger->info('Creating new Steam storage box', [
                    'user_id' => $user->getId(),
                    'asset_id' => $data['asset_id'],
                    'name' => $data['name']
                ]);
            }

            // Update fields (only for Steam boxes - manual boxes are never touched)
            $storageBox->setName($data['name']);
            $storageBox->setItemCount($data['item_count']);
            if ($data['modification_date'] !== null) {
                $storageBox->setModificationDate($data['modification_date']);
            }

            $this->entityManager->persist($storageBox);
        }

        $this->entityManager->flush();
    }

    /**
     * Create a manual storage box (for tracking items lent to friends)
     * Manual boxes have no assetId and are never affected by imports
     */
    public function createManualBox(User $user, string $name): StorageBox
    {
        $storageBox = new StorageBox();
        $storageBox->setUser($user);
        $storageBox->setAssetId(null);  // No assetId = manual box
        $storageBox->setName($name);
        $storageBox->setItemCount(0);

        $this->entityManager->persist($storageBox);
        $this->entityManager->flush();

        $this->logger->info('Created manual storage box', [
            'user_id' => $user->getId(),
            'name' => $name
        ]);

        return $storageBox;
    }

    /**
     * Validate that a storage box's reported item count matches actual items in DB
     */
    public function validateItemCount(StorageBox $box): bool
    {
        $actualCount = $this->itemUserRepository->count(['storageBox' => $box]);
        $reportedCount = $box->getItemCount();

        if ($actualCount !== $reportedCount) {
            $this->logger->warning('Storage box item count mismatch', [
                'storage_box_id' => $box->getId(),
                'storage_box_name' => $box->getName(),
                'reported_count' => $reportedCount,
                'actual_count' => $actualCount
            ]);
            return false;
        }

        return true;
    }

    /**
     * Get all items in a storage box
     */
    public function getItemsInStorageBox(StorageBox $box): array
    {
        return $this->itemUserRepository->findBy(['storageBox' => $box]);
    }

    /**
     * Get all manual storage boxes for a user (for friend lending tracking)
     */
    public function getManualBoxes(User $user): array
    {
        return $this->storageBoxRepository->findManualBoxes($user);
    }

    /**
     * Get all Steam-imported storage boxes for a user
     */
    public function getSteamBoxes(User $user): array
    {
        return $this->storageBoxRepository->findSteamBoxes($user);
    }

    /**
     * Rename a manual storage box
     */
    public function renameManualBox(StorageBox $box, string $newName): void
    {
        if ($box->isSteamBox()) {
            throw new \InvalidArgumentException('Cannot rename Steam-imported storage boxes');
        }

        $box->setName($newName);
        $this->entityManager->flush();

        $this->logger->info('Renamed manual storage box', [
            'box_id' => $box->getId(),
            'old_name' => $box->getName(),
            'new_name' => $newName
        ]);
    }

    /**
     * Delete a manual storage box (moves items back to main inventory)
     */
    public function deleteManualBox(StorageBox $box): void
    {
        if ($box->isSteamBox()) {
            throw new \InvalidArgumentException('Cannot delete Steam-imported storage boxes');
        }

        // Move items back to main inventory (set storageBox to null)
        $items = $this->getItemsInStorageBox($box);
        foreach ($items as $item) {
            $item->setStorageBox(null);
        }

        $this->entityManager->remove($box);
        $this->entityManager->flush();

        $this->logger->info('Deleted manual storage box', [
            'box_id' => $box->getId(),
            'name' => $box->getName(),
            'items_moved' => count($items)
        ]);
    }
}
