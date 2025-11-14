<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251113211032 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Discord database foundation: discord_config, discord_user, and discord_notification tables';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // Create tables
        $this->addSql('CREATE TABLE discord_config (id INT AUTO_INCREMENT NOT NULL, config_key VARCHAR(100) NOT NULL, config_value LONGTEXT DEFAULT NULL, description VARCHAR(255) DEFAULT NULL, is_enabled TINYINT(1) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_CONFIG_KEY (config_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE discord_notification (id INT AUTO_INCREMENT NOT NULL, notification_type VARCHAR(50) NOT NULL, channel_id VARCHAR(20) DEFAULT NULL, webhook_url VARCHAR(255) DEFAULT NULL, message_content LONGTEXT NOT NULL, embed_data JSON DEFAULT NULL, status VARCHAR(20) NOT NULL, error_message LONGTEXT DEFAULT NULL, sent_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, INDEX IDX_NOTIFICATION_TYPE (notification_type), INDEX IDX_NOTIFICATION_STATUS (status), INDEX IDX_NOTIFICATION_CREATED (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE discord_user (id INT AUTO_INCREMENT NOT NULL, discord_id VARCHAR(20) NOT NULL, discord_username VARCHAR(100) NOT NULL, discord_discriminator VARCHAR(4) DEFAULT NULL, is_verified TINYINT(1) NOT NULL, linked_at DATETIME NOT NULL, verified_at DATETIME DEFAULT NULL, last_command_at DATETIME DEFAULT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_F445D549A76ED395 (user_id), INDEX IDX_DISCORD_USER_USER (user_id), UNIQUE INDEX UNIQ_DISCORD_ID (discord_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE discord_user ADD CONSTRAINT FK_F445D549A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');

        // Insert default configuration entries
        $now = date('Y-m-d H:i:s');
        $this->addSql("INSERT INTO discord_config (config_key, config_value, description, is_enabled, created_at, updated_at) VALUES ('notify_system_events', 'true', 'Enable/disable Discord notifications for system events', 1, '{$now}', '{$now}')");
        $this->addSql("INSERT INTO discord_config (config_key, config_value, description, is_enabled, created_at, updated_at) VALUES ('system_events_rate_limit_minutes', '60', 'Minimum minutes between system event notifications to prevent spam', 1, '{$now}', '{$now}')");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE discord_user DROP FOREIGN KEY FK_F445D549A76ED395');
        $this->addSql('DROP TABLE discord_config');
        $this->addSql('DROP TABLE discord_notification');
        $this->addSql('DROP TABLE discord_user');
    }
}
