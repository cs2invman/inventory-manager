<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251118033126 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE process_queue (id INT AUTO_INCREMENT NOT NULL, process_type VARCHAR(50) NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, failed_at DATETIME DEFAULT NULL, error_message LONGTEXT DEFAULT NULL, attempts INT NOT NULL, item_id INT NOT NULL, INDEX IDX_29A15292126F525E (item_id), INDEX idx_status_created (status, created_at), INDEX idx_process_type (process_type), UNIQUE INDEX uniq_item_type_status (item_id, process_type, status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE process_queue ADD CONSTRAINT FK_29A15292126F525E FOREIGN KEY (item_id) REFERENCES item (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE process_queue DROP FOREIGN KEY FK_29A15292126F525E');
        $this->addSql('DROP TABLE process_queue');
    }
}
