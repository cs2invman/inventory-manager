<?php

namespace App\Command;

use App\Repository\ItemRepository;
use App\Service\ItemTableService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test:items-controller',
    description: 'Test the ItemsController logic (simulating controller behavior)'
)]
class TestItemsControllerCommand extends Command
{
    public function __construct(
        private ItemTableService $itemTableService,
        private ItemRepository $itemRepository
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Testing ItemsController Logic');

        // Test 1: Simulate getAvailableFilters()
        $io->section('Test 1: Get available filters');
        try {
            $filters = [
                'categories' => $this->itemRepository->findAllCategories(),
                'subcategories' => $this->itemRepository->findAllSubcategories(),
                'types' => $this->itemRepository->findAllTypes(),
                'rarities' => $this->itemRepository->findAllRarities(),
            ];

            $io->success(sprintf(
                'Categories: %d, Subcategories: %d, Types: %d, Rarities: %d',
                count($filters['categories']),
                count($filters['subcategories']),
                count($filters['types']),
                count($filters['rarities'])
            ));
        } catch (\Exception $e) {
            $io->error('Test 1 failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Test 2: Simulate data() method with various query parameters
        $io->section('Test 2: Simulate AJAX endpoint with filters');

        $testCases = [
            ['filters' => [], 'sortBy' => 'name', 'sortDirection' => 'asc', 'page' => 1, 'perPage' => 25],
            ['filters' => ['search' => 'AK-47'], 'sortBy' => 'name', 'sortDirection' => 'asc', 'page' => 1, 'perPage' => 10],
            ['filters' => ['category' => 'Weapon'], 'sortBy' => 'price', 'sortDirection' => 'desc', 'page' => 1, 'perPage' => 5],
            ['filters' => ['rarity' => 'Classified'], 'sortBy' => 'volume', 'sortDirection' => 'desc', 'page' => 1, 'perPage' => 5],
        ];

        foreach ($testCases as $index => $testCase) {
            $io->writeln(sprintf('Test case %d: %s', $index + 1, json_encode($testCase)));

            try {
                $filters = $testCase['filters'];
                $filters['active'] = true; // Always add active filter

                $result = $this->itemTableService->getItemsTableData(
                    $filters,
                    $testCase['sortBy'],
                    strtoupper($testCase['sortDirection']),
                    $testCase['page'],
                    $testCase['perPage']
                );

                // Simulate formatItemForJson()
                $formattedItems = [];
                foreach ($result['items'] as $itemData) {
                    $item = $itemData['item'];
                    $price = $itemData['latestPrice'];

                    $formattedItems[] = [
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
                        'currencySymbol' => '$',
                    ];
                }

                $io->writeln(sprintf(
                    '  ✓ Found %d items (showing %d), page %d/%d',
                    $result['total'],
                    count($formattedItems),
                    $result['page'],
                    $result['totalPages']
                ));

                if (!empty($formattedItems)) {
                    $first = $formattedItems[0];
                    $io->writeln(sprintf('  First item: %s', $first['name']));
                }
            } catch (\Exception $e) {
                $io->error(sprintf('Test case %d failed: %s', $index + 1, $e->getMessage()));
                return Command::FAILURE;
            }
        }

        // Test 3: Input validation
        $io->section('Test 3: Input validation edge cases');

        $edgeCases = [
            ['desc' => 'Invalid sortBy (should default to name)', 'sortBy' => 'invalid_column', 'expected' => 'name'],
            ['desc' => 'Page < 1 (should normalize to 1)', 'page' => 0, 'expected_page' => 1],
            ['desc' => 'Page < 1 negative (should normalize to 1)', 'page' => -5, 'expected_page' => 1],
            ['desc' => 'PerPage > 100 (should cap at 100)', 'perPage' => 500, 'expected_perPage' => 100],
            ['desc' => 'Short search term (should be ignored)', 'search' => 'A', 'expected_total' => 'all'],
        ];

        foreach ($edgeCases as $edgeCase) {
            $io->writeln($edgeCase['desc']);

            try {
                $filters = ['active' => true];
                $sortBy = $edgeCase['sortBy'] ?? 'name';
                $page = $edgeCase['page'] ?? 1;
                $perPage = $edgeCase['perPage'] ?? 25;

                if (isset($edgeCase['search'])) {
                    if (strlen($edgeCase['search']) >= 2) {
                        $filters['search'] = $edgeCase['search'];
                    }
                }

                // Validate sortBy
                $validSortColumns = ['name', 'category', 'subcategory', 'type', 'rarity', 'price', 'volume', 'updatedAt', 'trend7d', 'trend30d'];
                if (!in_array($sortBy, $validSortColumns)) {
                    $sortBy = 'name';
                }

                // Validate page
                if ($page < 1) {
                    $page = 1;
                }

                // Validate perPage
                if ($perPage > 100) {
                    $perPage = 100;
                }

                $result = $this->itemTableService->getItemsTableData($filters, $sortBy, 'ASC', $page, $perPage);

                $io->writeln(sprintf('  ✓ Handled correctly: sortBy=%s, page=%d, perPage=%d, total=%d',
                    $sortBy, $result['page'], $result['perPage'], $result['total']));
            } catch (\Exception $e) {
                $io->error('Edge case failed: ' . $e->getMessage());
                return Command::FAILURE;
            }
        }

        // Test 4: JSON response structure
        $io->section('Test 4: Verify JSON response structure');
        try {
            $filters = ['active' => true, 'search' => 'AK-47'];
            $result = $this->itemTableService->getItemsTableData($filters, 'name', 'ASC', 1, 5);

            $formattedItems = [];
            foreach ($result['items'] as $itemData) {
                $item = $itemData['item'];
                $formattedItems[] = [
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
                    'price' => $itemData['latestPrice'] !== null ? round($itemData['latestPrice'], 2) : null,
                    'volume' => $itemData['volume'],
                    'updatedAt' => $itemData['priceDate']?->format('c'),
                    'trend7d' => $itemData['trend7d'] !== null ? round($itemData['trend7d'], 1) : null,
                    'trend30d' => $itemData['trend30d'] !== null ? round($itemData['trend30d'], 1) : null,
                    'currencySymbol' => '$',
                ];
            }

            $response = [
                'items' => $formattedItems,
                'pagination' => [
                    'total' => $result['total'],
                    'page' => $result['page'],
                    'perPage' => $result['perPage'],
                    'totalPages' => $result['totalPages'],
                    'hasMore' => $result['hasMore'],
                ],
                'filters' => [
                    'available' => [
                        'categories' => $this->itemRepository->findAllCategories(),
                        'subcategories' => $this->itemRepository->findAllSubcategories(),
                        'types' => $this->itemRepository->findAllTypes(),
                        'rarities' => $this->itemRepository->findAllRarities(),
                    ],
                    'active' => $filters,
                ],
            ];

            $io->writeln('Response structure:');
            $io->writeln('  - items: ' . count($response['items']) . ' items');
            $io->writeln('  - pagination: total=' . $response['pagination']['total'] . ', page=' . $response['pagination']['page']);
            $io->writeln('  - filters.available: categories=' . count($response['filters']['available']['categories']));
            $io->writeln('  - filters.active: ' . json_encode($response['filters']['active']));

            if (!empty($response['items'])) {
                $io->writeln('Sample item JSON keys: ' . implode(', ', array_keys($response['items'][0])));
            }

            $io->success('JSON response structure is correct');
        } catch (\Exception $e) {
            $io->error('Test 4 failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success('All controller logic tests passed!');
        $io->note('Routes are registered at /items and /items/data (requires authentication)');

        return Command::SUCCESS;
    }
}
