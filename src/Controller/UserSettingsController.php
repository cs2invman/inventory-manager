<?php

namespace App\Controller;

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

        // Get current Steam ID
        $currentSteamId = $config->getSteamId();

        // Create form
        $form = $this->createForm(SteamIdType::class, [
            'steamId' => $currentSteamId,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $steamId = $data['steamId'];

            try {
                $this->userConfigService->setSteamId($user, $steamId);

                $this->addFlash('success', 'Settings saved successfully!');

                // Check if there's a redirect parameter
                $redirectRoute = $request->query->get('redirect');
                if ($redirectRoute) {
                    // Redirect back to the specified route
                    return $this->redirectToRoute($redirectRoute);
                }

                // Default: redirect to dashboard
                return $this->redirectToRoute('app_dashboard');

            } catch (\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('settings/index.html.twig', [
            'form' => $form->createView(),
            'currentSteamId' => $currentSteamId,
        ]);
    }
}
