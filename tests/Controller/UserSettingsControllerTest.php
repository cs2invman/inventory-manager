<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Integration tests for UserSettingsController.
 */
class UserSettingsControllerTest extends WebTestCase
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
        $this->user->setEmail('test@example.com');
        $this->user->setPassword('$2y$13$hashedpassword'); // Hashed password
        $this->user->setFirstName('Test');
        $this->user->setLastName('User');
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
     * Test that unauthenticated users are redirected to login.
     */
    public function testSettingsPageRequiresAuthentication(): void
    {
        $this->client->request('GET', '/settings');

        $this->assertResponseRedirects('/login', 302);
    }

    /**
     * Test that authenticated users can access settings page.
     */
    public function testAuthenticatedUserCanAccessSettings(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/settings');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'User Settings');
        $this->assertSelectorExists('form');
        $this->assertSelectorExists('input[name="steam_id_type[steamId]"]');
    }

    /**
     * Test submitting valid Steam ID.
     */
    public function testSubmitValidSteamId(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/settings');

        $form = $crawler->selectButton('Save Settings')->form([
            'steam_id_type[steamId]' => '76561198012345678',
        ]);

        $this->client->submit($form);

        $this->assertResponseRedirects('/dashboard', 302);
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-success', 'Settings saved successfully');
    }

    /**
     * Test submitting invalid Steam ID shows error.
     */
    public function testSubmitInvalidSteamIdShowsError(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/settings');

        $form = $crawler->selectButton('Save Settings')->form([
            'steam_id_type[steamId]' => 'invalid_steam_id',
        ]);

        $this->client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.text-red-400');
    }

    /**
     * Test redirect parameter redirects to specified route after save.
     */
    public function testRedirectParameterRedirectsToSpecifiedRoute(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/settings?redirect=inventory_import_form');

        $form = $crawler->selectButton('Save Settings')->form([
            'steam_id_type[steamId]' => '76561198012345678',
        ]);

        $this->client->submit($form);

        $this->assertResponseRedirects('/inventory/import', 302);
    }

    /**
     * Test that current Steam ID is displayed when already configured.
     */
    public function testCurrentSteamIdDisplayedWhenConfigured(): void
    {
        // Set up user with Steam ID
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/settings');
        $form = $crawler->selectButton('Save Settings')->form([
            'steam_id_type[steamId]' => '76561198012345678',
        ]);
        $this->client->submit($form);

        // Reload settings page
        $crawler = $this->client->request('GET', '/settings');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.text-green-300', 'Steam ID configured');
        $this->assertSelectorTextContains('.font-mono', '76561198012345678');
    }

    /**
     * Test clearing Steam ID (submitting empty value).
     */
    public function testClearingSteamId(): void
    {
        // First, set a Steam ID
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/settings');
        $form = $crawler->selectButton('Save Settings')->form([
            'steam_id_type[steamId]' => '76561198012345678',
        ]);
        $this->client->submit($form);

        // Now clear it
        $crawler = $this->client->request('GET', '/settings');
        $form = $crawler->selectButton('Save Settings')->form([
            'steam_id_type[steamId]' => '',
        ]);
        $this->client->submit($form);

        $this->assertResponseRedirects('/dashboard', 302);
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-success', 'Settings saved successfully');
    }
}
