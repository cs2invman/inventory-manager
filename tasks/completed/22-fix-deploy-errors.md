# Fix Doctrine Deprecation Warnings for Future-Proofing

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Small
**Created**: 2025-11-06

## Overview

Resolve 6 Doctrine/DoctrineBundle deprecation warnings appearing during production deployments. These warnings indicate configurations that will be removed in Doctrine ORM 3.0 and DoctrineBundle 3.0. Fixing them now ensures a smooth upgrade path and eliminates console noise.

## Problem Statement

Production deployments are generating deprecation warnings that indicate the application is using legacy Doctrine configurations. While the app functions correctly, these warnings:
1. Clutter deployment logs and make it harder to spot real issues
2. Will cause breaking changes when upgrading to Doctrine ORM 3.0 / DoctrineBundle 3.0
3. Indicate the app is not using modern, recommended patterns

The warnings appear during:
- Database migrations (`doctrine:migrations:migrate`)
- Cache warming (`cache:clear`, `cache:warmup`)

## Requirements

### Functional Requirements
- Eliminate all 6 deprecation warnings from deployment output
- Maintain existing functionality (no behavioral changes)
- Use modern Doctrine best practices for forward compatibility

### Non-Functional Requirements
- Configuration changes must be compatible with PHP 8.4 + Symfony 7+
- Changes should align with Doctrine ORM 2.x/3.x recommended patterns
- No impact on application performance

## Deprecation Warnings Analysis

### 1. Proxy Autoloader Deprecation (3 occurrences)
**Warning**: `Class "Doctrine\ORM\Proxy\Autoloader" is deprecated. Use native lazy objects instead.`

**Root Cause**: Not using native lazy objects feature introduced in Doctrine ORM 2.17.

**Current Config**:
```yaml
doctrine:
    orm:
        enable_lazy_ghost_objects: true  # Present but might need enable_native_lazy_objects
```

**Issue**: The config uses `enable_lazy_ghost_objects` but the deprecation suggests we need `enable_native_lazy_objects: true` (or the config isn't being applied correctly).

### 2. use_savepoints Deprecation
**Warning**: `The "use_savepoints" configuration key is deprecated when using DBAL 4 and will be removed in DoctrineBundle 3.0.`

**Root Cause**: `use_savepoints` configuration is now handled automatically by DBAL 4.

**Current Config**:
```yaml
doctrine:
    dbal:
        use_savepoints: true  # Line 15 in doctrine.yaml
```

**Issue**: This setting is no longer needed and should be removed when using DBAL 4+.

### 3. report_fields_where_declared Deprecation
**Warning**: `The "report_fields_where_declared" configuration option is deprecated and will be removed in DoctrineBundle 3.0. When using ORM 3, report_fields_where_declared will always be true.`

**Root Cause**: In ORM 3, this will always be `true`, so the config option is being removed.

**Current Config**:
```yaml
doctrine:
    orm:
        report_fields_where_declared: true  # Line 19 in doctrine.yaml
```

**Issue**: Explicitly setting this causes deprecation warning. Should remove and let it default.

### 4. controller_resolver.auto_mapping Default Value Change
**Warning**: `The default value of "doctrine.orm.controller_resolver.auto_mapping" will be changed from true to false. Explicitly configure true to keep existing behaviour.`

**Root Cause**: DoctrineBundle is changing the default behavior for entity autowiring in controllers.

**Current Config**: Not explicitly set (relying on default).

**Issue**: Need to explicitly set this to the desired value (likely `false` for modern apps using `#[MapEntity]` attributes).

### 5. controller_resolver.auto_mapping Feature Deprecation
**Warning**: `Enabling the controller resolver automapping feature has been deprecated. Symfony Mapped Route Parameters should be used as replacement.`

**Root Cause**: The old autowiring feature is deprecated in favor of Symfony's `#[MapEntity]` attribute.

**Current Config**: Using default `true` (implicit).

**Issue**: Should disable this feature and use modern Symfony route parameter mapping.

### 6. enable_native_lazy_objects Not Set
**Warning**: `Not setting "doctrine.orm.enable_native_lazy_objects" to true is deprecated.`

**Root Cause**: The new recommended way to handle lazy loading is through native PHP lazy objects.

**Current Config**:
```yaml
doctrine:
    orm:
        enable_lazy_ghost_objects: true  # Wrong config key
```

**Issue**: Using `enable_lazy_ghost_objects` instead of `enable_native_lazy_objects`.

## Technical Approach

### Configuration Changes (config/packages/doctrine.yaml)

All changes are in a single configuration file. The fixes are straightforward config updates.

### No Code Changes Required

The controllers in this app don't appear to use entity autowiring in route parameters (based on grep analysis), so disabling `controller_resolver.auto_mapping` should have zero impact.

## Implementation Steps

### Step 1: Update doctrine.yaml Configuration

**File**: `config/packages/doctrine.yaml`

**Changes**:

1. **Remove `use_savepoints`** (line 15):
   ```yaml
   # REMOVE THIS LINE:
   use_savepoints: true
   ```

2. **Remove `report_fields_where_declared`** (line 19):
   ```yaml
   # REMOVE THIS LINE:
   report_fields_where_declared: true
   ```

3. **Replace `enable_lazy_ghost_objects` with `enable_native_lazy_objects`** (line 18):
   ```yaml
   # CHANGE FROM:
   enable_lazy_ghost_objects: true

   # TO:
   enable_native_lazy_objects: true
   ```

4. **Add `controller_resolver` config** (after line 22, in the `orm` section):
   ```yaml
   controller_resolver:
       auto_mapping: false
   ```

**Full Updated `orm` Section** should look like:
```yaml
doctrine:
    dbal:
        url: '%env(resolve:DATABASE_URL)%'
        server_version: 'mariadb-11.4.8'
        charset: utf8mb4
        default_table_options:
            charset: utf8mb4
            collate: utf8mb4_unicode_ci
        profiling_collect_backtrace: '%kernel.debug%'
        # REMOVED: use_savepoints: true

    orm:
        auto_generate_proxy_classes: true
        enable_native_lazy_objects: true  # CHANGED FROM enable_lazy_ghost_objects
        # REMOVED: report_fields_where_declared: true
        validate_xml_mapping: true
        naming_strategy: doctrine.orm.naming_strategy.underscore_number_aware
        auto_mapping: true
        controller_resolver:  # ADDED
            auto_mapping: false  # ADDED
        mappings:
            App:
                type: attribute
                is_bundle: false
                dir: '%kernel.project_dir%/src/Entity'
                prefix: 'App\Entity'
                alias: App
```

### Step 2: Verify Controllers Don't Use Entity Autowiring

**Action**: Audit all controllers to ensure no route parameters are using entity autowiring.

**Controllers to Check**:
- `src/Controller/StorageBoxController.php`
- `src/Controller/DashboardController.php`
- `src/Controller/UserSettingsController.php`
- `src/Controller/InventoryController.php`
- `src/Controller/InventoryImportController.php`
- `src/Controller/SecurityController.php`

**What to Look For**: Route parameters like:
```php
// OLD PATTERN (would break with auto_mapping: false):
public function show(StorageBox $box): Response
{
    // ...
}

// MODERN PATTERN (correct):
#[Route('/storage-box/{id}', name: 'app_storage_box_show')]
public function show(int $id, StorageBoxRepository $repository): Response
{
    $box = $repository->find($id);
    // ...
}

// OR using #[MapEntity]:
use Symfony\Bridge\Doctrine\Attribute\MapEntity;

#[Route('/storage-box/{id}', name: 'app_storage_box_show')]
public function show(#[MapEntity] StorageBox $box): Response
{
    // ...
}
```

**Expected Result**: Based on initial grep, controllers likely fetch entities via repositories/services, not via autowiring.

### Step 3: Test in Docker Environment

**Commands**:
```bash
# 1. Update configuration (use Edit tool)
# (Step 1 above)

# 2. Clear cache and watch for deprecation warnings
docker compose exec php php bin/console cache:clear

# 3. Warm cache (should be clean - no deprecations)
docker compose exec php php bin/console cache:warmup

# 4. Run migrations (should be clean - no deprecations)
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction

# 5. Test the application manually
# - Login
# - Navigate to inventory
# - Navigate to storage boxes
# - Test inventory import
# - Test storage box deposit/withdraw
```

### Step 4: Test on Production Server

**Deploy Script** should already handle:
1. Pull latest code with updated config
2. Run migrations (verify no deprecation warnings in logs)
3. Clear/warm cache (verify no deprecation warnings in logs)

**Manual Verification**:
```bash
# SSH to production server
cd /path/to/app

# Check that deploy script ran cleanly
tail -n 100 var/log/deploy.log

# Look for absence of deprecation warnings in:
grep -i "deprecated" var/log/deploy.log

# Expected: No matches (or only unrelated warnings)
```

## Edge Cases & Error Handling

### Edge Case 1: Controllers Using Entity Autowiring
**Scenario**: A controller uses entity autowiring and breaks with `auto_mapping: false`.

**Detection**: Application throws 500 errors when accessing certain routes.

**Handling**:
1. Identify the controller method
2. Refactor to use repository injection or `#[MapEntity]` attribute
3. Example fix:
   ```php
   // BEFORE (breaks):
   public function show(StorageBox $box): Response

   // AFTER (works):
   public function show(int $id, StorageBoxRepository $repo): Response
   {
       $box = $repo->find($id);
       if (!$box) {
           throw $this->createNotFoundException();
       }
   ```

### Edge Case 2: Lazy Loading Behavior Changes
**Scenario**: `enable_native_lazy_objects` changes proxy behavior.

**Detection**: Errors related to proxy classes or lazy loading.

**Handling**:
1. Check Doctrine cache is cleared: `docker compose exec php php bin/console cache:pool:clear doctrine.system_cache_pool`
2. Regenerate proxies: `docker compose exec php php bin/console doctrine:cache:clear-metadata`
3. Verify entity relationships use proper lazy loading annotations

### Edge Case 3: Nested Transactions with Savepoints
**Scenario**: Code explicitly uses savepoints (rare).

**Detection**: Errors related to savepoint operations.

**Handling**:
1. Search codebase for savepoint usage: `grep -r "createSavepoint\|releaseSavepoint\|rollbackSavepoint" src/`
2. DBAL 4 handles savepoints automatically - explicit code likely not needed
3. If found, refactor to use DBAL 4's automatic savepoint handling

## Dependencies

### Blocking Dependencies
None - this is a standalone configuration change.

### Related Tasks
None - this is an isolated configuration task.

### Can Be Done in Parallel With
- Any other tasks (14-21) - no conflicts

### External Dependencies
- Requires access to production server for final verification
- Requires Docker environment to be running for testing

## Acceptance Criteria

- [ ] `use_savepoints: true` removed from `config/packages/doctrine.yaml`
- [ ] `report_fields_where_declared: true` removed from `config/packages/doctrine.yaml`
- [ ] `enable_lazy_ghost_objects: true` replaced with `enable_native_lazy_objects: true`
- [ ] `controller_resolver.auto_mapping: false` added to `config/packages/doctrine.yaml`
- [ ] All controllers audited - none use entity autowiring (or refactored to use `#[MapEntity]`)
- [ ] Cache clear in Docker shows zero deprecation warnings
- [ ] Cache warmup in Docker shows zero deprecation warnings
- [ ] Migration command in Docker shows zero deprecation warnings
- [ ] Application functions normally in dev (Docker) after changes
- [ ] Deploy script runs cleanly on production with zero deprecation warnings
- [ ] Application functions normally on production after deployment

## Verification Steps

### Local Development (Docker)
1. Make configuration changes
2. Run: `docker compose exec php php bin/console cache:clear` → verify no deprecation warnings
3. Run: `docker compose exec php php bin/console cache:warmup` → verify no deprecation warnings
4. Run: `docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction` → verify no deprecation warnings
5. Access application in browser → verify functionality

### Production Server
1. Deploy via deploy script
2. Check deploy logs for deprecation warnings (should be absent)
3. Monitor application logs for any new errors
4. Perform smoke test: login, view inventory, use storage boxes

## Notes & Considerations

### Why These Changes Are Future-Proof

1. **Native Lazy Objects**: PHP 8.4+ supports native lazy objects at the language level. Doctrine 3.x will require this.

2. **Savepoints Auto-Handling**: DBAL 4+ automatically manages savepoints for nested transactions. Manual config is no longer needed.

3. **report_fields_where_declared**: Doctrine ORM 3.x enforces proper field declaration. This will always be `true`.

4. **Controller Resolver**: Modern Symfony uses `#[MapEntity]` attributes for explicit, type-safe entity binding. The old autowiring was "magic" and less maintainable.

### Future Upgrade Path

With these changes:
- **Doctrine ORM 2.x → 3.x**: Smooth upgrade, no breaking changes related to these configs
- **DoctrineBundle 2.x → 3.x**: Smooth upgrade, no removed configs in use
- **PHP 8.4+**: Already compatible with native lazy objects

### Performance Impact

**Negligible**. Changes are configuration-only and align with recommended patterns:
- Native lazy objects may have slight performance improvement (native PHP vs. proxies)
- Disabling controller resolver removes unused feature (no overhead)
- Savepoints auto-handling is more efficient than manual config

### Security Considerations

None. These are internal Doctrine configuration changes with no security implications.

### Related Documentation

- [Doctrine ORM 2.17 Native Lazy Objects](https://github.com/doctrine/orm/pull/12005)
- [DoctrineBundle DBAL 4 Savepoints](https://github.com/doctrine/DoctrineBundle/pull/2055)
- [Symfony MapEntity Attribute](https://symfony.com/doc/current/doctrine.html#automatically-fetching-objects-mapentity)
- [DoctrineBundle Controller Resolver Deprecation](https://github.com/doctrine/DoctrineBundle/pull/1804)

## Risk Assessment

**Risk Level**: Low

**Reasoning**:
- Configuration changes only (no code changes expected)
- Changes align with official recommendations
- Deprecation warnings indicate these changes are required for future compatibility
- Easy rollback (revert config file)

**Rollback Plan**:
If issues arise, revert `config/packages/doctrine.yaml` to previous version and redeploy.
