<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\UserConfig;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service for managing user configuration settings.
 *
 * Handles CRUD operations for UserConfig entity and provides
 * helper methods for Steam ID validation and URL generation.
 */
class UserConfigService
{
    private const STEAM_ID_LENGTH = 17;
    private const STEAM_ID_PREFIX = '7656';
    private const STEAM_INVENTORY_BASE_URL = 'https://steamcommunity.com/inventory/';
    private const CS2_APP_ID = '730';

    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Get user configuration. Creates a new one if it doesn't exist.
     *
     * @param User $user The user entity
     * @return UserConfig The user's configuration
     */
    public function getUserConfig(User $user): UserConfig
    {
        $config = $user->getConfig();

        if ($config === null) {
            $config = new UserConfig();
            $config->setUser($user);
            $user->setConfig($config);
            $this->entityManager->persist($config);
            $this->entityManager->flush();
        }

        return $config;
    }

    /**
     * Set the user's Steam ID with validation.
     *
     * @param User $user The user entity
     * @param string|null $steamId The Steam ID to set (nullable to allow clearing)
     * @return void
     * @throws \InvalidArgumentException If Steam ID format is invalid
     */
    public function setSteamId(User $user, ?string $steamId): void
    {
        if ($steamId !== null && !$this->validateSteamId($steamId)) {
            throw new \InvalidArgumentException(
                'Invalid Steam ID format. Must be 17 digits, numeric only, and start with "7656".'
            );
        }

        $config = $this->getUserConfig($user);
        $config->setSteamId($steamId);
        $this->entityManager->flush();
    }

    /**
     * Get the user's Steam ID.
     *
     * @param User $user The user entity
     * @return string|null The Steam ID or null if not set
     */
    public function getSteamId(User $user): ?string
    {
        $config = $this->getUserConfig($user);
        return $config->getSteamId();
    }

    /**
     * Validate Steam ID format (SteamID64).
     *
     * Rules:
     * - Must be exactly 17 digits
     * - Must be numeric only (no letters, spaces, special characters)
     * - Should start with "7656" (standard SteamID64 prefix)
     *
     * @param string $steamId The Steam ID to validate
     * @return bool True if valid, false otherwise
     */
    public function validateSteamId(string $steamId): bool
    {
        // Check if exactly 17 digits
        if (strlen($steamId) !== self::STEAM_ID_LENGTH) {
            return false;
        }

        // Check if numeric only
        if (!ctype_digit($steamId)) {
            return false;
        }

        // Check if starts with "7656"
        if (!str_starts_with($steamId, self::STEAM_ID_PREFIX)) {
            return false;
        }

        return true;
    }

    /**
     * Check if user has configured their Steam ID.
     *
     * @param User $user The user entity
     * @return bool True if Steam ID is configured, false otherwise
     */
    public function hasSteamId(User $user): bool
    {
        $steamId = $this->getSteamId($user);
        return $steamId !== null && $steamId !== '';
    }

    /**
     * Generate Steam inventory URLs for CS2 items.
     *
     * Returns an associative array with URLs for:
     * - tradeable: Items that can be traded
     * - tradeLocked: Items that are trade-locked
     *
     * @param User $user The user entity
     * @return array{tradeable: string|null, tradeLocked: string|null} Array of URLs or nulls if Steam ID not set
     */
    public function getInventoryUrls(User $user): array
    {
        $steamId = $this->getSteamId($user);

        if ($steamId === null) {
            return [
                'tradeable' => null,
                'tradeLocked' => null,
            ];
        }

        $baseUrl = self::STEAM_INVENTORY_BASE_URL . $steamId . '/' . self::CS2_APP_ID;

        return [
            'tradeable' => $baseUrl . '/2?l=english&count=999',    // Context ID 2 = tradeable items
            'tradeLocked' => $baseUrl . '/16?l=english&count=999', // Context ID 16 = trade-locked items
        ];
    }
}
