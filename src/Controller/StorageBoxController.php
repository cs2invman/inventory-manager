<?php

namespace App\Controller;

use App\Entity\StorageBox;
use App\Service\StorageBoxService;
use App\Service\StorageBoxTransactionService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/storage')]
#[IsGranted('ROLE_USER')]
class StorageBoxController extends AbstractController
{
    public function __construct(
        private StorageBoxTransactionService $transactionService,
        private StorageBoxService $storageBoxService
    ) {}

    #[Route('/deposit/{id}', name: 'storage_box_deposit_form')]
    public function depositForm(#[MapEntity] StorageBox $storageBox): Response
    {
        // Security check
        if ($storageBox->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('storage_box/deposit.html.twig', [
            'storageBox' => $storageBox,
        ]);
    }

    #[Route('/deposit/{id}/preview', name: 'storage_box_deposit_preview', methods: ['POST'])]
    public function depositPreview(#[MapEntity] StorageBox $storageBox, Request $request): Response
    {
        // Security check
        if ($storageBox->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $tradeableJson = $request->request->get('tradeable_json', '');
        $tradeLockedJson = $request->request->get('tradelocked_json', '');

        if (empty(trim($tradeableJson)) && empty(trim($tradeLockedJson))) {
            $this->addFlash('error', 'Please provide at least one inventory JSON (tradeable or trade-locked)');
            return $this->redirectToRoute('storage_box_deposit_form', ['id' => $storageBox->getId()]);
        }

        try {
            $user = $this->getUser();
            $preview = $this->transactionService->prepareDepositPreview($user, $storageBox, $tradeableJson, $tradeLockedJson);

            if ($preview->hasErrors()) {
                foreach ($preview->errors as $error) {
                    $this->addFlash('error', $error);
                }
                return $this->redirectToRoute('storage_box_deposit_form', ['id' => $storageBox->getId()]);
            }

            return $this->render('storage_box/deposit_preview.html.twig', [
                'storageBox' => $storageBox,
                'preview' => $preview,
                'userConfig' => $user->getConfig(),
            ]);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to process deposit: ' . $e->getMessage());
            return $this->redirectToRoute('storage_box_deposit_form', ['id' => $storageBox->getId()]);
        }
    }

    #[Route('/deposit/{id}/confirm', name: 'storage_box_deposit_confirm', methods: ['POST'])]
    public function depositConfirm(#[MapEntity] StorageBox $storageBox, Request $request): Response
    {
        // Security check
        if ($storageBox->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $sessionKey = $request->request->get('session_key');

        if (empty($sessionKey)) {
            $this->addFlash('error', 'Invalid session data. Please try again.');
            return $this->redirectToRoute('storage_box_deposit_form', ['id' => $storageBox->getId()]);
        }

        try {
            $user = $this->getUser();
            $result = $this->transactionService->executeDeposit($user, $storageBox, $sessionKey);

            if ($result->isSuccess()) {
                $this->addFlash('success', sprintf(
                    'Successfully deposited %d items into %s!',
                    $result->itemsMoved,
                    $storageBox->getName()
                ));
            } else {
                $this->addFlash('error', 'Deposit failed. Please try again.');
                foreach ($result->errors as $error) {
                    $this->addFlash('error', $error);
                }
            }

            return $this->redirectToRoute('inventory_index', ['filter' => 'box', 'box_id' => $storageBox->getId()]);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Deposit failed: ' . $e->getMessage());
            return $this->redirectToRoute('storage_box_deposit_form', ['id' => $storageBox->getId()]);
        }
    }

    #[Route('/withdraw/{id}', name: 'storage_box_withdraw_form')]
    public function withdrawForm(#[MapEntity] StorageBox $storageBox): Response
    {
        // Security check
        if ($storageBox->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('storage_box/withdraw.html.twig', [
            'storageBox' => $storageBox,
        ]);
    }

    #[Route('/withdraw/{id}/preview', name: 'storage_box_withdraw_preview', methods: ['POST'])]
    public function withdrawPreview(#[MapEntity] StorageBox $storageBox, Request $request): Response
    {
        // Security check
        if ($storageBox->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $tradeableJson = $request->request->get('tradeable_json', '');
        $tradeLockedJson = $request->request->get('tradelocked_json', '');

        if (empty(trim($tradeableJson)) && empty(trim($tradeLockedJson))) {
            $this->addFlash('error', 'Please provide at least one inventory JSON (tradeable or trade-locked)');
            return $this->redirectToRoute('storage_box_withdraw_form', ['id' => $storageBox->getId()]);
        }

        try {
            $user = $this->getUser();
            $preview = $this->transactionService->prepareWithdrawPreview($user, $storageBox, $tradeableJson, $tradeLockedJson);

            if ($preview->hasErrors()) {
                foreach ($preview->errors as $error) {
                    $this->addFlash('error', $error);
                }
                return $this->redirectToRoute('storage_box_withdraw_form', ['id' => $storageBox->getId()]);
            }

            return $this->render('storage_box/withdraw_preview.html.twig', [
                'storageBox' => $storageBox,
                'preview' => $preview,
                'userConfig' => $user->getConfig(),
            ]);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to process withdrawal: ' . $e->getMessage());
            return $this->redirectToRoute('storage_box_withdraw_form', ['id' => $storageBox->getId()]);
        }
    }

    #[Route('/withdraw/{id}/confirm', name: 'storage_box_withdraw_confirm', methods: ['POST'])]
    public function withdrawConfirm(#[MapEntity] StorageBox $storageBox, Request $request): Response
    {
        // Security check
        if ($storageBox->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $sessionKey = $request->request->get('session_key');

        if (empty($sessionKey)) {
            $this->addFlash('error', 'Invalid session data. Please try again.');
            return $this->redirectToRoute('storage_box_withdraw_form', ['id' => $storageBox->getId()]);
        }

        try {
            $user = $this->getUser();
            $result = $this->transactionService->executeWithdraw($user, $storageBox, $sessionKey);

            if ($result->isSuccess()) {
                $this->addFlash('success', sprintf(
                    'Successfully withdrew %d items from %s!',
                    $result->itemsMoved,
                    $storageBox->getName()
                ));
            } else {
                $this->addFlash('error', 'Withdrawal failed. Please try again.');
                foreach ($result->errors as $error) {
                    $this->addFlash('error', $error);
                }
            }

            return $this->redirectToRoute('inventory_index', ['filter' => 'active']);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Withdrawal failed: ' . $e->getMessage());
            return $this->redirectToRoute('storage_box_withdraw_form', ['id' => $storageBox->getId()]);
        }
    }

    #[Route('/create-manual', name: 'storage_box_create_manual', methods: ['GET'])]
    public function createManualForm(): Response
    {
        return $this->render('storage_box/create_manual.html.twig');
    }

    #[Route('/create-manual', name: 'storage_box_create_manual_submit', methods: ['POST'])]
    public function createManualSubmit(Request $request): Response
    {
        $name = trim($request->request->get('name', ''));

        // Validation
        if (empty($name)) {
            $this->addFlash('error', 'Storage box name is required.');
            return $this->render('storage_box/create_manual.html.twig', ['name' => $name]);
        }

        if (strlen($name) > 255) {
            $this->addFlash('error', 'Storage box name must be 255 characters or less.');
            return $this->render('storage_box/create_manual.html.twig', ['name' => $name]);
        }

        try {
            $user = $this->getUser();
            $box = $this->storageBoxService->createManualBox($user, $name);

            $this->addFlash('success', sprintf("Manual storage box '%s' created successfully!", $name));
            return $this->redirectToRoute('inventory_index', ['filter' => 'all']);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to create storage box: ' . $e->getMessage());
            return $this->render('storage_box/create_manual.html.twig', ['name' => $name]);
        }
    }
}
