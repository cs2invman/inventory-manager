# Currency Display: Twig Extension

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Small
**Created**: 2025-11-06
**Part of**: Currency Display Feature (USD/CAD)

## Overview

Create a Twig extension with a custom filter to format prices in the user's preferred currency. This filter will handle conversion from USD to CAD and apply proper currency symbols.

## Requirements

Create a reusable Twig filter that:
- Accepts a USD price, currency preference, and exchange rate
- Converts to CAD if needed
- Formats with proper currency symbol ($ or CA$)
- Handles edge cases (null, zero, large numbers)

## Implementation Steps

1. **Create Twig Extension** (src/Twig/CurrencyExtension.php)
   ```php
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
   ```

2. **Register Extension** (should auto-register via services.yaml autoconfigure)
   - Verify `services.yaml` has:
     ```yaml
     App\Twig\:
         resource: '../src/Twig/'
         tags: ['twig.extension']
     ```
   - If not, add explicit service definition

3. **Test Filter in Isolation**
   - Create test template or use Symfony console
   - Test cases:
     - USD: `{{ 10.00|format_price('USD', 1.0) }}` → "$10.00"
     - CAD: `{{ 10.00|format_price('CAD', 1.38) }}` → "CA$13.80"
     - Null: `{{ null|format_price('USD', 1.0) }}` → "N/A"
     - Large: `{{ 1234.56|format_price('CAD', 1.38) }}` → "CA$1,703.69"
     - Zero: `{{ 0|format_price('CAD', 1.38) }}` → "CA$0.00"

## Usage Example

In templates:
```twig
{# Basic usage with all parameters #}
{{ priceValue|format_price(user.config.preferredCurrency, user.config.cadExchangeRate) }}

{# With default parameters (USD) #}
{{ priceValue|format_price() }}

{# Just USD explicitly #}
{{ priceValue|format_price('USD') }}
```

## Acceptance Criteria

- [ ] CurrencyExtension.php created in src/Twig/
- [ ] `format_price` filter accepts three parameters: price, currency, exchange rate
- [ ] Returns "N/A" for null prices
- [ ] Returns "$X.XX" format for USD prices
- [ ] Returns "CA$X.XX" format for CAD prices
- [ ] Applies exchange rate multiplication for CAD
- [ ] Includes thousands separator for large amounts (e.g., "CA$1,234.56")
- [ ] Formats to 2 decimal places
- [ ] Extension auto-registered and available in templates
- [ ] Filter works when called from any template

## Testing

### Manual Testing via Template

Create a test template (templates/test/currency.html.twig):
```twig
{% extends 'base.html.twig' %}

{% block body %}
<div class="p-8">
    <h1>Currency Filter Test</h1>

    <h2>USD Tests</h2>
    <p>{{ 10.00|format_price('USD', 1.0) }}</p>
    <p>{{ 1234.56|format_price('USD', 1.0) }}</p>
    <p>{{ 0|format_price('USD', 1.0) }}</p>

    <h2>CAD Tests</h2>
    <p>{{ 10.00|format_price('CAD', 1.38) }}</p>
    <p>{{ 1234.56|format_price('CAD', 1.38) }}</p>
    <p>{{ 0|format_price('CAD', 1.38) }}</p>

    <h2>Edge Cases</h2>
    <p>Null: {{ null|format_price('USD', 1.0) }}</p>
    <p>Default params: {{ 10.00|format_price() }}</p>
</div>
{% endblock %}
```

Add route to test:
```php
#[Route('/test/currency', name: 'test_currency')]
public function testCurrency(): Response
{
    return $this->render('test/currency.html.twig');
}
```

Visit `/test/currency` and verify output.

### Expected Results
- 10.00 USD → "$10.00"
- 1234.56 USD → "$1,234.56"
- 10.00 CAD (1.38) → "CA$13.80"
- 1234.56 CAD (1.38) → "CA$1,703.69"
- null → "N/A"

## Edge Cases Handled

- **Null price**: Returns "N/A" string
- **Zero price**: Returns "$0.00" or "CA$0.00"
- **Null currency**: Defaults to USD
- **Null exchange rate**: Defaults to 1.0 (no conversion)
- **Large numbers**: Thousands separator applied
- **Small numbers**: Proper 2 decimal formatting

## Notes

- Filter is stateless and has no side effects
- No database queries - pure calculation
- Exchange rate precision of 4 decimals handled by entity layer
- Consider adding type hints and return type for PHP 8.4 strict types

## Dependencies

- **Requires**: Task 14 (Database fields must exist, though not strictly required for filter to work)
- **Required by**: Task 18 (Settings Page), Task 19 (Inventory Pages), Task 20 (Import Preview), Task 21 (Storage Box Pages)

## Related Tasks

- Task 14: Currency Display - Database & Entity Changes
- Task 19: Currency Display - Inventory Pages (will use this filter)
- Task 20: Currency Display - Import Preview (will use this filter)
- Task 21: Currency Display - Storage Box Pages (will use this filter)
