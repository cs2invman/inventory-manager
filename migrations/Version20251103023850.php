<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251103023850 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create storage_box table and migrate item_user from storageBoxName to storageBox relationship';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE storage_box (id INT AUTO_INCREMENT NOT NULL, asset_id VARCHAR(100) NOT NULL, name VARCHAR(255) NOT NULL, item_count INT NOT NULL, modification_date DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_932E76365DA1941 (asset_id), INDEX IDX_932E7636A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE storage_box ADD CONSTRAINT FK_932E7636A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('DROP INDEX idx_storage_box ON item_user');
        $this->addSql('ALTER TABLE item_user ADD storage_box_id INT DEFAULT NULL, DROP storage_box_name');
        $this->addSql('ALTER TABLE item_user ADD CONSTRAINT FK_45A392B2E3148F3E FOREIGN KEY (storage_box_id) REFERENCES storage_box (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_45A392B2E3148F3E ON item_user (storage_box_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE storage_box DROP FOREIGN KEY FK_932E7636A76ED395');
        $this->addSql('DROP TABLE storage_box');
        $this->addSql('ALTER TABLE item_user DROP FOREIGN KEY FK_45A392B2E3148F3E');
        $this->addSql('DROP INDEX IDX_45A392B2E3148F3E ON item_user');
        $this->addSql('ALTER TABLE item_user ADD storage_box_name VARCHAR(255) DEFAULT NULL, DROP storage_box_id');
        $this->addSql('CREATE INDEX idx_storage_box ON item_user (storage_box_name)');
    }
}
