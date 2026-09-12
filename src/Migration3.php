<?php
declare(strict_types=1);
final class Migration3
{
    public static function run(PDO $db): void
    {
        $suffix=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $db->exec("CREATE TABLE IF NOT EXISTS manual_reminder_log (
            id VARCHAR(36) PRIMARY KEY, store_id VARCHAR(36) NOT NULL, actor_id VARCHAR(36) NOT NULL,
            actor_name VARCHAR(120) NOT NULL, request_id VARCHAR(36) NOT NULL, recipient VARCHAR(254) NOT NULL,
            installation_date VARCHAR(10) NOT NULL, scheduled_for VARCHAR(10) NOT NULL,
            status VARCHAR(20) NOT NULL CHECK(status IN ('sending','sent','failed')), error TEXT NULL,
            created_at VARCHAR(20) NOT NULL, sent_at VARCHAR(20) NULL,
            UNIQUE(request_id,recipient), FOREIGN KEY(store_id) REFERENCES stores(id), FOREIGN KEY(actor_id) REFERENCES users(id)
        )".$suffix);
        $db->exec('INSERT INTO schema_versions(version) VALUES(3)');
    }
}
