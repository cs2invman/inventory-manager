<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251113155157 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add current_price_id to Item entity for query optimization';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE item ADD current_price_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE item ADD CONSTRAINT FK_1F1B251E4B6CBDCD FOREIGN KEY (current_price_id) REFERENCES item_price (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_1F1B251E4B6CBDCD ON item (current_price_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE item DROP FOREIGN KEY FK_1F1B251E4B6CBDCD');
        $this->addSql('DROP INDEX IDX_1F1B251E4B6CBDCD ON item');
        $this->addSql('ALTER TABLE item DROP current_price_id');
    }
}
