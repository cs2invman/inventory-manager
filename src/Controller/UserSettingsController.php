<?php

namespace App\Controller;

use App\Form\CurrencyPreferencesType;
use App\Form\SteamIdType;
use App\Service\UserConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller for user settings and configuration.
 */
#[Route('/settings')]
#[IsGranted('ROLE_USER')]
class UserSettingsController extends AbstractController
{
    public function __construct(
        private readonly UserConfigService $userConfigService
    ) {
    }

    /**
     * Display user settings page.
     *
     * Accepts an optional 'redirect' query parameter to redirect back
     * to a specific route after successful save.
     */
    #[Route('', name: 'app_settings_index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        $config = $this->userConfigService->getUserConfig($user);

        // Steam ID Form (existing)
        $steamIdForm = $this->createForm(SteamIdType::class, [
            'steamId' => $config->getSteamId(),
        ]);

        // Currency Preferences Form (new)
        $currencyForm = $this->createForm(CurrencyPreferencesType::class, [
            'preferredCurrency' => $config->getPreferredCurrency() ?? 'USD',
            'cadExchangeRate' => $config->getCadExchangeRate() ?? 1.38,
        ]);

        // Handle Steam ID form
        $steamIdForm->handleRequest($request);
        if ($steamIdForm->isSubmitted() && $steamIdForm->isValid()) {
            $data = $steamIdForm->getData();
            try {
                $this->userConfigService->setSteamId($user, $data['steamId']);
                $this->addFlash('success', 'Steam ID saved successfully!');
                return $this->redirectToRoute('app_settings_index');
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        // Handle Currency form
        $currencyForm->handleRequest($request);
        if ($currencyForm->isSubmitted() && $currencyForm->isValid()) {
            $data = $currencyForm->getData();
            try {
                $this->userConfigService->setCurrencyPreferences(
                    $user,
                    $data['preferredCurrency'],
                    $data['cadExchangeRate']
                );
                $this->addFlash('success', 'Currency preferences saved successfully!');
                return $this->redirectToRoute('app_settings_index');
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('settings/index.html.twig', [
            'steamIdForm' => $steamIdForm->createView(),
            'currencyForm' => $currencyForm->createView(),
            'currentSteamId' => $config->getSteamId(),
            'currentCurrency' => $config->getPreferredCurrency() ?? 'USD',
            'currentExchangeRate' => $config->getCadExchangeRate() ?? 1.38,
        ]);
    }
}
