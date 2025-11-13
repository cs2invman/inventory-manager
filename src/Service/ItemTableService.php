<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\ItemRepository;
use Doctrine\ORM\EntityManagerInterface;

class ItemTableService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ItemRepository $itemRepository
    ) {
    }

    /**
     * Get items table data with filters, sorting, and pagination
     *
     * @param array $filters Filter criteria (search, category, subcategory, type, rarity, stattrakAvailable, souvenirAvailable, ownedOnly)
     * @param string $sortBy Column to sort by (or 'price', 'volume', 'sold7d', 'sold30d', 'volumeBuyOrders', 'volumeSellOrders', 'trend7d', 'trend30d' for price-based sorting)
     * @param string $sortDirection Sort direction (ASC or DESC)
     * @param int $page Current page number (1-indexed)
     * @param int $perPage Items per page
     * @param User|null $currentUser Current user for owned inventory filter
     * @return array Structured array with items and pagination metadata
     */
    public function getItemsTableData(
        array $filters = [],
        string $sortBy = 'name',
        string $sortDirection = 'ASC',
        int $page = 1,
        int $perPage = 25,
        ?User $currentUser = null
    ): array {
        // Pass user to filters array for owned inventory filter
        if ($currentUser !== null && isset($filters['ownedOnly']) && $filters['ownedOnly']) {
            $filters['currentUser'] = $currentUser;
        }

        // Ensure page is at least 1
        if ($page < 1) {
            $page = 1;
        }

        // Validate sort direction
        $sortDirection = strtoupper($sortDirection) === 'DESC' ? 'DESC' : 'ASC';

        // Calculate offset
        $offset = ($page - 1) * $perPage;

        // Get total count for pagination
        // Use price-aware count if price filters or owned filter are present
        $hasPriceFilter = isset($filters['minPrice']) || isset($filters['maxPrice']);
        $hasOwnedFilter = isset($filters['ownedOnly']) && $filters['ownedOnly'] === true;
        $total = ($hasPriceFilter || $hasOwnedFilter)
            ? $this->itemRepository->countWithPriceFilters($filters)
            : $this->itemRepository->countWithFilters($filters);

        // Handle price/volume/trend sorting using SQL
        // Also use price-based query when price filters or owned filter are present
        $priceSortFields = ['price', 'volume', 'sold7d', 'sold30d', 'volumeBuyOrders', 'volumeSellOrders'];

        if (in_array($sortBy, $priceSortFields) || $hasPriceFilter || $hasOwnedFilter) {
            // Use efficient SQL-based sorting for price-related fields
            // Also required when filtering by price range
            $itemIds = $this->itemRepository->findItemIdsSortedByPrice(
                $filters,
                in_array($sortBy, $priceSortFields) ? $sortBy : 'price',
                $sortDirection,
                $perPage,
                $offset
            );
            $items = $this->itemRepository->findWithLatestPriceAndTrend($itemIds);
        } elseif (in_array($sortBy, ['trend7d', 'trend30d'])) {
            // Use SQL-based trend sorting for efficiency across all items
            $days = $sortBy === 'trend7d' ? 7 : 30;
            $itemIds = $this->itemRepository->findItemIdsSortedByTrend(
                $filters,
                $days,
                $sortDirection,
                $perPage,
                $offset
            );
            $items = $this->itemRepository->findWithLatestPriceAndTrend($itemIds);
        } else {
            // For item fields, use repository's native sorting
            $items = $this->itemRepository->findAllWithFiltersAndPagination(
                $filters,
                $sortBy,
                $sortDirection,
                $perPage,
                $offset
            );

            // Enrich with price and trend data
            $itemIds = array_map(fn($item) => $item->getId(), $items);
            $itemsWithData = $this->itemRepository->findWithLatestPriceAndTrend($itemIds);
            $items = $itemsWithData;
        }

        // Calculate pagination metadata
        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 0;
        $hasMore = $page < $totalPages;

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
            'hasMore' => $hasMore,
        ];
    }

    /**
     * Get items sorted by trend (7d or 30d)
     * Since trends are calculated, not stored, we need to limit batch size
     *
     * @param array $filters
     * @param string $sortBy
     * @param string $sortDirection
     * @param int $perPage
     * @param int $offset
     * @return array
     */
    private function getItemsWithTrendSorting(
        array $filters,
        string $sortBy,
        string $sortDirection,
        int $perPage,
        int $offset
    ): array {
        // For trend sorting, limit to a reasonable batch size to avoid memory issues
        // We fetch a larger batch than requested to ensure proper sorting
        $batchSize = min(500, $offset + ($perPage * 3));

        // Fetch items matching filters (limited batch)
        $items = $this->itemRepository->findAllWithFiltersAndPagination(
            $filters,
            'name',  // Default sort for initial fetch
            'ASC',
            $batchSize,
            0
        );

        // Get item IDs
        $itemIds = array_map(fn($item) => $item->getId(), $items);

        // Enrich with price and trend data
        $itemsWithData = $this->itemRepository->findWithLatestPriceAndTrend($itemIds);

        // Sort by the requested trend field
        usort($itemsWithData, function ($a, $b) use ($sortBy, $sortDirection) {
            $aValue = $this->getSortValue($a, $sortBy);
            $bValue = $this->getSortValue($b, $sortBy);

            // Handle null values (items without trend data should go to the end)
            if ($aValue === null && $bValue === null) {
                return 0;
            }
            if ($aValue === null) {
                return 1;  // Always put null at the end
            }
            if ($bValue === null) {
                return -1;  // Always put null at the end
            }

            // Compare values
            $comparison = $aValue <=> $bValue;

            // Apply sort direction
            return $sortDirection === 'DESC' ? -$comparison : $comparison;
        });

        // Apply pagination (slice the sorted array)
        return array_slice($itemsWithData, $offset, $perPage);
    }

    /**
     * Get the value to sort by from an item data array
     *
     * @param array $itemData
     * @param string $sortBy
     * @return float|int|null
     */
    private function getSortValue(array $itemData, string $sortBy): float|int|null
    {
        return match ($sortBy) {
            'price' => $itemData['latestPrice'],
            'volume', 'sold30d' => $itemData['sold30d'],  // Map both to sold30d
            'sold7d' => $itemData['sold7d'],
            'volumeBuyOrders' => $itemData['volumeBuyOrders'],
            'volumeSellOrders' => $itemData['volumeSellOrders'],
            'trend7d' => $itemData['trend7d'],
            'trend30d' => $itemData['trend30d'],
            default => null,
        };
    }

    /**
     * Get unique categories for filter dropdowns
     *
     * @return string[]
     */
    public function getCategories(): array
    {
        return $this->itemRepository->findAllCategories();
    }

    /**
     * Get unique subcategories for filter dropdowns
     *
     * @return string[]
     */
    public function getSubcategories(): array
    {
        return $this->itemRepository->findAllSubcategories();
    }

    /**
     * Get unique types for filter dropdowns
     *
     * @return string[]
     */
    public function getTypes(): array
    {
        return $this->itemRepository->findAllTypes();
    }

    /**
     * Get unique rarities for filter dropdowns
     *
     * @return string[]
     */
    public function getRarities(): array
    {
        return $this->itemRepository->findAllRarities();
    }
}
