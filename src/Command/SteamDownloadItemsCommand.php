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

    public function __construct(
        private readonly SteamWebApiClient $apiClient,
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

        // Increase memory limit for large API responses (can be 100MB+, requires ~1GB with processing overhead)
        ini_set('memory_limit', '1024M');

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

            // Check for recent file unless --force is used
            if (!$input->getOption('force')) {
                $recentFile = $this->findRecentFile($outputDir);
                if ($recentFile) {
                    $io->warning("A recent file was downloaded less than " . self::RECENT_FILE_THRESHOLD_MINUTES . " minutes ago:");
                    $io->text("  {$recentFile}");
                    $io->text("Use --force to download anyway.");
                    return Command::SUCCESS;
                }
            }

            // Download items
            $io->text('Downloading CS2 items from SteamWebAPI...');

            $startTime = microtime(true);
            $jsonContent = $this->apiClient->fetchAllItems();
            $duration = round(microtime(true) - $startTime, 2);

            // Decode to count items
            $items = json_decode($jsonContent, true);
            $itemCount = is_array($items) ? count($items) : 0;

            // Generate filename with timestamp
            $timestamp = (new \DateTimeImmutable())->format('Y-m-d-His');
            $filename = "items-{$timestamp}.json";
            $filepath = "{$outputDir}/{$filename}";

            // Write to file
            $bytesWritten = file_put_contents($filepath, $jsonContent);

            if ($bytesWritten === false) {
                $io->error("Failed to write file: {$filepath}");
                return Command::FAILURE;
            }

            // Create/update symlink
            $symlinkPath = "{$outputDir}/items-latest.json";
            if (file_exists($symlinkPath)) {
                unlink($symlinkPath);
            }
            symlink($filename, $symlinkPath);

            // Clean up old files (keep last 7 days)
            $this->cleanupOldFiles($outputDir);

            // Display success message
            $io->success('Download completed successfully');

            $io->definitionList(
                ['Items downloaded' => number_format($itemCount)],
                ['File size' => $this->formatBytes($bytesWritten)],
                ['Duration' => "{$duration}s"],
                ['Saved to' => $filepath],
                ['Symlink' => $symlinkPath]
            );

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $this->logger->error('Failed to download items from SteamWebAPI', [
                'error' => $e->getMessage(),
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
     * Clean up old download files
     */
    private function cleanupOldFiles(string $directory): void
    {
        $pattern = $directory . '/items-*.json';
        $files = glob($pattern);

        if (empty($files)) {
            return;
        }

        $threshold = time() - (7 * 24 * 60 * 60); // 7 days ago

        foreach ($files as $file) {
            // Skip symlinks
            if (is_link($file)) {
                continue;
            }

            if (filemtime($file) < $threshold) {
                unlink($file);
                $this->logger->info('Deleted old item file', ['file' => $file]);
            }
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
}