<?php

namespace App\Command;

use App\Service\SteamWebApiClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:steam:download-items',
    description: 'Download CS2 item data from SteamWebAPI and save to JSON file'
)]
class SteamDownloadItemsCommand extends Command
{
    private const RECENT_FILE_THRESHOLD_MINUTES = 25;
    private const CHUNK_SIZE = 5500;

    public function __construct(
        private readonly SteamWebApiClient $apiClient,
        private readonly LoggerInterface $downloadLogger,
        private readonly LoggerInterface $logger,
        private readonly string $storageBasePath
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'output-dir',
                null,
                InputOption::VALUE_REQUIRED,
                'Override default storage directory'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Download even if a recent file exists'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Reduce memory limit with chunked downloads (was 1024M, now 512M)
        ini_set('memory_limit', '512M');

        // Log command start
        $this->downloadLogger->info('Download command started');

        try {
            // Determine output directory
            $outputDir = $input->getOption('output-dir') ?? $this->storageBasePath;
            $outputDir = rtrim($outputDir, '/');

            // Ensure directory exists
            if (!is_dir($outputDir)) {
                if (!mkdir($outputDir, 0755, true)) {
                    $io->error("Failed to create directory: {$outputDir}");
                    return Command::FAILURE;
                }
            }

            // Create import directory
            $importDir = $this->ensureImportDirectory($outputDir);

            // Check for recent file unless --force is used
            // For chunked downloads, we consider this a fresh download each time
            if (!$input->getOption('force')) {
                $recentFile = $this->findRecentFile($outputDir);
                if ($recentFile) {
                    $this->downloadLogger->info('Recent file found, skipping download', [
                        'file' => basename($recentFile),
                    ]);
                    $io->warning("A recent file was downloaded less than " . self::RECENT_FILE_THRESHOLD_MINUTES . " minutes ago:");
                    $io->text("  {$recentFile}");
                    $io->text("Use --force to download anyway.");
                    return Command::SUCCESS;
                }
            }

            // Generate timestamp for this batch
            $timestamp = (new \DateTimeImmutable())->format('Y-m-d-His');

            // Download first chunk to determine total count
            $io->text('Downloading CS2 items from SteamWebAPI (chunked)...');
            $io->newLine();

            $startTime = microtime(true);
            $firstChunkJson = $this->apiClient->fetchItemsPaginated(self::CHUNK_SIZE, 1);
            $firstChunkItemCount = $this->parseItemCount($firstChunkJson);

            // Calculate total chunks needed
            // Note: The first page tells us how many items we got, but we need to estimate total
            // For CS2, we know there are ~26,000 items, so we'll download until we get an empty/small response
            // Let's use a more robust approach: keep downloading until we get fewer items than CHUNK_SIZE
            $totalChunks = (int) ceil(26000 / self::CHUNK_SIZE); // Initial estimate: 5 chunks

            $totalBytesWritten = 0;
            $totalItemsDownloaded = 0;
            $chunks = [];
            $page = 1;

            // Process first chunk
            $filename = $this->generateChunkFilename($timestamp, $page, $totalChunks);
            $filepath = "{$importDir}/{$filename}";
            $bytesWritten = file_put_contents($filepath, $firstChunkJson);

            if ($bytesWritten === false) {
                $io->error("Failed to write chunk file: {$filepath}");
                return Command::FAILURE;
            }

            $totalBytesWritten += $bytesWritten;
            $totalItemsDownloaded += $firstChunkItemCount;
            $chunks[] = $filename;

            $this->downloadLogger->info("Chunk {$page} downloaded", [
                'chunk_num' => $page,
                'file' => $filename,
                'items_in_chunk' => $firstChunkItemCount,
                'total_items' => $totalItemsDownloaded,
            ]);

            $io->text("Chunk {$page} of ~{$totalChunks}: {$firstChunkItemCount} items, {$this->formatBytes($bytesWritten)}");

            // Download remaining chunks
            $page = 2;
            while (true) {
                $chunkJson = $this->apiClient->fetchItemsPaginated(self::CHUNK_SIZE, $page);
                $chunkItemCount = $this->parseItemCount($chunkJson);

                // If we got no items or very few, we're done
                if ($chunkItemCount === 0) {
                    break;
                }

                $filename = $this->generateChunkFilename($timestamp, $page, $totalChunks);
                $filepath = "{$importDir}/{$filename}";
                $bytesWritten = file_put_contents($filepath, $chunkJson);

                if ($bytesWritten === false) {
                    $io->error("Failed to write chunk file: {$filepath}");
                    // Continue with other chunks instead of failing entirely
                    $this->logger->warning("Failed to write chunk {$page}", ['filepath' => $filepath]);
                    $page++;
                    continue;
                }

                $totalBytesWritten += $bytesWritten;
                $totalItemsDownloaded += $chunkItemCount;
                $chunks[] = $filename;

                $this->downloadLogger->info("Chunk {$page} downloaded", [
                    'chunk_num' => $page,
                    'file' => $filename,
                    'items_in_chunk' => $chunkItemCount,
                    'total_items' => $totalItemsDownloaded,
                ]);

                $io->text("Chunk {$page} of ~{$totalChunks}: {$chunkItemCount} items, {$this->formatBytes($bytesWritten)}");

                // If this chunk had fewer items than CHUNK_SIZE, we're likely done
                if ($chunkItemCount < self::CHUNK_SIZE) {
                    break;
                }

                $page++;
            }

            // Update filenames with actual total chunk count
            $actualTotalChunks = count($chunks);
            if ($actualTotalChunks !== $totalChunks) {
                // Rename files with correct total
                foreach ($chunks as $index => $oldFilename) {
                    $chunkNum = $index + 1;
                    $newFilename = $this->generateChunkFilename($timestamp, $chunkNum, $actualTotalChunks);
                    $oldPath = "{$importDir}/{$oldFilename}";
                    $newPath = "{$importDir}/{$newFilename}";

                    if ($oldFilename !== $newFilename && file_exists($oldPath)) {
                        rename($oldPath, $newPath);
                        $chunks[$index] = $newFilename;
                    }
                }
            }

            $duration = round(microtime(true) - $startTime, 2);

            // Clean up old files (keep last 7 days)
            $deletedCount = $this->cleanupOldFiles($outputDir);
            if ($deletedCount > 0) {
                $this->downloadLogger->info('Cleaned up old files', [
                    'files_deleted' => $deletedCount,
                ]);
            }

            // Log completion
            $this->downloadLogger->info('Download completed successfully', [
                'total_items' => $totalItemsDownloaded,
                'total_chunks' => $actualTotalChunks,
                'duration_seconds' => $duration,
                'memory_peak' => $this->formatBytes(memory_get_peak_usage(true)),
            ]);

            // Display success message
            $io->newLine();
            $io->success('Download completed successfully');

            $io->definitionList(
                ['Items downloaded' => number_format($totalItemsDownloaded)],
                ['Total chunks' => $actualTotalChunks],
                ['Chunk size' => number_format(self::CHUNK_SIZE) . ' items/chunk'],
                ['Total file size' => $this->formatBytes($totalBytesWritten)],
                ['Duration' => "{$duration}s"],
                ['Saved to' => $importDir]
            );

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $this->downloadLogger->error('Download failed', [
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            $io->error('Failed to download items: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Find a recently downloaded file
     */
    private function findRecentFile(string $directory): ?string
    {
        $pattern = $directory . '/items-*.json';
        $files = glob($pattern);

        if (empty($files)) {
            return null;
        }

        // Get the most recent file
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        $mostRecent = $files[0];

        // Check if it's recent enough
        $threshold = time() - (self::RECENT_FILE_THRESHOLD_MINUTES * 60);
        if (filemtime($mostRecent) > $threshold) {
            return $mostRecent;
        }

        return null;
    }

    /**
     * Clean up old download files (both single files and chunks)
     */
    private function cleanupOldFiles(string $directory): int
    {
        $threshold = time() - (7 * 24 * 60 * 60); // 7 days ago
        $deletedCount = 0;

        // Clean up root-level single files (legacy format)
        $pattern = $directory . '/items-*.json';
        $files = glob($pattern);

        if (!empty($files)) {
            foreach ($files as $file) {
                // Skip symlinks
                if (is_link($file)) {
                    continue;
                }

                if (filemtime($file) < $threshold) {
                    unlink($file);
                    $deletedCount++;
                }
            }
        }

        // Clean up chunk files from import/ folder
        $importDir = $directory . '/import';
        if (is_dir($importDir)) {
            $chunkPattern = $importDir . '/items-chunk-*.json';
            $chunkFiles = glob($chunkPattern);

            if (!empty($chunkFiles)) {
                foreach ($chunkFiles as $file) {
                    if (filemtime($file) < $threshold) {
                        unlink($file);
                        $deletedCount++;
                    }
                }
            }
        }

        // Clean up chunk files from processed/ folder (preparing for task 7-2)
        $processedDir = $directory . '/processed';
        if (is_dir($processedDir)) {
            $processedPattern = $processedDir . '/items-chunk-*.json';
            $processedFiles = glob($processedPattern);

            if (!empty($processedFiles)) {
                foreach ($processedFiles as $file) {
                    if (filemtime($file) < $threshold) {
                        unlink($file);
                        $deletedCount++;
                    }
                }
            }
        }

        return $deletedCount;
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
     * Parse item count from JSON response
     */
    private function parseItemCount(string $json): int
    {
        $items = json_decode($json, true);
        return is_array($items) ? count($items) : 0;
    }

    /**
     * Generate chunk filename with timestamp and numbers
     */
    private function generateChunkFilename(string $timestamp, int $chunkNum, int $totalChunks): string
    {
        $paddedNum = str_pad((string)$chunkNum, 3, '0', STR_PAD_LEFT);
        $paddedTotal = str_pad((string)$totalChunks, 3, '0', STR_PAD_LEFT);
        return "items-chunk-{$timestamp}-{$paddedNum}-of-{$paddedTotal}.json";
    }

    /**
     * Ensure import directory exists and return its path
     */
    private function ensureImportDirectory(string $baseDir): string
    {
        $importDir = rtrim($baseDir, '/') . '/import';
        if (!is_dir($importDir)) {
            mkdir($importDir, 0755, true);
        }
        return $importDir;
    }
}