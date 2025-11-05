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

        // Reduce memory limit with chunked processing (was 2048M, now 768M)
        ini_set('memory_limit', '768M');

        try {
            // Check if file argument was provided
            $filepath = $input->getArgument('file');

            // If no file specified, check for chunks first, then fall back to items-latest.json
            if (!$filepath) {
                $importDir = $this->storageBasePath . '/import';
                $chunkFiles = $this->findChunkFiles($importDir);

                if (!empty($chunkFiles)) {
                    // Process chunks
                    return $this->executeChunkedSync($input, $output, $io, $chunkFiles);
                }

                // Fall back to single file
                $filepath = $this->storageBasePath . '/items-latest.json';
            }

            // Process single file (legacy mode or explicit file argument)
            return $this->executeSingleFileSync($input, $output, $io, $filepath);

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
     * Execute sync from chunked files
     */
    private function executeChunkedSync(InputInterface $input, OutputInterface $output, SymfonyStyle $io, array $chunkFiles): int
    {
        $chunkCount = count($chunkFiles);
        $totalItems = $this->getTotalItemCount($chunkFiles);

        $io->section('Chunk Information');
        $io->definitionList(
            ['Total chunks' => $chunkCount],
            ['Total items' => number_format($totalItems)],
            ['Source' => $this->storageBasePath . '/import']
        );

        // Handle dry-run
        if ($input->getOption('dry-run')) {
            $io->warning('DRY RUN MODE - No changes will be made to the database');
            $io->text('Found ' . $chunkCount . ' chunk files with ' . number_format($totalItems) . ' total items');
            return Command::SUCCESS;
        }

        // Confirm before proceeding
        $io->newLine();
        if (!$io->confirm('Proceed with syncing items to database?', true)) {
            $io->text('Sync cancelled');
            return Command::SUCCESS;
        }

        // Start chunked sync
        $io->section('Syncing Items (Chunked)');

        $skipPrices = $input->getOption('skip-prices');
        if ($skipPrices) {
            $io->text('Price history sync is disabled (--skip-prices)');
        }

        // Create progress bar
        $progressBar = new ProgressBar($output, $totalItems);
        $progressBar->setFormat('very_verbose');
        $progressBar->start();

        // Track progress
        $startTime = microtime(true);
        $processedDir = $this->ensureProcessedDirectory($this->storageBasePath);

        // Accumulate external IDs across all chunks
        $allExternalIds = [];
        $aggregatedStats = [
            'added' => 0,
            'updated' => 0,
            'deactivated' => 0,
            'price_records_created' => 0,
            'skipped' => 0,
            'total' => 0,
        ];

        // Process each chunk
        $itemsProcessedSoFar = 0;
        foreach ($chunkFiles as $index => $chunkFile) {
            $chunkNum = $index + 1;
            $io->text("Processing chunk {$chunkNum} of {$chunkCount}: " . basename($chunkFile));

            // Sync this chunk with deferred deactivation
            $stats = $this->syncService->syncFromJsonFile(
                $chunkFile,
                $skipPrices,
                function (int $currentIndex) use ($progressBar, $itemsProcessedSoFar) {
                    $progressBar->setProgress($itemsProcessedSoFar + $currentIndex);
                },
                $allExternalIds,
                true // Defer deactivation
            );

            // Aggregate statistics
            $aggregatedStats['added'] += $stats['added'];
            $aggregatedStats['updated'] += $stats['updated'];
            $aggregatedStats['price_records_created'] += $stats['price_records_created'];
            $aggregatedStats['skipped'] += $stats['skipped'];
            $aggregatedStats['total'] += $stats['total'];

            // Update offset for next chunk
            $itemsProcessedSoFar += $stats['total'];

            // Move processed chunk to processed directory
            $this->moveToProcessed($chunkFile, $processedDir);

            // Force garbage collection after each chunk
            gc_collect_cycles();
        }

        // Now run deactivation with all accumulated external IDs
        $io->newLine();
        $io->text('Running deactivation check...');

        try {
            $aggregatedStats['deactivated'] = $this->syncService->deactivateMissingItems($allExternalIds);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to deactivate missing items', [
                'error' => $e->getMessage(),
            ]);
            $io->warning('Deactivation step failed: ' . $e->getMessage());
        }

        $progressBar->finish();
        $io->newLine(2);

        $duration = round(microtime(true) - $startTime, 2);

        // Display statistics
        $io->section('Sync Complete');

        $io->definitionList(
            ['Added' => number_format($aggregatedStats['added'])],
            ['Updated' => number_format($aggregatedStats['updated'])],
            ['Deactivated' => number_format($aggregatedStats['deactivated'])],
            ['Price records created' => number_format($aggregatedStats['price_records_created'])],
            ['Skipped' => number_format($aggregatedStats['skipped'])],
            ['Total processed' => number_format($aggregatedStats['total'])],
            ['Chunks processed' => $chunkCount],
            ['Duration' => $this->formatDuration($duration)]
        );

        $io->success('Item sync completed successfully');

        return Command::SUCCESS;
    }

    /**
     * Execute sync from a single file (legacy mode)
     */
    private function executeSingleFileSync(InputInterface $input, OutputInterface $output, SymfonyStyle $io, string $filepath): int
    {
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

    /**
     * Find chunk files in the import directory
     *
     * @return array Array of chunk file paths, sorted by name
     */
    private function findChunkFiles(string $importDir): array
    {
        if (!is_dir($importDir)) {
            return [];
        }

        $pattern = $importDir . '/items-chunk-*.json';
        $files = glob($pattern);

        if (empty($files)) {
            return [];
        }

        // Sort by filename to process chunks in order
        sort($files);

        return $files;
    }

    /**
     * Ensure processed directory exists and return its path
     */
    private function ensureProcessedDirectory(string $baseDir): string
    {
        $processedDir = rtrim($baseDir, '/') . '/processed';
        if (!is_dir($processedDir)) {
            mkdir($processedDir, 0755, true);
        }
        return $processedDir;
    }

    /**
     * Move a chunk file to the processed directory
     */
    private function moveToProcessed(string $chunkFile, string $processedDir): void
    {
        $filename = basename($chunkFile);
        $destination = $processedDir . '/' . $filename;

        if (file_exists($destination)) {
            unlink($destination);
        }

        rename($chunkFile, $destination);
    }

    /**
     * Get total item count from chunk files
     */
    private function getTotalItemCount(array $chunkFiles): int
    {
        $total = 0;

        foreach ($chunkFiles as $file) {
            $content = file_get_contents($file);
            $items = json_decode($content, true);

            if (is_array($items)) {
                $total += count($items);
            }
        }

        return $total;
    }
}