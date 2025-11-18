<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251118041701 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE process_queue_processor (id INT AUTO_INCREMENT NOT NULL, processor_name VARCHAR(100) NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, completed_at DATETIME DEFAULT NULL, failed_at DATETIME DEFAULT NULL, error_message LONGTEXT DEFAULT NULL, attempts INT NOT NULL, process_queue_id INT NOT NULL, INDEX IDX_C1644D34B18A052F (process_queue_id), INDEX idx_queue_status (process_queue_id, status), UNIQUE INDEX uniq_queue_processor (process_queue_id, processor_name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE process_queue_processor ADD CONSTRAINT FK_C1644D34B18A052F FOREIGN KEY (process_queue_id) REFERENCES process_queue (id) ON DELETE CASCADE');
        $this->addSql('DROP INDEX uniq_item_type_status ON process_queue');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE process_queue_processor DROP FOREIGN KEY FK_C1644D34B18A052F');
        $this->addSql('DROP TABLE process_queue_processor');
        $this->addSql('CREATE UNIQUE INDEX uniq_item_type_status ON process_queue (item_id, process_type, status)');
    }
}
