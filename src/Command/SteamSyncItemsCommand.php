<?php

namespace App\Command;

use App\Service\ItemSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:steam:sync-items',
    description: 'Sync CS2 item data from JSON file to database'
)]
class SteamSyncItemsCommand extends Command
{
    public function __construct(
        private readonly ItemSyncService $syncService,
        private readonly LoggerInterface $logger,
        private readonly string $storageBasePath
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'file',
                InputArgument::OPTIONAL,
                'Path to JSON file (defaults to items-latest.json symlink)'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be changed without committing to database'
            )
            ->addOption(
                'skip-prices',
                null,
                InputOption::VALUE_NONE,
                'Only sync item metadata, skip price history'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Increase memory limit for processing large datasets (26,000+ items with 80MB+ JSON files)
        ini_set('memory_limit', '2048M');

        try {
            // Determine input file
            $filepath = $input->getArgument('file');
            if (!$filepath) {
                $filepath = $this->storageBasePath . '/items-latest.json';
            }

            // Validate file exists
            if (!file_exists($filepath)) {
                $io->error("File not found: {$filepath}");
                return Command::FAILURE;
            }

            if (!is_readable($filepath)) {
                $io->error("File is not readable: {$filepath}");
                return Command::FAILURE;
            }

            // Get file info
            $filesize = filesize($filepath);
            $fileAge = time() - filemtime($filepath);
            $ageMinutes = floor($fileAge / 60);

            $io->section('File Information');
            $io->definitionList(
                ['Path' => $filepath],
                ['Size' => $this->formatBytes($filesize)],
                ['Age' => $this->formatAge($ageMinutes)]
            );

            // Parse JSON to get item count
            $jsonContent = file_get_contents($filepath);
            $items = json_decode($jsonContent, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $io->error('Invalid JSON file: ' . json_last_error_msg());
                return Command::FAILURE;
            }

            if (!is_array($items)) {
                $io->error('Expected JSON array of items');
                return Command::FAILURE;
            }

            $itemCount = count($items);
            $io->text("Found {$itemCount} items in file");

            // Handle dry-run
            if ($input->getOption('dry-run')) {
                $io->warning('DRY RUN MODE - No changes will be made to the database');
                $io->text('JSON file is valid and contains ' . number_format($itemCount) . ' items');
                return Command::SUCCESS;
            }

            // Confirm before proceeding
            $io->newLine();
            if (!$io->confirm('Proceed with syncing items to database?', true)) {
                $io->text('Sync cancelled');
                return Command::SUCCESS;
            }

            // Start sync
            $io->section('Syncing Items');

            $skipPrices = $input->getOption('skip-prices');
            if ($skipPrices) {
                $io->text('Price history sync is disabled (--skip-prices)');
            }

            // Create progress bar
            $progressBar = new ProgressBar($output, $itemCount);
            $progressBar->setFormat('very_verbose');
            $progressBar->start();

            // Track progress
            $startTime = microtime(true);

            // Sync items with progress callback
            $stats = $this->syncService->syncFromJsonFile($filepath, $skipPrices, function (int $currentIndex) use ($progressBar) {
                $progressBar->setProgress($currentIndex);
            });

            $progressBar->finish();
            $io->newLine(2);

            $duration = round(microtime(true) - $startTime, 2);

            // Display statistics
            $io->section('Sync Complete');

            $io->definitionList(
                ['Added' => number_format($stats['added'])],
                ['Updated' => number_format($stats['updated'])],
                ['Deactivated' => number_format($stats['deactivated'])],
                ['Price records created' => number_format($stats['price_records_created'])],
                ['Skipped' => number_format($stats['skipped'])],
                ['Total processed' => number_format($stats['total'])],
                ['Duration' => $this->formatDuration($duration)]
            );

            $io->success('Item sync completed successfully');

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $this->logger->error('Failed to sync items from JSON file', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $io->error('Failed to sync items: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Format bytes to human-readable string
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Format age in minutes to human-readable string
     */
    private function formatAge(int $minutes): string
    {
        if ($minutes < 1) {
            return 'less than a minute ago';
        }

        if ($minutes < 60) {
            return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
        }

        $hours = floor($minutes / 60);
        if ($hours < 24) {
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        }

        $days = floor($hours / 24);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    }

    /**
     * Format duration in seconds to human-readable string
     */
    private function formatDuration(float $seconds): string
    {
        if ($seconds < 60) {
            return round($seconds, 2) . 's';
        }

        $minutes = floor($seconds / 60);
        $remainingSeconds = round($seconds % 60);

        return "{$minutes}m {$remainingSeconds}s";
    }
}