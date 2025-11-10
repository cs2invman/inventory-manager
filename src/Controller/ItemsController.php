<?php

namespace App\Controller;

use App\Repository\ItemRepository;
use App\Service\ItemTableService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class ItemsController extends AbstractController
{
    public function __construct(
        private ItemTableService $itemTableService,
        private ItemRepository $itemRepository,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Main items table page - renders filter UI and table skeleton
     */
    #[Route('/items', name: 'items_index', methods: ['GET'])]
    public function index(): Response
    {
        // Get available filter options
        $availableFilters = $this->getAvailableFilters();

        // Get user's currency preference
        $user = $this->getUser();
        $currency = 'USD';
        $currencySymbol = '$';

        if ($user && $user->getConfig()) {
            $preferredCurrency = $user->getConfig()->getPreferredCurrency();
            if ($preferredCurrency === 'CAD') {
                $currency = 'CAD';
                $currencySymbol = 'C$';
            }
        }

        return $this->render('items/index.html.twig', [
            'filters' => $availableFilters,
            'currency' => $currency,
            'currencySymbol' => $currencySymbol,
        ]);
    }

    /**
     * AJAX endpoint for items table data with filtering, sorting, and pagination
     */
    #[Route('/items/data', name: 'items_data', methods: ['GET'])]
    public function data(Request $request): JsonResponse
    {
        // Extract query parameters
        $search = trim($request->query->get('search', ''));
        $category = $request->query->get('category', '');
        $subcategory = $request->query->get('subcategory', '');
        $type = $request->query->get('type', '');
        $rarity = $request->query->get('rarity', '');
        $stattrak = $request->query->get('stattrak', '');
        $souvenir = $request->query->get('souvenir', '');
        $minPrice = $request->query->get('minPrice', '');
        $maxPrice = $request->query->get('maxPrice', '');
        $sortBy = $request->query->get('sortBy', 'name');
        $sortDirection = $request->query->get('sortDirection', 'asc');
        $page = $request->query->getInt('page', 1);
        $perPage = $request->query->getInt('perPage', 25);

        // Validate inputs
        $validSortColumns = ['name', 'category', 'subcategory', 'type', 'rarity', 'price', 'volume', 'updatedAt', 'trend7d', 'trend30d'];
        if (!in_array($sortBy, $validSortColumns)) {
            $this->logger->warning('Invalid sortBy column provided', ['sortBy' => $sortBy]);
            $sortBy = 'name';
        }

        $sortDirection = strtolower($sortDirection) === 'desc' ? 'desc' : 'asc';

        // Validate page and perPage
        if ($page < 1) {
            $page = 1;
        }
        if ($perPage < 1) {
            $perPage = 25;
        }
        if ($perPage > 100) {
            $perPage = 100;
        }

        // Build filters array
        $filters = ['active' => true]; // Always filter active items

        if (strlen($search) >= 2) {
            $filters['search'] = $search;
        }
        if ($category) {
            $filters['category'] = $category;
        }
        if ($subcategory) {
            $filters['subcategory'] = $subcategory;
        }
        if ($type) {
            $filters['type'] = $type;
        }
        if ($rarity) {
            $filters['rarity'] = $rarity;
        }
        if ($stattrak === '1') {
            $filters['stattrakAvailable'] = true;
        }
        if ($souvenir === '1') {
            $filters['souvenirAvailable'] = true;
        }
        if ($minPrice !== '' && is_numeric($minPrice) && (float)$minPrice >= 0) {
            $filters['minPrice'] = (float)$minPrice;
        }
        if ($maxPrice !== '' && is_numeric($maxPrice) && (float)$maxPrice >= 0) {
            $filters['maxPrice'] = (float)$maxPrice;
        }

        // Get user's currency settings
        $user = $this->getUser();
        $currencyCode = 'USD';
        $currencySymbol = '$';
        $exchangeRate = null;

        if ($user && $user->getConfig()) {
            $preferredCurrency = $user->getConfig()->getPreferredCurrency();
            if ($preferredCurrency === 'CAD') {
                $currencyCode = 'CAD';
                $currencySymbol = 'C$';
                $exchangeRate = (float) $user->getConfig()->getCadExchangeRate();
            }
        }

        // Call service layer
        try {
            $result = $this->itemTableService->getItemsTableData(
                $filters,
                $sortBy,
                strtoupper($sortDirection),
                $page,
                $perPage
            );
        } catch (\Exception $e) {
            $this->logger->error('Items table data fetch failed', [
                'error' => $e->getMessage(),
                'filters' => $filters,
                'sortBy' => $sortBy,
                'page' => $page,
            ]);
            return $this->json(['error' => 'Failed to fetch items data'], 500);
        }

        // Format items for JSON response
        $formattedItems = [];
        foreach ($result['items'] as $itemData) {
            $formattedItems[] = $this->formatItemForJson($itemData, $currencySymbol, $exchangeRate);
        }

        // Return JSON response
        return $this->json([
            'items' => $formattedItems,
            'pagination' => [
                'total' => $result['total'],
                'page' => $result['page'],
                'perPage' => $result['perPage'],
                'totalPages' => $result['totalPages'],
                'hasMore' => $result['hasMore'],
            ],
            'filters' => [
                'available' => $this->getAvailableFilters(),
                'active' => $filters,
            ],
        ]);
    }

    /**
     * Get all available filter options for dropdowns
     */
    private function getAvailableFilters(): array
    {
        return [
            'categories' => $this->itemRepository->findAllCategories(),
            'subcategories' => $this->itemRepository->findAllSubcategories(),
            'types' => $this->itemRepository->findAllTypes(),
            'rarities' => $this->itemRepository->findAllRarities(),
        ];
    }

    /**
     * Format item entity data to JSON-friendly array
     */
    private function formatItemForJson(
        array $itemData,
        string $currencySymbol,
        ?float $exchangeRate = null
    ): array {
        $item = $itemData['item']; // Item entity

        $price = $itemData['latestPrice'];
        if ($price !== null && $exchangeRate !== null) {
            $price = $price * $exchangeRate;
        }

        return [
            'id' => $item->getId(),
            'name' => $item->getName(),
            'marketName' => $item->getMarketName(),
            'imageUrl' => $item->getImageUrl(),
            'type' => $item->getType(),
            'category' => $item->getCategory(),
            'subcategory' => $item->getSubcategory(),
            'rarity' => $item->getRarity(),
            'rarityColor' => $item->getRarityColor(),
            'stattrakAvailable' => $item->isStattrakAvailable(),
            'souvenirAvailable' => $item->isSouvenirAvailable(),
            'price' => $price !== null ? round($price, 2) : null,
            'volume' => $itemData['volume'],
            'updatedAt' => $itemData['priceDate']?->format('c'),
            'trend7d' => $itemData['trend7d'] !== null ? round($itemData['trend7d'], 1) : null,
            'trend30d' => $itemData['trend30d'] !== null ? round($itemData['trend30d'], 1) : null,
            'currencySymbol' => $currencySymbol,
        ];
    }
}
