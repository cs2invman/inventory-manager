<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251028161218 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE item (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, image_url VARCHAR(500) NOT NULL, steam_id VARCHAR(100) NOT NULL, type VARCHAR(50) NOT NULL, hash_name VARCHAR(255) NOT NULL, category VARCHAR(100) NOT NULL, subcategory VARCHAR(100) DEFAULT NULL, rarity VARCHAR(50) NOT NULL, rarity_color VARCHAR(7) DEFAULT NULL, collection VARCHAR(255) DEFAULT NULL, stattrak_available TINYINT(1) DEFAULT 0 NOT NULL, souvenir_available TINYINT(1) DEFAULT 0 NOT NULL, description LONGTEXT DEFAULT NULL, icon_url_large VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_1F1B251EF3FD4ECA (steam_id), UNIQUE INDEX UNIQ_1F1B251EE1F029B6 (hash_name), INDEX idx_item_type (type), INDEX idx_item_category (category), INDEX idx_item_rarity (rarity), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE item');
    }
}
