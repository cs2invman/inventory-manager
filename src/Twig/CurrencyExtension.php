<?php

namespace App\Twig;

use App\Entity\UserConfig;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class CurrencyExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('format_price', [$this, 'formatPrice']),
            new TwigFilter('currency', [$this, 'formatCurrency']),
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

    /**
     * Format currency amount based on original currency and user preferences
     */
    public function formatCurrency(
        ?string $amount,
        ?string $originalCurrency,
        ?UserConfig $userConfig = null
    ): string {
        // Handle null amounts
        if ($amount === null) {
            return 'N/A';
        }

        $amountFloat = (float) $amount;
        $preferredCurrency = $userConfig?->getPreferredCurrency() ?? 'USD';
        $exchangeRate = $userConfig?->getCadExchangeRate() ?? 1.38;

        // Convert amount to USD first (if original is CAD)
        $amountInUsd = $amountFloat;
        if ($originalCurrency === 'CAD') {
            $amountInUsd = $amountFloat / $exchangeRate;
        }

        // Convert to user's preferred currency
        if ($preferredCurrency === 'CAD') {
            $convertedAmount = $amountInUsd * $exchangeRate;
            return 'CA$' . number_format($convertedAmount, 2, '.', ',');
        }

        // Display in USD
        return '$' . number_format($amountInUsd, 2, '.', ',');
    }
}
