<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class CurrencyExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('format_price', [$this, 'formatPrice']),
        ];
    }

    public function formatPrice(
        ?float $priceUsd,
        ?string $currency = 'USD',
        ?float $exchangeRate = 1.0
    ): string {
        // Handle null prices
        if ($priceUsd === null) {
            return 'N/A';
        }

        // Default to USD if currency not provided
        $currency = $currency ?? 'USD';
        $exchangeRate = $exchangeRate ?? 1.0;

        // Convert to CAD if needed
        if ($currency === 'CAD') {
            $convertedPrice = $priceUsd * $exchangeRate;
            return 'CA$' . number_format($convertedPrice, 2, '.', ',');
        }

        // Default to USD
        return '$' . number_format($priceUsd, 2, '.', ',');
    }
}
