<?php

namespace App\Command;

use App\Service\ItemSyncService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

#[AsCommand(
    name: 'app:steam:sync-items',
    description: 'Sync CS2 item data from JSON file to database'
)]
class SteamSyncItemsCommand extends Command
{
    private const MAX_FILES_PER_RUN = 2;
    private const LOCK_TTL = 180; // 3 minutes max execution time (reduced since we only process 2 files)

    private ?LockInterface $lock = null;

    public function __construct(
        private readonly ItemSyncService $syncService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly string $storageBasePath,
        private readonly LockFactory $lockFactory
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
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

        // Set memory limit for processing
        ini_set('memory_limit', '768M');

        // Acquire lock to prevent concurrent executions
        // Using flock which is automatically released on process termination (even on crash/OOM)
        $this->lock = $this->lockFactory->createLock('steam-sync-items', self::LOCK_TTL);

        if (!$this->lock->acquire()) {
            // Another instance is already running, exit silently
            $this->logger->info('Sync already running, skipping this execution');
            return Command::SUCCESS;
        }

        // Register signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
        }

        try {
            // Find chunk files in import directory
            $importDir = $this->storageBasePath . '/import';
            $chunkFiles = $this->findChunkFiles($importDir);

            // If no files found, exit silently (cron-friendly)
            if (empty($chunkFiles)) {
                return Command::SUCCESS;
            }

            // Limit to MAX_FILES_PER_RUN files to prevent memory issues
            $chunkFiles = array_slice($chunkFiles, 0, self::MAX_FILES_PER_RUN);

            $this->logger->info('Starting sync', [
                'files_found' => count($chunkFiles),
                'max_per_run' => self::MAX_FILES_PER_RUN,
            ]);

            // Process chunk files
            return $this->executeChunkedSync($input, $output, $io, $chunkFiles);

        } catch (\Throwable $e) {
            $this->logger->error('Failed to sync items from JSON file', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $io->error('Failed to sync items: ' . $e->getMessage());
            return Command::FAILURE;
        } finally {
            // Always release the lock
            if ($this->lock) {
                $this->lock->release();
            }
        }
    }

    /**
     * Execute sync from chunked files with aggressive memory management
     */
    private function executeChunkedSync(InputInterface $input, OutputInterface $output, SymfonyStyle $io, array $chunkFiles): int
    {
        $chunkCount = count($chunkFiles);
        $skipPrices = $input->getOption('skip-prices');

        $this->logger->info('Starting chunked sync', [
            'chunk_count' => $chunkCount,
            'skip_prices' => $skipPrices,
        ]);

        // Track progress
        $startTime = microtime(true);
        $processedDir = $this->ensureProcessedDirectory($this->storageBasePath);

        $aggregatedStats = [
            'added' => 0,
            'updated' => 0,
            'price_records_created' => 0,
            'skipped' => 0,
            'total' => 0,
        ];

        // Log memory usage at start
        $this->logMemoryUsage('Start of sync');

        // Process each chunk with per-file error handling
        foreach ($chunkFiles as $index => $chunkFile) {
            $chunkNum = $index + 1;
            $filename = basename($chunkFile);

            $this->logger->info("Processing chunk {$chunkNum}/{$chunkCount}", [
                'file' => $filename,
            ]);

            try {
                // Sync this chunk
                $stats = $this->syncService->syncFromJsonFile(
                    $chunkFile,
                    $skipPrices,
                    null // No progress callback for cron
                );

                // Aggregate statistics
                $aggregatedStats['added'] += $stats['added'];
                $aggregatedStats['updated'] += $stats['updated'];
                $aggregatedStats['price_records_created'] += $stats['price_records_created'];
                $aggregatedStats['skipped'] += $stats['skipped'];
                $aggregatedStats['total'] += $stats['total'];

                // Move processed chunk to processed directory
                $this->moveToProcessed($chunkFile, $processedDir);

                $this->logger->info("Chunk {$chunkNum}/{$chunkCount} completed", [
                    'added' => $stats['added'],
                    'updated' => $stats['updated'],
                    'skipped' => $stats['skipped'],
                ]);

                // AGGRESSIVE MEMORY CLEANUP
                unset($stats);
                $this->entityManager->clear();
                gc_collect_cycles();

                // Log memory usage after each chunk
                $this->logMemoryUsage("After chunk {$chunkNum}/{$chunkCount}");

            } catch (\Throwable $e) {
                // Log error but continue processing
                $this->logger->error('Failed to process chunk file', [
                    'file' => $filename,
                    'chunk' => $chunkNum,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                // Clean up and continue
                $this->entityManager->clear();
                gc_collect_cycles();

                // Don't move failed file, leave in import for retry
                continue;
            }
        }

        // Final memory cleanup
        $this->entityManager->clear();
        gc_collect_cycles();

        $this->logMemoryUsage('End of sync');

        $duration = round(microtime(true) - $startTime, 2);

        // Log final statistics
        $this->logger->info('Sync completed successfully', array_merge($aggregatedStats, [
            'chunks_processed' => $chunkCount,
            'duration_seconds' => $duration,
        ]));

        return Command::SUCCESS;
    }

    /**
     * Log current memory usage with context
     */
    private function logMemoryUsage(string $context): void
    {
        $current = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);
        $limit = $this->parseMemoryLimit(ini_get('memory_limit'));
        $percentage = $limit > 0 ? ($current / $limit) * 100 : 0;

        $this->logger->info('Memory usage', [
            'context' => $context,
            'current' => $this->formatBytes($current),
            'peak' => $this->formatBytes($peak),
            'limit' => $this->formatBytes($limit),
            'percentage' => round($percentage, 2) . '%',
        ]);

        // Warn if memory usage is high
        if ($percentage > 80) {
            $this->logger->warning('Memory usage high', [
                'context' => $context,
                'percentage' => round($percentage, 2) . '%',
                'current' => $this->formatBytes($current),
                'limit' => $this->formatBytes($limit),
            ]);
        }
    }

    /**
     * Parse PHP memory_limit format to bytes
     */
    private function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);

        // Handle unlimited (-1)
        if ($limit === '-1') {
            return PHP_INT_MAX;
        }

        // Extract number and unit
        $value = (int) $limit;
        $unit = strtoupper(substr($limit, -1));

        // Convert to bytes
        return match ($unit) {
            'G' => $value * 1024 * 1024 * 1024,
            'M' => $value * 1024 * 1024,
            'K' => $value * 1024,
            default => $value,
        };
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
     * Handle termination signals (SIGTERM, SIGINT) for graceful shutdown
     */
    public function handleSignal(int $signal): void
    {
        $this->logger->warning('Received termination signal, shutting down gracefully', [
            'signal' => $signal,
        ]);

        // Release lock before exiting
        if ($this->lock) {
            $this->lock->release();
        }

        exit(0);
    }

}