<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add webhook URL configuration for Discord notifications.
 */
final class Version20251114000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Discord webhook URL configuration entries';
    }

    public function up(Schema $schema): void
    {
        $now = date('Y-m-d H:i:s');

        // Add webhook URL configuration (initially empty, to be configured by user)
        $this->addSql("INSERT INTO discord_config (config_key, config_value, description, is_enabled, created_at, updated_at)
            VALUES ('webhook_system_events', '', 'Discord webhook URL for system event notifications (Steam sync, errors, etc.)', 0, '{$now}', '{$now}')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM discord_config WHERE config_key = 'webhook_system_events'");
    }
}
