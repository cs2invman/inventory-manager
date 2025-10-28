<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251028164218 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE item_user (id INT AUTO_INCREMENT NOT NULL, asset_id VARCHAR(100) DEFAULT NULL, float_value NUMERIC(8, 7) DEFAULT NULL, paint_seed INT DEFAULT NULL, pattern_index INT DEFAULT NULL, storage_box_name VARCHAR(255) DEFAULT NULL, inspect_link VARCHAR(500) DEFAULT NULL, stattrak_counter INT DEFAULT NULL, is_stattrak TINYINT(1) DEFAULT 0 NOT NULL, is_souvenir TINYINT(1) DEFAULT 0 NOT NULL, stickers JSON DEFAULT NULL, name_tag VARCHAR(255) DEFAULT NULL, acquired_date DATETIME DEFAULT NULL, acquired_price NUMERIC(10, 2) DEFAULT NULL, current_market_value NUMERIC(10, 2) DEFAULT NULL, wear_category VARCHAR(10) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, item_id INT NOT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_45A392B25DA1941 (asset_id), INDEX IDX_45A392B2126F525E (item_id), INDEX idx_item_user_user (user_id), INDEX idx_item_user_composite (user_id, item_id), INDEX idx_storage_box (storage_box_name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE item_user ADD CONSTRAINT FK_45A392B2126F525E FOREIGN KEY (item_id) REFERENCES item (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE item_user ADD CONSTRAINT FK_45A392B2A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE item_user DROP FOREIGN KEY FK_45A392B2126F525E');
        $this->addSql('ALTER TABLE item_user DROP FOREIGN KEY FK_45A392B2A76ED395');
        $this->addSql('DROP TABLE item_user');
    }
}
