# Currency Display: Service Layer

**Status**: Completed
**Priority**: Medium
**Estimated Effort**: Small
**Created**: 2025-11-06
**Part of**: Currency Display Feature (USD/CAD)

## Overview

Extend the UserConfigService to handle currency preference updates with validation. This service layer ensures business logic and validation rules are enforced when users update their currency settings.

## Requirements

Add methods to UserConfigService for:
- Setting currency preferences with validation
- Retrieving currency preferences
- Validating currency codes
- Validating exchange rates

## Implementation Steps

1. **Update UserConfigService** (src/Service/UserConfigService.php)

   Add the following methods:

   ```php
   /**
    * Set user's currency preferences.
    *
    * @throws \InvalidArgumentException if currency or exchange rate is invalid
    */
   public function setCurrencyPreferences(
       User $user,
       string $currency,
       float $exchangeRate
   ): void {
       $this->validateCurrency($currency);
       $this->validateExchangeRate($exchangeRate);

       $config = $this->getUserConfig($user);
       $config->setPreferredCurrency($currency);
       $config->setCadExchangeRate($exchangeRate);
       $config->setUpdatedAt(new \DateTimeImmutable());

       $this->entityManager->flush();
   }

   /**
    * Get user's preferred currency (defaults to USD if not set).
    */
   public function getPreferredCurrency(User $user): string
   {
       $config = $this->getUserConfig($user);
       return $config->getPreferredCurrency() ?? 'USD';
   }

   /**
    * Get user's CAD exchange rate (defaults to 1.38 if not set).
    */
   public function getCadExchangeRate(User $user): float
   {
       $config = $this->getUserConfig($user);
       return $config->getCadExchangeRate() ?? 1.38;
   }

   /**
    * Validate currency code.
    *
    * @throws \InvalidArgumentException if currency is not supported
    */
   private function validateCurrency(string $currency): void
   {
       $allowedCurrencies = ['USD', 'CAD'];

       if (!in_array($currency, $allowedCurrencies, true)) {
           throw new \InvalidArgumentException(
               sprintf(
                   'Invalid currency "%s". Allowed values: %s',
                   $currency,
                   implode(', ', $allowedCurrencies)
               )
           );
       }
   }

   /**
    * Validate exchange rate.
    *
    * @throws \InvalidArgumentException if exchange rate is out of range
    */
   private function validateExchangeRate(float $exchangeRate): void
   {
       if ($exchangeRate < 0.01 || $exchangeRate > 10.00) {
           throw new \InvalidArgumentException(
               sprintf(
                   'Exchange rate must be between 0.01 and 10.00, got: %s',
                   $exchangeRate
               )
           );
       }
   }
   ```

2. **Add PHPDoc Comments**
   - Document all new public methods
   - Include parameter types and descriptions
   - Document exceptions thrown

3. **Consider Future Extension**
   - Structure allows adding more currencies easily
   - Could move `$allowedCurrencies` to configuration file if needed

## Acceptance Criteria

- [ ] `setCurrencyPreferences()` method added and accepts User, currency, and rate
- [ ] `getPreferredCurrency()` method returns user's currency with USD default
- [ ] `getCadExchangeRate()` method returns user's rate with 1.38 default
- [ ] Currency validation rejects anything except 'USD' or 'CAD'
- [ ] Exchange rate validation rejects values < 0.01 or > 10.00
- [ ] InvalidArgumentException thrown with descriptive messages
- [ ] UpdatedAt timestamp set when preferences change
- [ ] Changes persisted to database via entity manager flush
- [ ] All methods have PHPDoc comments

## Testing

### Manual Testing via Symfony Console

Create a test command or use Tinker-like approach:

```php
// In a controller or console command
$user = $userRepository->find(1);

// Test setting valid preferences
try {
    $userConfigService->setCurrencyPreferences($user, 'CAD', 1.40);
    echo "Success: CAD set to 1.40\n";
} catch (\InvalidArgumentException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Test getting preferences
$currency = $userConfigService->getPreferredCurrency($user);
$rate = $userConfigService->getCadExchangeRate($user);
echo "Currency: $currency, Rate: $rate\n";

// Test invalid currency
try {
    $userConfigService->setCurrencyPreferences($user, 'EUR', 1.20);
    echo "ERROR: Should have thrown exception\n";
} catch (\InvalidArgumentException $e) {
    echo "Success: Rejected EUR - " . $e->getMessage() . "\n";
}

// Test invalid exchange rate
try {
    $userConfigService->setCurrencyPreferences($user, 'CAD', 15.00);
    echo "ERROR: Should have thrown exception\n";
} catch (\InvalidArgumentException $e) {
    echo "Success: Rejected 15.00 - " . $e->getMessage() . "\n";
}

try {
    $userConfigService->setCurrencyPreferences($user, 'CAD', 0.001);
    echo "ERROR: Should have thrown exception\n";
} catch (\InvalidArgumentException $e) {
    echo "Success: Rejected 0.001 - " . $e->getMessage() . "\n";
}
```

### Expected Behavior

**Valid inputs:**
- ('USD', 1.00) → Success
- ('CAD', 1.38) → Success
- ('CAD', 0.01) → Success (minimum)
- ('CAD', 10.00) → Success (maximum)
- ('CAD', 1.3845) → Success (4 decimals)

**Invalid inputs:**
- ('EUR', 1.20) → Exception: "Invalid currency EUR..."
- ('', 1.38) → Exception: "Invalid currency ..."
- ('CAD', 0.001) → Exception: "Exchange rate must be between..."
- ('CAD', 11.00) → Exception: "Exchange rate must be between..."
- ('CAD', -1.38) → Exception: "Exchange rate must be between..."

## Edge Cases Handled

- **Null config**: `getUserConfig()` creates config if not exists
- **Null currency in DB**: Getter defaults to 'USD'
- **Null exchange rate in DB**: Getter defaults to 1.38
- **Invalid currency**: Exception with list of allowed values
- **Out of range rate**: Exception with valid range
- **Boundary values**: 0.01 and 10.00 are valid

## Notes

- Validation in service layer enforces business rules
- Form layer will also validate for better UX (duplicate validation is okay)
- Exchange rate range (0.01 to 10.00) covers realistic scenarios
  - CAD/USD historically ranges 0.70 to 1.50
  - 10x buffer allows for hypothetical scenarios
- Consider making allowed currencies a config parameter for future expansion

## Dependencies

- **Requires**: Task 14 (Database & Entity Changes) - must be completed first
- **Required by**: Task 17 (Forms), Task 18 (Settings Page Controller)

## Related Tasks

- Task 14: Currency Display - Database & Entity Changes
- Task 17: Currency Display - Forms (will use this service)
- Task 18: Currency Display - Settings Page (will use this service)
