<?php

namespace App\Controller;

use App\Repository\ItemUserRepository;
use App\Repository\StorageBoxRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/inventory')]
#[IsGranted('ROLE_USER')]
class InventoryController extends AbstractController
{
    public function __construct(
        private ItemUserRepository $itemUserRepository,
        private StorageBoxRepository $storageBoxRepository,
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('', name: 'inventory_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getUser();

        // Get filter parameters
        $filter = $request->query->get('filter', 'all');
        $filterBoxId = $request->query->getInt('box_id', 0);

        // Fetch ALL user inventory items (active + in storage)
        $inventoryItems = $this->itemUserRepository->findUserInventory($user->getId());

        // Get all storage boxes with actual item counts from database
        $storageBoxesData = $this->storageBoxRepository->findWithItemCount($user);

        // Transform the data to make it easier to use in template
        $storageBoxes = [];
        foreach ($storageBoxesData as $data) {
            $box = $data[0]; // The StorageBox entity
            $actualItemCount = (int) $data['actualItemCount'];

            $storageBoxes[] = [
                'entity' => $box,
                'id' => $box->getId(),
                'name' => $box->getName(),
                'reportedCount' => $box->getReportedCount() ?? $box->getItemCount(), // Use reportedCount if available, fallback to itemCount
                'actualCount' => $actualItemCount,
                'isSynced' => (($box->getReportedCount() ?? $box->getItemCount()) === $actualItemCount),
                'modificationDate' => $box->getModificationDate(),
            ];
        }

        // Apply filtering
        $filteredItems = $inventoryItems;
        if ($filter === 'active') {
            // Only items NOT in storage
            $filteredItems = array_filter($inventoryItems, fn($item) => $item->getStorageBox() === null);
        } elseif ($filter === 'box' && $filterBoxId > 0) {
            // Only items in specific storage box
            $filteredItems = array_filter($inventoryItems, fn($item) =>
                $item->getStorageBox() && $item->getStorageBox()->getId() === $filterBoxId
            );
        }

        // Calculate stats
        $activeInventoryCount = count(array_filter($inventoryItems, fn($item) => $item->getStorageBox() === null));
        $storedItemsCount = count($inventoryItems) - $activeInventoryCount;

        // Get latest prices for all items and calculate total value
        $totalValue = 0.0;
        $itemsWithPrices = [];

        foreach ($filteredItems as $itemUser) {
            $item = $itemUser->getItem();

            // Get the latest price for this item
            $latestPrice = $this->entityManager->createQuery('
                SELECT ip
                FROM App\Entity\ItemPrice ip
                WHERE ip.item = :item
                ORDER BY ip.priceDate DESC
            ')
            ->setParameter('item', $item)
            ->setMaxResults(1)
            ->getOneOrNullResult();

            $priceValue = $latestPrice ? (float) $latestPrice->getPrice() : 0.0;

            // Get sticker prices if item has stickers
            $stickersWithPrices = [];
            $stickersTotalValue = 0.0;
            $stickers = $itemUser->getStickers();

            if ($stickers !== null && is_array($stickers)) {
                foreach ($stickers as $sticker) {
                    if (!isset($sticker['name'])) {
                        continue;
                    }

                    // Construct market hash name using the correct type (Sticker or Patch)
                    $type = $sticker['type'] ?? 'Sticker'; // Default to Sticker for backward compatibility
                    $stickerHashName = $type . ' | ' . $sticker['name'];

                    // Try to find the sticker item and its price
                    $stickerItem = $this->entityManager->createQuery('
                        SELECT i
                        FROM App\Entity\Item i
                        WHERE i.hashName = :hashName
                    ')
                    ->setParameter('hashName', $stickerHashName)
                    ->setMaxResults(1)
                    ->getOneOrNullResult();

                    $stickerPriceValue = 0.0;
                    if ($stickerItem !== null) {
                        $stickerPrice = $this->entityManager->createQuery('
                            SELECT ip
                            FROM App\Entity\ItemPrice ip
                            WHERE ip.item = :item
                            ORDER BY ip.priceDate DESC
                        ')
                        ->setParameter('item', $stickerItem)
                        ->setMaxResults(1)
                        ->getOneOrNullResult();

                        if ($stickerPrice !== null) {
                            $stickerPriceValue = (float) $stickerPrice->getPrice();
                        }
                    }

                    $stickersTotalValue += $stickerPriceValue;
                    $stickersWithPrices[] = array_merge($sticker, [
                        'price' => $stickerPriceValue,
                        'hash_name' => $stickerHashName,
                    ]);
                }
            }

            // Get keychain price if item has a keychain
            $keychainPrice = null;
            $keychainPriceValue = 0.0;
            $keychainWithPrice = null;
            $keychain = $itemUser->getKeychain();

            if ($keychain !== null && isset($keychain['name'])) {
                // Construct market hash name for keychain: "Charm | {name}"
                $keychainHashName = 'Charm | ' . $keychain['name'];

                // Try to find the keychain item and its price
                $keychainItem = $this->entityManager->createQuery('
                    SELECT i
                    FROM App\Entity\Item i
                    WHERE i.hashName = :hashName
                ')
                ->setParameter('hashName', $keychainHashName)
                ->setMaxResults(1)
                ->getOneOrNullResult();

                if ($keychainItem !== null) {
                    $keychainPrice = $this->entityManager->createQuery('
                        SELECT ip
                        FROM App\Entity\ItemPrice ip
                        WHERE ip.item = :item
                        ORDER BY ip.priceDate DESC
                    ')
                    ->setParameter('item', $keychainItem)
                    ->setMaxResults(1)
                    ->getOneOrNullResult();

                    if ($keychainPrice !== null) {
                        $keychainPriceValue = (float) $keychainPrice->getPrice();
                    }
                }

                $keychainWithPrice = array_merge($keychain, [
                    'price' => $keychainPriceValue,
                    'hash_name' => $keychainHashName,
                ]);
            }

            // Add only keychain price to total item value (stickers cannot be removed, so they don't add value)
            $itemTotalValue = $priceValue + $keychainPriceValue;
            $totalValue += $itemTotalValue;

            $itemsWithPrices[] = [
                'itemUser' => $itemUser,
                'latestPrice' => $latestPrice,
                'priceValue' => $priceValue,
                'stickersWithPrices' => $stickersWithPrices,
                'stickersTotalValue' => $stickersTotalValue,
                'keychainWithPrice' => $keychainWithPrice,
                'keychainPriceValue' => $keychainPriceValue,
                'itemTotalValue' => $itemTotalValue,
            ];
        }

        // Group items by item ID if they are groupable
        $groupedItems = [];
        $ungroupedItems = [];

        foreach ($itemsWithPrices as $itemData) {
            $itemUser = $itemData['itemUser'];
            $item = $itemUser->getItem();

            if ($this->isGroupableItem($item) && !$this->hasCustomizations($itemUser)) {
                // Group by item ID
                $itemId = $item->getId();

                if (!isset($groupedItems[$itemId])) {
                    $groupedItems[$itemId] = [
                        'item' => $item,
                        'latestPrice' => $itemData['latestPrice'],
                        'unitPrice' => $itemData['priceValue'],
                        'items' => [],
                        'quantity' => 0,
                        'aggregatePrice' => 0.0,
                    ];
                }

                $groupedItems[$itemId]['items'][] = $itemUser;
                $groupedItems[$itemId]['quantity']++;
                $groupedItems[$itemId]['aggregatePrice'] += $itemData['itemTotalValue'];
            } else {
                // Keep as individual item
                $itemData['quantity'] = 1;
                $itemData['aggregatePrice'] = $itemData['itemTotalValue'];
                $ungroupedItems[] = $itemData;
            }
        }

        // Convert grouped items to same format as ungrouped
        $finalGroupedItems = [];
        foreach ($groupedItems as $groupData) {
            $finalGroupedItems[] = [
                'item' => $groupData['item'],
                'latestPrice' => $groupData['latestPrice'],
                'priceValue' => $groupData['unitPrice'],
                'quantity' => $groupData['quantity'],
                'aggregatePrice' => $groupData['aggregatePrice'],
                'isGrouped' => true,
                // For grouped items, we'll use the first ItemUser for display purposes
                'itemUser' => $groupData['items'][0],
                'stickersWithPrices' => [], // Grouped items won't have stickers
                'keychainWithPrice' => null,
                'stickersTotalValue' => 0.0,
                'keychainPriceValue' => 0.0,
                'itemTotalValue' => $groupData['aggregatePrice'],
            ];
        }

        // Merge grouped and ungrouped items
        $itemsWithPrices = array_merge($finalGroupedItems, $ungroupedItems);

        // Sort by aggregate price descending
        usort($itemsWithPrices, function ($a, $b) {
            return $b['aggregatePrice'] <=> $a['aggregatePrice'];
        });

        return $this->render('inventory/index.html.twig', [
            'itemsWithPrices' => $itemsWithPrices,
            'storageBoxes' => $storageBoxes,
            'totalValue' => $totalValue,
            'totalItems' => count($inventoryItems),
            'itemCount' => count($filteredItems),
            'activeInventoryCount' => $activeInventoryCount,
            'storedItemsCount' => $storedItemsCount,
            'currentFilter' => $filter,
            'currentBoxId' => $filterBoxId,
            'userConfig' => $user->getConfig(),
        ]);
    }

    /**
     * Check if an item is groupable (cases, capsules, stickers, etc.)
     */
    private function isGroupableItem(\App\Entity\Item $item): bool
    {
        $name = strtolower($item->getName() ?? '');

        // Group if item name contains these keywords AND has no customizations
        $groupableKeywords = [
            'case',
            'capsule',
            'sticker |',         // Stickers
            'patch |',           // Patches
            'graffiti |',
            'music kit |',
            'sealed graffiti |',
            'charm |',           // Charms/Keychains (only those without patterns)
        ];

        foreach ($groupableKeywords as $keyword) {
            if (str_contains($name, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if an item has customizations that prevent grouping
     */
    private function hasCustomizations(\App\Entity\ItemUser $itemUser): bool
    {
        // Don't group if item has any unique properties
        return $itemUser->getFloatValue() !== null
            || $itemUser->getPaintSeed() !== null      // Pattern index
            || $itemUser->getPatternIndex() !== null   // Alternative pattern field
            || $itemUser->getStickers() !== null       // Items with stickers applied
            || $itemUser->getNameTag() !== null        // Custom name tags
            || $itemUser->isStattrak()                 // StatTrak items
            || $itemUser->isSouvenir();                // Souvenir items
        // Note: keychain/charm is NOT checked here - charms can be grouped if they have no other unique properties
    }
}