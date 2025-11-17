<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251117153729 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Refactor Discord webhooks to dedicated table';
    }

    public function up(Schema $schema): void
    {
        // Create discord_webhook table
        $this->addSql('CREATE TABLE discord_webhook (id INT AUTO_INCREMENT NOT NULL, identifier VARCHAR(50) NOT NULL, display_name VARCHAR(100) NOT NULL, webhook_url VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, is_enabled TINYINT(1) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_IDENTIFIER (identifier), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        // Migrate existing webhooks from discord_config to discord_webhook
        $now = date('Y-m-d H:i:s');
        $this->addSql("
            INSERT INTO discord_webhook (identifier, display_name, webhook_url, description, is_enabled, created_at, updated_at)
            SELECT
                REPLACE(config_key, 'webhook_', '') as identifier,
                CONCAT(UPPER(SUBSTRING(REPLACE(config_key, 'webhook_', ''), 1, 1)),
                       SUBSTRING(REPLACE(REPLACE(config_key, 'webhook_', ''), '_', ' '), 2)) as display_name,
                config_value as webhook_url,
                description,
                is_enabled,
                '{$now}' as created_at,
                '{$now}' as updated_at
            FROM discord_config
            WHERE config_key LIKE 'webhook_%'
            AND config_value IS NOT NULL
            AND config_value != ''
        ");

        // Remove webhook entries from discord_config
        $this->addSql("DELETE FROM discord_config WHERE config_key LIKE 'webhook_%'");
    }

    public function down(Schema $schema): void
    {
        // Restore webhooks to discord_config before dropping table
        $this->addSql("
            INSERT INTO discord_config (config_key, config_value, description, is_enabled, created_at, updated_at)
            SELECT
                CONCAT('webhook_', identifier) as config_key,
                webhook_url as config_value,
                description,
                is_enabled,
                created_at,
                updated_at
            FROM discord_webhook
        ");

        // Drop discord_webhook table
        $this->addSql('DROP TABLE discord_webhook');
    }
}
