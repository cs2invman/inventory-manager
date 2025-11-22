<?php

namespace App\Command;

use App\Command\Traits\CronOptimizedCommandTrait;
use App\Message\SendDiscordNotificationMessage;
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
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:steam:sync-items',
    description: 'Sync CS2 item data from JSON file to database'
)]
class SteamSyncItemsCommand extends Command
{
    use CronOptimizedCommandTrait;

    private const MAX_FILES_PER_RUN = 2;
    private const LOCK_TTL = 180; // 3 minutes max execution time (reduced since we only process 2 files)

    private ?LockInterface $lock = null;

    public function __construct(
        private readonly ItemSyncService $syncService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $syncLogger,
        private readonly LoggerInterface $logger,
        private readonly string $storageBasePath,
        private readonly LockFactory $lockFactory,
        private readonly MessageBusInterface $messageBus,
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
            ->addOption(
                'progress',
                null,
                InputOption::VALUE_NONE,
                'Show progress bar during processing'
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
            $this->syncLogger->info('Sync already running, skipping this execution');
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

            $this->syncLogger->info('Files found for processing', [
                'count' => count($chunkFiles),
                'files' => array_map('basename', $chunkFiles),
            ]);

            // Process chunk files
            return $this->executeChunkedSync($input, $output, $io, $chunkFiles);

        } catch (\Throwable $e) {
            $this->syncLogger->error('Sync failed', [
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            // Send Discord notification for sync failure
            $this->sendSyncFailureNotification($e);

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

                $this->syncLogger->info("Chunk {$chunkNum}/{$chunkCount} completed", [
                    'file' => $filename,
                    'chunk_stats' => [
                        'added' => $stats['added'],
                        'updated' => $stats['updated'],
                        'skipped' => $stats['skipped'],
                    ],
                    'total_processed' => $aggregatedStats['total'],
                    'memory_peak' => $this->formatBytes(memory_get_peak_usage(true)),
                ]);

                // AGGRESSIVE MEMORY CLEANUP
                unset($stats);
                $this->entityManager->clear();
                gc_collect_cycles();

                // Log memory usage after each chunk
                $this->logMemoryUsage("After chunk {$chunkNum}/{$chunkCount}");

            } catch (\Throwable $e) {
                // Log error but continue processing
                $this->syncLogger->error('Chunk processing failed', [
                    'file' => $filename,
                    'error' => $e->getMessage(),
                    'error_class' => get_class($e),
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
        $this->syncLogger->info('Sync completed successfully', [
            'total_stats' => $aggregatedStats,
            'chunks_processed' => $chunkCount,
            'duration_seconds' => $duration,
            'memory_peak' => $this->formatBytes(memory_get_peak_usage(true)),
        ]);

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

        $this->syncLogger->info('Memory usage', [
            'context' => $context,
            'current' => $this->formatBytes($current),
            'peak' => $this->formatBytes($peak),
            'limit' => $this->formatBytes($limit),
            'percentage' => round($percentage, 2) . '%',
        ]);

        // Warn if memory usage is high
        if ($percentage > 80) {
            $this->syncLogger->warning('Memory usage high', [
                'context' => $context,
                'percentage' => round($percentage, 2) . '%',
                'current' => $this->formatBytes($current),
                'limit' => $this->formatBytes($limit),
            ]);
        }
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

    /**
     * Send Discord notification for sync failure.
     */
    private function sendSyncFailureNotification(\Throwable $exception): void
    {
        try {
            $embed = [
                'title' => 'Steam Item Sync Failed',
                'description' => 'An error occurred during Steam CS2 item synchronization.',
                'color' => 0xe74c3c, // Red
                'fields' => [
                    [
                        'name' => 'Error Type',
                        'value' => get_class($exception),
                        'inline' => false,
                    ],
                    [
                        'name' => 'Error Message',
                        'value' => mb_substr($exception->getMessage(), 0, 1024),
                        'inline' => false,
                    ],
                ],
                'timestamp' => (new \DateTimeImmutable())->format('c'),
            ];

            $message = new SendDiscordNotificationMessage(
                notificationType: 'system_event',
                webhookConfigKey: 'system_events',
                content: 'Steam Item Sync Failed',
                embed: $embed
            );

            $this->messageBus->dispatch($message);
        } catch (\Exception $e) {
            // Log error but don't fail the command
            $this->logger->error('Failed to send Discord sync failure notification', [
                'error' => $e->getMessage(),
            ]);
        }
    }

}