<?php

namespace App\Controller;

use App\Entity\LedgerEntry;
use App\Form\LedgerEntryType;
use App\Repository\LedgerEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/ledger')]
#[IsGranted('ROLE_USER')]
class LedgerController extends AbstractController
{
    public function __construct(
        private readonly LedgerEntryRepository $ledgerEntryRepository,
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    #[Route('', name: 'ledger_index', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();
        $entries = $this->ledgerEntryRepository->findByUser(
            $user,
            ['transactionDate' => 'DESC']
        );

        // Calculate totals
        $userConfig = $user->getConfig();
        $preferredCurrency = $userConfig?->getPreferredCurrency() ?? 'USD';
        $exchangeRate = $userConfig?->getCadExchangeRate() ?? 1.38;

        $totalCount = count($entries);
        $netTotal = 0.0;

        foreach ($entries as $entry) {
            $amount = (float) $entry->getAmount();

            // Convert to USD first if the original currency is CAD
            $amountInUsd = $amount;
            if ($entry->getCurrency() === 'CAD') {
                $amountInUsd = $amount / $exchangeRate;
            }

            // Convert to user's preferred currency
            $amountInPreferredCurrency = $amountInUsd;
            if ($preferredCurrency === 'CAD') {
                $amountInPreferredCurrency = $amountInUsd * $exchangeRate;
            }

            // Add or subtract based on transaction type
            if ($entry->getTransactionType() === 'investment') {
                $netTotal += $amountInPreferredCurrency;
            } else {
                $netTotal -= $amountInPreferredCurrency;
            }
        }

        return $this->render('ledger/index.html.twig', [
            'entries' => $entries,
            'userConfig' => $userConfig,
            'totalCount' => $totalCount,
            'netTotal' => $netTotal,
            'preferredCurrency' => $preferredCurrency,
        ]);
    }

    #[Route('/new', name: 'ledger_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $user = $this->getUser();
        $ledgerEntry = new LedgerEntry();
        $ledgerEntry->setUser($user);

        $form = $this->createForm(LedgerEntryType::class, $ledgerEntry);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->entityManager->persist($ledgerEntry);
                $this->entityManager->flush();

                $this->addFlash('success', 'Ledger entry created successfully!');
                return $this->redirectToRoute('ledger_index');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Failed to create ledger entry: ' . $e->getMessage());
            }
        }

        return $this->render('ledger/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/edit', name: 'ledger_edit', methods: ['GET', 'POST'])]
    public function edit(#[MapEntity] LedgerEntry $ledgerEntry, Request $request): Response
    {
        // Security check
        if ($ledgerEntry->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException('You cannot edit another user\'s ledger entry.');
        }

        $form = $this->createForm(LedgerEntryType::class, $ledgerEntry);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $ledgerEntry->setUpdatedAt(new \DateTimeImmutable());
                $this->entityManager->flush();

                $this->addFlash('success', 'Ledger entry updated successfully!');
                return $this->redirectToRoute('ledger_index');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Failed to update ledger entry: ' . $e->getMessage());
            }
        }

        return $this->render('ledger/edit.html.twig', [
            'form' => $form->createView(),
            'ledgerEntry' => $ledgerEntry,
        ]);
    }

    #[Route('/{id}/delete', name: 'ledger_delete', methods: ['POST'])]
    public function delete(#[MapEntity] LedgerEntry $ledgerEntry, Request $request): Response
    {
        // Security check
        if ($ledgerEntry->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException('You cannot delete another user\'s ledger entry.');
        }

        // CSRF protection
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete-ledger-' . $ledgerEntry->getId(), $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('ledger_index');
        }

        try {
            $this->entityManager->remove($ledgerEntry);
            $this->entityManager->flush();

            $this->addFlash('success', 'Ledger entry deleted successfully!');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to delete ledger entry: ' . $e->getMessage());
        }

        return $this->redirectToRoute('ledger_index');
    }
}
