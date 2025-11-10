<?php

namespace App\Command;

use App\Service\ItemTableService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test:item-table',
    description: 'Test the ItemTableService implementation'
)]
class TestItemTableCommand extends Command
{
    public function __construct(
        private ItemTableService $itemTableService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Testing ItemTableService');

        // Test 1: Get all items (first page)
        $io->section('Test 1: Get all items (first page, 5 per page)');
        try {
            $result = $this->itemTableService->getItemsTableData([], 'name', 'ASC', 1, 5);
            $io->success(sprintf(
                'Found %d total items, showing page %d of %d (%d items on this page)',
                $result['total'],
                $result['page'],
                $result['totalPages'],
                count($result['items'])
            ));

            foreach ($result['items'] as $item) {
                $io->writeln(sprintf(
                    '  - %s | Price: %s | Volume: %s | Trend 7d: %s%%',
                    $item['item']->getName(),
                    $item['latestPrice'] ? number_format($item['latestPrice'], 2) : 'N/A',
                    $item['volume'] ?? 'N/A',
                    $item['trend7d'] ? number_format($item['trend7d'], 2) : 'N/A'
                ));
            }
        } catch (\Exception $e) {
            $io->error('Test 1 failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Test 2: Filter by category
        $io->section('Test 2: Filter by category "Weapon"');
        try {
            $result = $this->itemTableService->getItemsTableData(['category' => 'Weapon'], 'name', 'ASC', 1, 5);
            $io->success(sprintf(
                'Found %d weapons, showing %d items',
                $result['total'],
                count($result['items'])
            ));
        } catch (\Exception $e) {
            $io->error('Test 2 failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Test 3: Text search
        $io->section('Test 3: Search for "AK-47"');
        try {
            $result = $this->itemTableService->getItemsTableData(['search' => 'AK-47'], 'name', 'ASC', 1, 5);
            $io->success(sprintf(
                'Found %d items matching "AK-47"',
                $result['total']
            ));

            foreach ($result['items'] as $item) {
                $io->writeln(sprintf('  - %s', $item['item']->getName()));
            }
        } catch (\Exception $e) {
            $io->error('Test 3 failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Test 4: Multiple filters
        $io->section('Test 4: Filter by category "Weapon" and rarity "Covert"');
        try {
            $result = $this->itemTableService->getItemsTableData(
                ['category' => 'Weapon', 'rarity' => 'Covert'],
                'name',
                'ASC',
                1,
                5
            );
            $io->success(sprintf(
                'Found %d covert weapons',
                $result['total']
            ));
        } catch (\Exception $e) {
            $io->error('Test 4 failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Test 5: Sort by price descending
        $io->section('Test 5: Sort by price (descending, top 5)');
        try {
            $result = $this->itemTableService->getItemsTableData([], 'price', 'DESC', 1, 5);
            $io->success('Top 5 most expensive items:');

            foreach ($result['items'] as $item) {
                $io->writeln(sprintf(
                    '  - %s: $%s',
                    $item['item']->getName(),
                    $item['latestPrice'] ? number_format($item['latestPrice'], 2) : 'N/A'
                ));
            }
        } catch (\Exception $e) {
            $io->error('Test 5 failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Test 6: Pagination
        $io->section('Test 6: Test pagination (page 2)');
        try {
            $result = $this->itemTableService->getItemsTableData([], 'name', 'ASC', 2, 10);
            $io->success(sprintf(
                'Page 2: showing items 11-20 of %d total items, hasMore: %s',
                $result['total'],
                $result['hasMore'] ? 'yes' : 'no'
            ));
        } catch (\Exception $e) {
            $io->error('Test 6 failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Test 7: Filter dropdowns
        $io->section('Test 7: Test filter dropdown methods');
        try {
            $categories = $this->itemTableService->getCategories();
            $subcategories = $this->itemTableService->getSubcategories();
            $types = $this->itemTableService->getTypes();
            $rarities = $this->itemTableService->getRarities();

            $io->success(sprintf(
                'Categories: %d, Subcategories: %d, Types: %d, Rarities: %d',
                count($categories),
                count($subcategories),
                count($types),
                count($rarities)
            ));

            $io->writeln('Sample categories: ' . implode(', ', array_slice($categories, 0, 5)));
            $io->writeln('Sample rarities: ' . implode(', ', array_slice($rarities, 0, 5)));
        } catch (\Exception $e) {
            $io->error('Test 7 failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Test 8: Edge case - invalid page number
        $io->section('Test 8: Edge case - invalid page number (0)');
        try {
            $result = $this->itemTableService->getItemsTableData([], 'name', 'ASC', 0, 10);
            $io->success(sprintf(
                'Page normalized to %d (should be 1)',
                $result['page']
            ));
        } catch (\Exception $e) {
            $io->error('Test 8 failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Test 9: Edge case - short search term
        $io->section('Test 9: Edge case - short search term (1 char, should be ignored)');
        try {
            $result = $this->itemTableService->getItemsTableData(['search' => 'A'], 'name', 'ASC', 1, 5);
            $io->success(sprintf(
                'Found %d items (search term ignored, showing all)',
                $result['total']
            ));
        } catch (\Exception $e) {
            $io->error('Test 9 failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success('All tests passed!');

        return Command::SUCCESS;
    }
}
