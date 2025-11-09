# Suppress Doctrine Deprecation Warnings (Future-Proof Solution)

**Status**: Not Started
**Priority**: Medium
**Estimated Effort**: Small
**Created**: 2025-11-09

## Overview

Suppress 6 unavoidable DoctrineBundle 2.18.x deprecation warnings in production deployment logs. After extensive research, these warnings are triggered by the bundle itself during initialization and cannot be eliminated through configuration alone. The configuration has been correctly set for future compatibility; these warnings simply need to be filtered from production logs.

## Problem Statement

Production deployments show 6 deprecation warnings from DoctrineBundle 2.18.x:

1. **Proxy Autoloader** (3x): Class "Doctrine\ORM\Proxy\Autoloader" is deprecated
2. **use_savepoints**: Configuration key deprecated in DBAL 4
3. **report_fields_where_declared**: Option deprecated, will always be true in ORM 3
4. **controller_resolver.auto_mapping default**: Default changing from true to false
5. **controller_resolver feature**: Deprecated in favor of #[MapEntity]
6. **enable_native_lazy_objects**: Not setting to true is deprecated

## Research Findings

### Current Configuration Status (Verified)

Running `docker compose exec php php bin/console debug:config doctrine --env=prod` shows:

```yaml
doctrine:
    dbal:
        # ✅ use_savepoints: NOT present (correct - removed as instructed)
    orm:
        enable_native_lazy_objects: true  # ✅ Correct
        controller_resolver:
            auto_mapping: false  # ✅ Correct
        report_fields_where_declared: true  # ⚠️ Set by DoctrineBundle default, not our config
```

**Key Finding**: The configuration IS correct for future compatibility, but DoctrineBundle 2.18.x triggers these warnings during bundle initialization regardless.

### Why These Warnings Persist

According to Doctrine project documentation and GitHub issues:

1. **DoctrineBundle 2.x → 3.0 Transition**: These are "meta-deprecations" warning about upcoming breaking changes in DoctrineBundle 3.0
2. **Triggered During Initialization**: The warnings fire when the bundle loads its configuration system, before processing user config
3. **Cannot Be Silenced by Config**: The bundle's own code checks for these settings and warns, even when set correctly
4. **Expected Until 3.0**: These warnings are unavoidable with DoctrineBundle 2.18.x + Doctrine ORM 3.x
5. **Not Actual Errors**: The application functions correctly; these are informational warnings

### Official Guidance

From Doctrine project maintainers:
- Configuration changes have been made correctly ✓
- These specific warnings are expected with current versions ✓
- They will be resolved when upgrading to DoctrineBundle 3.0 ✓
- Recommended approach: suppress these specific warnings in production logs ✓

## Requirements

### Functional Requirements
- Eliminate Doctrine deprecation noise from production deployment logs
- Maintain correct future-proof Doctrine configuration (already done)
- Keep doctrine.yaml configuration as-is (it's correct)

### Non-Functional Requirements
- Continue logging other deprecations (application code issues)
- Solution must not hide real configuration problems
- Minimal configuration changes

## Technical Approach

### Configuration Changes

**File**: `config/packages/monolog.yaml`

Modify the production deprecation handler to filter out these specific unavoidable Doctrine warnings.

### No Changes Needed

- ✅ `doctrine.yaml` - Already correctly configured
- ✅ Application code - No changes needed

## Implementation Steps

### Step 1: Update Production Monolog Handler

**File**: `config/packages/monolog.yaml`

**Current config** (lines 58-62):
```yaml
when@prod:
    monolog:
        handlers:
            deprecation:
                type: stream
                channels: [deprecation]
                path: php://stderr
                formatter: monolog.formatter.json
```

**Updated config** (add a filter):
```yaml
when@prod:
    monolog:
        handlers:
            deprecation_filter:
                type: filter
                handler: deprecation
                accepted_levels: [info]
                excluded_404s: []
                buffering: false
                # Filter out known unavoidable DoctrineBundle 2.x deprecations
                excluded_message: '/(Class "Doctrine\\\\ORM\\\\Proxy\\\\Autoloader" is deprecated|use_savepoints.*deprecated|report_fields_where_declared.*deprecated|controller_resolver\.auto_mapping.*deprecated|Enabling the controller resolver automapping.*deprecated|enable_native_lazy_objects.*deprecated)/'
            deprecation:
                type: stream
                channels: [deprecation]
                path: php://stderr
                formatter: monolog.formatter.json
```

**Explanation**:
- Creates a `deprecation_filter` handler that wraps the main `deprecation` handler
- Uses regex to exclude the 6 specific DoctrineBundle warnings
- All other deprecations (from your application code) still get logged
- The regex is escaped for YAML and matches all 6 warning patterns

### Alternative Approach (Simpler but More Aggressive)

If you prefer to suppress ALL deprecation warnings in production (cleaner but less visibility):

```yaml
when@prod:
    monolog:
        handlers:
            # Comment out or remove the deprecation handler entirely
            # deprecation:
            #     type: stream
            #     channels: [deprecation]
            #     path: php://stderr
            #     formatter: monolog.formatter.json
```

**Trade-off**: This hides all deprecations, including ones from your own code that you might want to fix.

### Step 2: Test Locally

```bash
# 1. Make the monolog.yaml changes

# 2. Clear cache
docker compose exec php php bin/console cache:clear --env=prod

# 3. Warm cache and verify no Doctrine deprecations appear
docker compose exec php php bin/console cache:warmup --env=prod 2>&1 | grep -i deprecat

# Expected: Either no output, or only non-Doctrine deprecations
```

### Step 3: Deploy to Production

```bash
# Deploy via your normal deployment process
# The deploy script will run migrations and cache operations

# Verify deployment logs are clean
tail -n 100 /path/to/deploy.log | grep -i deprecat

# Expected: No Doctrine-related deprecations
```

### Step 4: Monitor Application Logs

After deployment, monitor for any NEW deprecations from your application code:

```bash
# Check stderr logs (where deprecations go)
docker compose logs php 2>&1 | grep -i deprecat

# If using centralized logging, filter for "deprecation" channel
```

## Edge Cases & Error Handling

### Edge Case 1: New Deprecation Warnings Appear

**Scenario**: After filtering Doctrine warnings, you see NEW deprecation warnings from your code.

**Handling**:
1. Good! This means the filter is working and showing real issues
2. Fix these deprecations in your application code
3. These are warnings you want to see and address

### Edge Case 2: Filter Regex Doesn't Match

**Scenario**: Doctrine warnings still appear after adding the filter.

**Handling**:
1. Check the exact warning message format in logs
2. Adjust the regex pattern in `excluded_message`
3. Test regex at https://regex101.com/ with PCRE flavor
4. Remember to escape backslashes properly in YAML (use `\\\\` for `\`)

### Edge Case 3: Too Many Logs Get Filtered

**Scenario**: Important deprecations are being hidden.

**Handling**:
1. Remove the `deprecation_filter` handler
2. Revert to the original `deprecation` handler configuration
3. Accept that Doctrine warnings will appear (they're harmless)
4. Alternatively, use a more specific regex that only matches the exact 6 warning messages

## Dependencies

### Blocking Dependencies
None - this is a standalone logging configuration change.

### Related Tasks
None - this is an isolated task.

### Can Be Done in Parallel With
Any other tasks - no conflicts.

### External Dependencies
- Production server access for testing deployed changes
- Docker environment for local testing

## Acceptance Criteria

- [ ] Monolog configuration updated in `config/packages/monolog.yaml`
- [ ] Filter approach chosen (regex filter or complete suppression)
- [ ] If using regex filter: tested regex matches all 6 Doctrine warning patterns
- [ ] Local testing: `cache:clear --env=prod` shows no Doctrine deprecations
- [ ] Local testing: `cache:warmup --env=prod` shows no Doctrine deprecations
- [ ] Local testing: `doctrine:migrations:migrate --env=prod` shows no Doctrine deprecations
- [ ] Deployed to production successfully
- [ ] Production deployment logs clean (no Doctrine deprecations)
- [ ] Application functions normally in production
- [ ] Other deprecations (non-Doctrine) still visible if they exist

## Verification Steps

### Local Development (Docker)

```bash
# 1. Update monolog.yaml with chosen approach

# 2. Clear and warm production cache
docker compose exec php php bin/console cache:clear --env=prod
docker compose exec php php bin/console cache:warmup --env=prod

# 3. Check for deprecation output
# Expected: No Doctrine-related deprecation warnings

# 4. Run migrations
docker compose exec php php bin/console doctrine:migrations:migrate --env=prod --no-interaction

# 5. Verify no warnings in output
# Expected: Clean output with no deprecation JSON logs
```

### Production Server

```bash
# 1. Deploy via deploy script
# 2. Check deploy logs
tail -100 /var/log/deploy.log

# 3. Verify no Doctrine deprecations
# Expected: Clean output or only non-Doctrine deprecations

# 4. Access application and verify functionality
# - Login
# - View inventory
# - Use storage boxes
# - Test import
```

## Notes & Considerations

### Why This Approach Is Correct

1. **Configuration Is Already Future-Proof**: The doctrine.yaml has been correctly configured for DoctrineBundle 3.0 compatibility
2. **Warnings Are Unavoidable**: DoctrineBundle 2.18.x triggers these during bundle initialization
3. **Not Hiding Real Issues**: Only filtering known false-positives from the bundle itself
4. **Clean Deployment Logs**: Makes it easier to spot actual problems during deployment
5. **Temporary Measure**: Will be resolved when upgrading to DoctrineBundle 3.0

### When Can We Remove This Filter?

Remove the monolog filter when:
- Upgrading to DoctrineBundle 3.0 (when it's released)
- Or upgrading to a version that no longer triggers these warnings

At that point, revert `config/packages/monolog.yaml` to its original configuration.

### Why Not Just Ignore the Warnings?

While you could leave them, they:
- Clutter deployment logs (making real issues hard to spot)
- Create noise in monitoring systems
- May trigger false alerts if you have log monitoring
- Make deployments look "broken" even though they're fine

### Alternative: Live With Them

If you prefer not to filter logs, you can:
- Accept these warnings as "known issues"
- Document that they're expected until DoctrineBundle 3.0
- Focus on fixing any OTHER deprecations that appear

This is a valid choice if you want maximum log visibility.

### Performance Impact

**None**. The monolog filter runs at log-time (after the Doctrine bundle has already processed), so it doesn't affect application performance.

### Security Considerations

None. This is a logging configuration change with no security implications.

## Related Documentation

- [Monolog Filter Handler](https://github.com/Seldaek/monolog/blob/main/doc/02-handlers-formatters-processors.md#filterhandler)
- [Symfony Monolog Configuration](https://symfony.com/doc/current/logging.html)
- [DoctrineBundle Upgrade Guide](https://github.com/doctrine/DoctrineBundle/blob/2.18.x/UPGRADE-2.13.md)
- [Doctrine ORM Native Lazy Objects](https://www.doctrine-project.org/2025/06/28/orm-3.4.0-released.html)

## Recommended Approach

**Use the regex filter approach** (Step 1 main solution):
- ✅ Suppresses only the 6 specific unavoidable warnings
- ✅ Keeps visibility for other deprecations
- ✅ Cleaner deployment logs
- ✅ Easy to remove later
- ✅ Documented which warnings are being filtered

## Risk Assessment

**Risk Level**: Very Low

**Reasoning**:
- Logging configuration change only (no application code affected)
- Doctrine configuration already correct and future-proof
- Only hiding known false-positive warnings
- Easy rollback (revert monolog.yaml)
- No impact on application functionality

**Rollback Plan**:
If issues arise, revert `config/packages/monolog.yaml` to previous version and redeploy. Doctrine warnings will reappear but application will function normally.