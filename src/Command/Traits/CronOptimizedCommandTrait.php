<?php

namespace App\Command\Traits;

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Trait for common console command functionality.
 *
 * Provides helper methods for:
 * - Verbosity checking (quiet mode, verbose mode, debug mode)
 * - Progress bar creation (only when --progress flag is set)
 * - Memory management utilities
 * - Consistent output formatting
 */
trait CronOptimizedCommandTrait
{
    /**
     * Check if command is in quiet mode (no output except errors).
     */
    protected function isQuiet(OutputInterface $output): bool
    {
        return $output->getVerbosity() < OutputInterface::VERBOSITY_VERBOSE;
    }

    /**
     * Check if command is in verbose mode (-v).
     */
    protected function isVerbose(OutputInterface $output): bool
    {
        return $output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE;
    }

    /**
     * Check if command is in very verbose mode (-vv).
     */
    protected function isVeryVerbose(OutputInterface $output): bool
    {
        return $output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE;
    }

    /**
     * Check if command is in debug mode (-vvv).
     */
    protected function isDebug(OutputInterface $output): bool
    {
        return $output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG;
    }

    /**
     * Create a progress bar if --progress flag is set and output is not quiet.
     *
     * @param InputInterface $input The input interface
     * @param OutputInterface $output The output interface
     * @param SymfonyStyle $io The Symfony style instance
     * @param int $totalItems Total number of items to process
     * @return ProgressBar|null Progress bar instance or null if not needed
     */
    protected function createProgressBarIfRequested(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        int $totalItems
    ): ?ProgressBar {
        // Only create progress bar if:
        // 1. --progress flag is set
        // 2. Output is not quiet
        // 3. Output is a TTY (not redirected)
        if (!$input->getOption('progress') || $this->isQuiet($output) || !$output->isDecorated()) {
            return null;
        }

        $progressBar = $io->createProgressBar($totalItems);
        $progressBar->start();

        return $progressBar;
    }

    /**
     * Clear entity manager periodically to prevent memory bloat.
     *
     * @param \Doctrine\ORM\EntityManagerInterface $em Entity manager to clear
     * @param int $count Current item count
     * @param int $frequency How often to clear (default: every 10 items)
     */
    protected function clearEntityManagerPeriodically(
        \Doctrine\ORM\EntityManagerInterface $em,
        int $count,
        int $frequency = 10
    ): void {
        if ($count % $frequency === 0) {
            $em->clear();
            gc_collect_cycles();
        }
    }

    /**
     * Log memory usage at debug level (-vvv).
     *
     * @param \Psr\Log\LoggerInterface $logger Logger instance
     * @param OutputInterface $output Output interface for verbosity checking
     * @param string $context Context message (e.g., "After processing chunk 1")
     */
    protected function logMemoryUsage(
        \Psr\Log\LoggerInterface $logger,
        OutputInterface $output,
        string $context
    ): void {
        if (!$this->isDebug($output)) {
            return;
        }

        $current = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);
        $limit = $this->parseMemoryLimit(ini_get('memory_limit'));
        $percentage = $limit > 0 ? ($current / $limit) * 100 : 0;

        $logger->debug('Memory usage', [
            'context' => $context,
            'current' => $this->formatBytes($current),
            'peak' => $this->formatBytes($peak),
            'limit' => $this->formatBytes($limit),
            'percentage' => round($percentage, 2) . '%',
        ]);
    }

    /**
     * Format bytes to human-readable string.
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Parse PHP memory_limit format to bytes.
     */
    protected function parseMemoryLimit(string $limit): int
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
}
