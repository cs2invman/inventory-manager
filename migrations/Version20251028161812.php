<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251028161812 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE item_price (id INT AUTO_INCREMENT NOT NULL, price_date DATETIME NOT NULL, price NUMERIC(10, 2) NOT NULL, volume INT DEFAULT NULL, median_price NUMERIC(10, 2) DEFAULT NULL, lowest_price NUMERIC(10, 2) DEFAULT NULL, highest_price NUMERIC(10, 2) DEFAULT NULL, source VARCHAR(50) NOT NULL, created_at DATETIME NOT NULL, item_id INT NOT NULL, INDEX IDX_E06F3909126F525E (item_id), INDEX idx_item_price_composite (item_id, price_date), INDEX idx_price_date (price_date), INDEX idx_price_source (source), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE item_price ADD CONSTRAINT FK_E06F3909126F525E FOREIGN KEY (item_id) REFERENCES item (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE item_price DROP FOREIGN KEY FK_E06F3909126F525E');
        $this->addSql('DROP TABLE item_price');
    }
}
