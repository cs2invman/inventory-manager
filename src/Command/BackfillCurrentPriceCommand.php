<?php

namespace App\Command;

use App\Entity\Item;
use App\Entity\ItemPrice;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:item:backfill-current-price',
    description: 'Backfill current_price_id for all existing items'
)]
class BackfillCurrentPriceCommand extends Command
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force backfill even for items that already have current_price_id set'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = $input->getOption('force');

        $io->title('Backfilling current_price_id for Items');

        // First, get all item IDs to process (fetch IDs only to avoid memory issues)
        $idsQb = $this->entityManager->createQueryBuilder();
        $idsQb->select('i.id')
            ->from(Item::class, 'i');

        if (!$force) {
            $idsQb->where('i.currentPrice IS NULL');
            $io->note('Only processing items without current_price_id set');
        } else {
            $io->note('Force mode: processing ALL items');
        }

        $itemIds = array_column($idsQb->getQuery()->getResult(), 'id');
        $totalItems = count($itemIds);

        if ($totalItems === 0) {
            $io->success('No items to process');
            return Command::SUCCESS;
        }

        $io->text(sprintf('Found %d items to process', $totalItems));
        $io->newLine();

        $backfilledCount = 0;
        $skippedCount = 0;
        $processedCount = 0;

        $progressBar = $io->createProgressBar($totalItems);
        $progressBar->start();

        // Process item IDs in batches
        $batches = array_chunk($itemIds, self::BATCH_SIZE);

        foreach ($batches as $batchIds) {
            // Fetch items for this batch
            $items = $this->entityManager->createQueryBuilder()
                ->select('i')
                ->from(Item::class, 'i')
                ->where('i.id IN (:ids)')
                ->setParameter('ids', $batchIds)
                ->getQuery()
                ->getResult();

            foreach ($items as $item) {
                // Find latest price for this item
                $latestPrice = $this->entityManager->createQuery('
                    SELECT ip FROM App\Entity\ItemPrice ip
                    WHERE ip.item = :itemId
                    ORDER BY ip.priceDate DESC
                ')
                ->setParameter('itemId', $item->getId())
                ->setMaxResults(1)
                ->getOneOrNullResult();

                if ($latestPrice) {
                    $item->setCurrentPrice($latestPrice);
                    $backfilledCount++;
                } else {
                    $skippedCount++;
                }

                $processedCount++;
            }

            // Flush and clear after each batch
            $this->entityManager->flush();
            $this->entityManager->clear();

            $progressBar->advance(count($items));
        }

        $progressBar->finish();

        $io->newLine(2);
        $io->success('Backfill completed!');
        $io->table(
            ['Metric', 'Count'],
            [
                ['Total items processed', $processedCount],
                ['Items with current_price_id set', $backfilledCount],
                ['Items without prices (skipped)', $skippedCount],
            ]
        );

        return Command::SUCCESS;
    }
}
