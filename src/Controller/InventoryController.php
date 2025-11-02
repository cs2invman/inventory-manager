<?php

namespace App\Controller;

use App\Repository\ItemUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/inventory')]
#[IsGranted('ROLE_USER')]
class InventoryController extends AbstractController
{
    public function __construct(
        private ItemUserRepository $itemUserRepository,
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('', name: 'inventory_index', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();

        // Fetch user inventory
        $inventoryItems = $this->itemUserRepository->findUserInventory($user->getId());

        // Get latest prices for all items and calculate total value
        $totalValue = 0.0;
        $itemsWithPrices = [];

        foreach ($inventoryItems as $itemUser) {
            $item = $itemUser->getItem();

            // Get the latest price for this item
            $latestPrice = $this->entityManager->createQuery('
                SELECT ip
                FROM App\Entity\ItemPrice ip
                WHERE ip.item = :item
                ORDER BY ip.priceDate DESC
            ')
            ->setParameter('item', $item)
            ->setMaxResults(1)
            ->getOneOrNullResult();

            $priceValue = $latestPrice ? (float) $latestPrice->getPrice() : 0.0;
            $totalValue += $priceValue;

            $itemsWithPrices[] = [
                'itemUser' => $itemUser,
                'latestPrice' => $latestPrice,
                'priceValue' => $priceValue,
            ];
        }

        // Sort by price descending
        usort($itemsWithPrices, function ($a, $b) {
            return $b['priceValue'] <=> $a['priceValue'];
        });

        return $this->render('inventory/index.html.twig', [
            'itemsWithPrices' => $itemsWithPrices,
            'totalValue' => $totalValue,
            'itemCount' => count($inventoryItems),
        ]);
    }
}