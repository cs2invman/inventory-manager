<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\UserConfig;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Integration tests for InventoryImportController Steam ID requirements.
 */
class InventoryImportControllerTest extends WebTestCase
{
    private $client;
    private $entityManager;
    private $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = $this->client->getContainer()
            ->get('doctrine')
            ->getManager();

        // Create a test user
        $this->user = new User();
        $this->user->setEmail('test-import@example.com');
        $this->user->setPassword('$2y$13$hashedpassword');
        $this->user->setFirstName('Test');
        $this->user->setLastName('Import');
        $this->user->setIsActive(true);

        $this->entityManager->persist($this->user);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        // Clean up test data
        if ($this->user && $this->entityManager->contains($this->user)) {
            $this->entityManager->remove($this->user);
            $this->entityManager->flush();
        }

        parent::tearDown();
    }

    /**
     * Test that import page redirects to settings when Steam ID is not configured.
     */
    public function testImportPageRedirectsToSettingsWhenSteamIdNotConfigured(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/inventory/import');

        $this->assertResponseRedirects('/settings?redirect=inventory_import_form', 302);
        $this->client->followRedirect();

        $this->assertSelectorTextContains('.alert-warning', 'Please configure your Steam ID');
    }

    /**
     * Test that import page displays correctly when Steam ID is configured.
     */
    public function testImportPageDisplaysWhenSteamIdConfigured(): void
    {
        // Configure Steam ID for user
        $config = new UserConfig();
        $config->setUser($this->user);
        $config->setSteamId('76561198012345678');
        $this->user->setConfig($config);

        $this->entityManager->persist($config);
        $this->entityManager->flush();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/inventory/import');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'Import CS2 Inventory');
    }

    /**
     * Test that import page displays user's Steam ID.
     */
    public function testImportPageDisplaysUserSteamId(): void
    {
        $steamId = '76561198012345678';

        // Configure Steam ID for user
        $config = new UserConfig();
        $config->setUser($this->user);
        $config->setSteamId($steamId);
        $this->user->setConfig($config);

        $this->entityManager->persist($config);
        $this->entityManager->flush();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/inventory/import');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.font-mono', $steamId);
    }

    /**
     * Test that import page displays clickable Steam inventory URLs.
     */
    public function testImportPageDisplaysClickableSteamUrls(): void
    {
        $steamId = '76561198012345678';

        // Configure Steam ID for user
        $config = new UserConfig();
        $config->setUser($this->user);
        $config->setSteamId($steamId);
        $this->user->setConfig($config);

        $this->entityManager->persist($config);
        $this->entityManager->flush();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/inventory/import');

        $this->assertResponseIsSuccessful();

        // Check for tradeable items URL
        $tradeableUrl = "https://steamcommunity.com/inventory/{$steamId}/730/2";
        $this->assertSelectorExists("a[href=\"{$tradeableUrl}\"]");

        // Check for trade-locked items URL
        $tradeLockedUrl = "https://steamcommunity.com/inventory/{$steamId}/730/16";
        $this->assertSelectorExists("a[href=\"{$tradeLockedUrl}\"]");
    }

    /**
     * Test that import page has link to change Steam ID.
     */
    public function testImportPageHasChangeSteamIdLink(): void
    {
        // Configure Steam ID for user
        $config = new UserConfig();
        $config->setUser($this->user);
        $config->setSteamId('76561198012345678');
        $this->user->setConfig($config);

        $this->entityManager->persist($config);
        $this->entityManager->flush();

        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/inventory/import');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('a[href*="/settings"]');
        $this->assertSelectorTextContains('a', 'Change Steam ID');
    }

    /**
     * Test redirect flow: settings -> import after configuring Steam ID.
     */
    public function testRedirectFlowFromSettingsToImport(): void
    {
        $this->client->loginUser($this->user);

        // Try to access import (should redirect to settings)
        $this->client->request('GET', '/inventory/import');
        $this->assertResponseRedirects('/settings?redirect=inventory_import_form', 302);

        // Follow to settings
        $crawler = $this->client->followRedirect();
        $this->assertSelectorTextContains('h2', 'User Settings');

        // Configure Steam ID
        $form = $crawler->selectButton('Save Settings')->form([
            'steam_id_type[steamId]' => '76561198012345678',
        ]);
        $this->client->submit($form);

        // Should redirect back to import
        $this->assertResponseRedirects('/inventory/import', 302);

        // Follow to import page
        $this->client->followRedirect();
        $this->assertSelectorTextContains('h2', 'Import CS2 Inventory');
    }
}
