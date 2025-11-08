<?php

namespace App\Controller;

use App\Repository\ItemUserRepository;
use App\Repository\StorageBoxRepository;
use App\Service\InventoryImportService;
use App\Service\UserConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
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
        private readonly InventoryImportService $importService,
        private readonly UserConfigService $userConfigService,
        private readonly ItemUserRepository $itemUserRepository,
        private readonly StorageBoxRepository $storageBoxRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger
    ) {
    }

    #[Route('', name: 'inventory_import_form', methods: ['GET'])]
    public function importForm(): Response
    {
        $user = $this->getUser();

        // Check if user has configured their Steam ID
        if (!$this->userConfigService->hasSteamId($user)) {
            $this->addFlash('warning', 'Please configure your Steam ID before importing your inventory.');
            return $this->redirectToRoute('app_settings_index', [
                'redirect' => 'inventory_import_form'
            ]);
        }

        // Get Steam inventory URLs
        $inventoryUrls = $this->userConfigService->getInventoryUrls($user);
        $steamId = $this->userConfigService->getSteamId($user);

        return $this->render('inventory/import.html.twig', [
            'inventoryUrls' => $inventoryUrls,
            'steamId' => $steamId,
        ]);
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
                'userConfig' => $user->getConfig(),
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
        $selectedItems = $request->request->all('selected_items');

        if (empty($sessionKey)) {
            $this->addFlash('error', 'Invalid session data. Please try importing again.');
            return $this->redirectToRoute('inventory_import_form');
        }

        // Validate that at least some items are selected
        if (empty($selectedItems)) {
            $this->addFlash('error', 'No items selected for import.');
            return $this->redirectToRoute('inventory_import_form');
        }

        // Separate add vs remove IDs based on prefix
        $selectedAddIds = [];
        $selectedRemoveIds = [];

        foreach ($selectedItems as $itemId) {
            if (str_starts_with($itemId, 'add-')) {
                $selectedAddIds[] = $itemId;
            } elseif (str_starts_with($itemId, 'remove-')) {
                $selectedRemoveIds[] = $itemId;
            }
        }

        try {
            $user = $this->getUser();
            $result = $this->importService->executeImport(
                $user,
                $sessionKey,
                $selectedAddIds,
                $selectedRemoveIds
            );

            if ($result->isSuccess()) {
                $this->addFlash('success', sprintf(
                    'Import complete! Added %d items, removed %d items.',
                    $result->addedCount,
                    $result->removedCount
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

            return $this->redirectToRoute('inventory_index');
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

    #[Route('/delete-all', name: 'inventory_import_delete_all', methods: ['POST'])]
    public function deleteAll(): Response
    {
        $user = $this->getUser();

        try {
            // Count items and boxes before deletion for logging and flash message
            $items = $this->itemUserRepository->findBy(['user' => $user]);
            $storageBoxes = $this->storageBoxRepository->findBy(['user' => $user]);
            $itemCount = count($items);
            $boxCount = count($storageBoxes);

            // Start transaction for atomic deletion
            $this->entityManager->beginTransaction();

            try {
                // Delete all ItemUser records for the current user
                foreach ($items as $item) {
                    $this->entityManager->remove($item);
                }

                // Delete all StorageBox records for the current user
                foreach ($storageBoxes as $box) {
                    $this->entityManager->remove($box);
                }

                // Flush all changes
                $this->entityManager->flush();

                // Commit transaction
                $this->entityManager->commit();

                // Log the deletion event
                $this->logger->warning('User deleted all inventory data', [
                    'user_id' => $user->getId(),
                    'username' => $user->getUserIdentifier(),
                    'item_count' => $itemCount,
                    'storage_box_count' => $boxCount,
                    'timestamp' => new \DateTimeImmutable(),
                ]);

                // Add flash message with counts
                $this->addFlash('success', sprintf(
                    'Deleted %d items and %d storage boxes.',
                    $itemCount,
                    $boxCount
                ));

                return $this->redirectToRoute('inventory_index');
            } catch (\Exception $e) {
                // Rollback transaction on error
                $this->entityManager->rollback();
                throw $e;
            }
        } catch (\Exception $e) {
            // Log the error
            $this->logger->error('Failed to delete inventory data', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->addFlash('error', 'Failed to delete inventory data. Please try again.');
            return $this->redirectToRoute('inventory_import_form');
        }
    }
}