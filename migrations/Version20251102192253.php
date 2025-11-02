<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251102192253 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add SteamWebAPI sync fields to Item entity (externalId, active, marketName, slug, classId, instanceId, groupId, borderColor, itemColor, quality, points)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE item ADD external_id VARCHAR(50) DEFAULT NULL, ADD active TINYINT(1) DEFAULT 1 NOT NULL, ADD market_name VARCHAR(255) DEFAULT NULL, ADD slug VARCHAR(255) DEFAULT NULL, ADD class_id VARCHAR(50) DEFAULT NULL, ADD instance_id VARCHAR(50) DEFAULT NULL, ADD group_id VARCHAR(50) DEFAULT NULL, ADD border_color VARCHAR(6) DEFAULT NULL, ADD item_color VARCHAR(6) DEFAULT NULL, ADD quality VARCHAR(50) DEFAULT NULL, ADD points INT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1F1B251E9F75D7B0 ON item (external_id)');
        $this->addSql('CREATE INDEX idx_item_active ON item (active)');
        $this->addSql('CREATE INDEX idx_item_external_id ON item (external_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_1F1B251E9F75D7B0 ON item');
        $this->addSql('DROP INDEX idx_item_active ON item');
        $this->addSql('DROP INDEX idx_item_external_id ON item');
        $this->addSql('ALTER TABLE item DROP external_id, DROP active, DROP market_name, DROP slug, DROP class_id, DROP instance_id, DROP group_id, DROP border_color, DROP item_color, DROP quality, DROP points');
    }
}
