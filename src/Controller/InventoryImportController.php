<?php

namespace App\Controller;

use App\Service\InventoryImportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/inventory/import')]
#[IsGranted('ROLE_USER')]
class InventoryImportController extends AbstractController
{
    public function __construct(
        private InventoryImportService $importService
    ) {
    }

    #[Route('', name: 'inventory_import_form', methods: ['GET'])]
    public function importForm(): Response
    {
        return $this->render('inventory/import.html.twig');
    }

    #[Route('/preview', name: 'inventory_import_preview', methods: ['POST'])]
    public function preview(Request $request): Response
    {
        $tradeableJson = $request->request->get('tradeable_json', '');
        $tradeLockedJson = $request->request->get('trade_locked_json', '');

        // Validate that at least one JSON is provided
        if (empty(trim($tradeableJson)) && empty(trim($tradeLockedJson))) {
            $this->addFlash('error', 'Please provide at least one inventory JSON.');
            return $this->redirectToRoute('inventory_import_form');
        }

        // If empty, use empty JSON structure
        if (empty(trim($tradeableJson))) {
            $tradeableJson = '{"assets":[],"descriptions":[],"asset_properties":[],"success":1}';
        }
        if (empty(trim($tradeLockedJson))) {
            $tradeLockedJson = '{"assets":[],"descriptions":[],"asset_properties":[],"success":1}';
        }

        try {
            $user = $this->getUser();
            $preview = $this->importService->prepareImportPreview($user, $tradeableJson, $tradeLockedJson);

            // Check if there are critical errors
            if ($preview->hasErrors()) {
                foreach ($preview->errors as $error) {
                    $this->addFlash('error', $error);
                }
                return $this->redirectToRoute('inventory_import_form');
            }

            return $this->render('inventory/import_preview.html.twig', [
                'preview' => $preview,
            ]);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to parse inventory data: ' . $e->getMessage());
            return $this->redirectToRoute('inventory_import_form');
        }
    }

    #[Route('/confirm', name: 'inventory_import_confirm', methods: ['POST'])]
    public function confirm(Request $request): Response
    {
        $sessionKey = $request->request->get('session_key');

        if (empty($sessionKey)) {
            $this->addFlash('error', 'Invalid session data. Please try importing again.');
            return $this->redirectToRoute('inventory_import_form');
        }

        try {
            $user = $this->getUser();
            $result = $this->importService->executeImport($user, $sessionKey);

            if ($result->isSuccess()) {
                $this->addFlash('success', sprintf(
                    'Successfully imported %d items into your inventory!',
                    $result->successCount
                ));

                if ($result->hasSkippedItems()) {
                    $this->addFlash('warning', sprintf(
                        '%d items were skipped due to errors.',
                        count($result->skippedItems)
                    ));
                }
            } else {
                $this->addFlash('error', 'Import failed. Please try again.');
                foreach ($result->errors as $error) {
                    $this->addFlash('error', $error);
                }
            }

            return $this->redirectToRoute('app_dashboard');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Import failed: ' . $e->getMessage());
            return $this->redirectToRoute('inventory_import_form');
        }
    }

    #[Route('/cancel', name: 'inventory_import_cancel', methods: ['POST'])]
    public function cancel(): Response
    {
        $this->addFlash('info', 'Import cancelled.');
        return $this->redirectToRoute('inventory_import_form');
    }
}