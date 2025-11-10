<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251110202310 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE item ADD unstable TINYINT(1) DEFAULT 0 NOT NULL, ADD unstable_reason VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE item_price ADD sold30d INT DEFAULT NULL, ADD sold7d INT DEFAULT NULL, ADD sold_today INT DEFAULT NULL, ADD volume_buy_orders INT DEFAULT NULL, ADD volume_sell_orders INT DEFAULT NULL, ADD price_buy_order NUMERIC(10, 2) DEFAULT NULL, ADD price_median NUMERIC(10, 2) DEFAULT NULL, ADD price_median24h NUMERIC(10, 2) DEFAULT NULL, ADD price_median7d NUMERIC(10, 2) DEFAULT NULL, ADD price_median30d NUMERIC(10, 2) DEFAULT NULL, DROP lowest_price, DROP highest_price, CHANGE volume sold_total INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE item DROP unstable, DROP unstable_reason');
        $this->addSql('ALTER TABLE item_price ADD volume INT DEFAULT NULL, ADD lowest_price NUMERIC(10, 2) DEFAULT NULL, ADD highest_price NUMERIC(10, 2) DEFAULT NULL, DROP sold_total, DROP sold30d, DROP sold7d, DROP sold_today, DROP volume_buy_orders, DROP volume_sell_orders, DROP price_buy_order, DROP price_median, DROP price_median24h, DROP price_median7d, DROP price_median30d');
    }
}
