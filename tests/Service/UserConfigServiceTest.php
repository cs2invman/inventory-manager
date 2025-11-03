<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Entity\UserConfig;
use App\Service\UserConfigService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for UserConfigService.
 */
class UserConfigServiceTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private UserConfigService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->service = new UserConfigService($this->entityManager);
    }

    /**
     * Test that validateSteamId accepts valid Steam IDs.
     *
     * @dataProvider validSteamIdProvider
     */
    public function testValidateSteamIdWithValidIds(string $steamId): void
    {
        $this->assertTrue($this->service->validateSteamId($steamId));
    }

    /**
     * Test that validateSteamId rejects invalid Steam IDs.
     *
     * @dataProvider invalidSteamIdProvider
     */
    public function testValidateSteamIdWithInvalidIds(string $steamId): void
    {
        $this->assertFalse($this->service->validateSteamId($steamId));
    }

    /**
     * Test getUserConfig creates new config if it doesn't exist.
     */
    public function testGetUserConfigCreatesNewConfig(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getConfig')->willReturn(null);

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $config = $this->service->getUserConfig($user);

        $this->assertInstanceOf(UserConfig::class, $config);
    }

    /**
     * Test getUserConfig returns existing config.
     */
    public function testGetUserConfigReturnsExistingConfig(): void
    {
        $existingConfig = $this->createMock(UserConfig::class);
        $user = $this->createMock(User::class);
        $user->method('getConfig')->willReturn($existingConfig);

        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');

        $config = $this->service->getUserConfig($user);

        $this->assertSame($existingConfig, $config);
    }

    /**
     * Test setSteamId with valid Steam ID.
     */
    public function testSetSteamIdWithValidId(): void
    {
        $steamId = '76561198012345678';
        $config = $this->createMock(UserConfig::class);
        $user = $this->createMock(User::class);

        $user->method('getConfig')->willReturn($config);
        $config->expects($this->once())->method('setSteamId')->with($steamId);
        $this->entityManager->expects($this->once())->method('flush');

        $this->service->setSteamId($user, $steamId);
    }

    /**
     * Test setSteamId with invalid Steam ID throws exception.
     */
    public function testSetSteamIdWithInvalidIdThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Steam ID format');

        $user = $this->createMock(User::class);
        $this->service->setSteamId($user, 'invalid_steam_id');
    }

    /**
     * Test setSteamId with null clears Steam ID.
     */
    public function testSetSteamIdWithNullClearsId(): void
    {
        $config = $this->createMock(UserConfig::class);
        $user = $this->createMock(User::class);

        $user->method('getConfig')->willReturn($config);
        $config->expects($this->once())->method('setSteamId')->with(null);
        $this->entityManager->expects($this->once())->method('flush');

        $this->service->setSteamId($user, null);
    }

    /**
     * Test hasSteamId returns true when Steam ID is set.
     */
    public function testHasSteamIdReturnsTrueWhenSet(): void
    {
        $config = $this->createMock(UserConfig::class);
        $config->method('getSteamId')->willReturn('76561198012345678');
        $user = $this->createMock(User::class);
        $user->method('getConfig')->willReturn($config);

        $this->assertTrue($this->service->hasSteamId($user));
    }

    /**
     * Test hasSteamId returns false when Steam ID is not set.
     */
    public function testHasSteamIdReturnsFalseWhenNotSet(): void
    {
        $config = $this->createMock(UserConfig::class);
        $config->method('getSteamId')->willReturn(null);
        $user = $this->createMock(User::class);
        $user->method('getConfig')->willReturn($config);

        $this->assertFalse($this->service->hasSteamId($user));
    }

    /**
     * Test hasSteamId returns false when Steam ID is empty string.
     */
    public function testHasSteamIdReturnsFalseWhenEmpty(): void
    {
        $config = $this->createMock(UserConfig::class);
        $config->method('getSteamId')->willReturn('');
        $user = $this->createMock(User::class);
        $user->method('getConfig')->willReturn($config);

        $this->assertFalse($this->service->hasSteamId($user));
    }

    /**
     * Test getInventoryUrls with configured Steam ID.
     */
    public function testGetInventoryUrlsWithSteamId(): void
    {
        $steamId = '76561198012345678';
        $config = $this->createMock(UserConfig::class);
        $config->method('getSteamId')->willReturn($steamId);
        $user = $this->createMock(User::class);
        $user->method('getConfig')->willReturn($config);

        $urls = $this->service->getInventoryUrls($user);

        $this->assertIsArray($urls);
        $this->assertArrayHasKey('tradeable', $urls);
        $this->assertArrayHasKey('tradeLocked', $urls);
        $this->assertEquals("https://steamcommunity.com/inventory/{$steamId}/730/2", $urls['tradeable']);
        $this->assertEquals("https://steamcommunity.com/inventory/{$steamId}/730/16", $urls['tradeLocked']);
    }

    /**
     * Test getInventoryUrls without Steam ID returns nulls.
     */
    public function testGetInventoryUrlsWithoutSteamId(): void
    {
        $config = $this->createMock(UserConfig::class);
        $config->method('getSteamId')->willReturn(null);
        $user = $this->createMock(User::class);
        $user->method('getConfig')->willReturn($config);

        $urls = $this->service->getInventoryUrls($user);

        $this->assertIsArray($urls);
        $this->assertNull($urls['tradeable']);
        $this->assertNull($urls['tradeLocked']);
    }

    /**
     * Provide valid Steam IDs for testing.
     */
    public static function validSteamIdProvider(): array
    {
        return [
            'standard SteamID64' => ['76561198012345678'],
            'another valid ID' => ['76561199999999999'],
            'minimum valid ID' => ['76561198000000000'],
        ];
    }

    /**
     * Provide invalid Steam IDs for testing.
     */
    public static function invalidSteamIdProvider(): array
    {
        return [
            'too short' => ['7656119801234567'],
            'too long' => ['765611980123456789'],
            'contains letters' => ['7656119801234567a'],
            'wrong prefix' => ['86561198012345678'],
            'empty string' => [''],
            'special characters' => ['7656119801234567!'],
            'spaces' => ['76561198 12345678'],
        ];
    }
}
