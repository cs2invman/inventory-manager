# Currency Display: Database & Entity Changes

**Status**: Not Started
**Priority**: High (Foundational)
**Estimated Effort**: Small
**Created**: 2025-11-06
**Part of**: Currency Display Feature (USD/CAD)

## Overview

Add database fields and entity properties to support user currency preferences. This is the foundational task that enables all other currency display features.

## Requirements

Add two new fields to the UserConfig entity:
- `preferredCurrency`: User's display currency choice (USD or CAD)
- `cadExchangeRate`: User-configurable exchange rate for CAD conversion

## Implementation Steps

1. **Update UserConfig Entity** (src/Entity/UserConfig.php)
   - Add field: `preferredCurrency` - string, length 3, default 'USD'
   - Add field: `cadExchangeRate` - decimal(8,4), default 1.3800
   - Add Doctrine annotations/attributes
   - Add getter/setter methods:
     ```php
     public function getPreferredCurrency(): ?string
     public function setPreferredCurrency(?string $preferredCurrency): static
     public function getCadExchangeRate(): ?float
     public function setCadExchangeRate(?float $cadExchangeRate): static
     ```

2. **Generate Migration**
   ```bash
   docker compose exec php php bin/console make:migration
   ```

3. **Review Migration SQL**
   - Verify it adds both columns to user_config table
   - Ensure DEFAULT values are set correctly
   - Expected SQL:
     ```sql
     ALTER TABLE user_config
     ADD COLUMN preferred_currency VARCHAR(3) DEFAULT 'USD' AFTER updated_at,
     ADD COLUMN cad_exchange_rate DECIMAL(8,4) DEFAULT 1.3800 AFTER preferred_currency;
     ```

4. **Run Migration**
   ```bash
   docker compose exec php php bin/console doctrine:migrations:migrate
   ```

5. **Verify Database Changes**
   ```bash
   docker compose exec php php bin/console doctrine:schema:validate
   ```

## Acceptance Criteria

- [ ] UserConfig entity has `preferredCurrency` property with getter/setter
- [ ] UserConfig entity has `cadExchangeRate` property with getter/setter
- [ ] Migration file generated successfully
- [ ] Migration runs without errors
- [ ] Database schema validation passes
- [ ] Existing user_config records have default values (USD, 1.3800)
- [ ] New UserConfig instances default to USD and 1.38

## Testing

### Manual Verification
```bash
# Check database schema
docker compose exec mariadb mysql -u root -p cs2inventory -e "DESCRIBE user_config;"

# Verify existing records have defaults
docker compose exec mariadb mysql -u root -p cs2inventory -e "SELECT id, preferred_currency, cad_exchange_rate FROM user_config;"
```

### Entity Test (Optional)
Create a quick test script or use Symfony console to verify:
```php
$config = new UserConfig();
// Should default to USD and 1.3800
var_dump($config->getPreferredCurrency()); // NULL initially (set by DB default on persist)
var_dump($config->getCadExchangeRate()); // NULL initially (set by DB default on persist)
```

## Notes

- Decimal(8,4) allows rates from 0.0001 to 9999.9999
- VARCHAR(3) for currency allows future expansion (EUR, GBP, etc.)
- Defaults ensure backward compatibility with existing users
- Fields are nullable in PHP but have DB defaults for new rows

## Dependencies

None - this is the foundational task

## Blocks

- Task 15 (Twig Extension) - needs these fields to exist
- Task 16 (Service Layer) - needs these fields to exist
- All other currency display tasks

## Related Tasks

- Task 15: Currency Display - Twig Extension
- Task 16: Currency Display - Service Layer
- Task 17: Currency Display - Forms
- Task 18: Currency Display - Settings Page
