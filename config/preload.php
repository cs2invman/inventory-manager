<?php

// Custom preload script that excludes Twig to avoid anonymous class warnings
// See: https://github.com/php/php-src/issues/10131
// Twig uses anonymous classes that cause "Can't preload unlinked class" warnings
// This is a known limitation of PHP's opcache preloading feature with PHP 8.4

$projectDir = dirname(__DIR__);
$preloadFile = $projectDir . '/var/cache/prod/App_KernelProdContainer.preload.php';

if (!file_exists($preloadFile)) {
    return;
}

// Read the Symfony-generated preload file
$preloadContent = file_get_contents($preloadFile);

// Parse out the file paths from opcache_compile_file() calls
preg_match_all('/opcache_compile_file\([\'"](.+?)[\'"]\)/', $preloadContent, $matches);

if (!empty($matches[1])) {
    foreach ($matches[1] as $filePath) {
        // Skip Twig files to avoid anonymous class preload warnings
        if (stripos($filePath, '/twig/') !== false) {
            continue;
        }

        // Skip Twig namespace classes
        if (stripos($filePath, DIRECTORY_SEPARATOR . 'Twig' . DIRECTORY_SEPARATOR) !== false) {
            continue;
        }

        // Preload the file if it exists
        if (file_exists($filePath)) {
            opcache_compile_file($filePath);
        }
    }
}
