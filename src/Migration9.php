<?php
declare(strict_types=1);
final class Migration9
{
    public static function run(PDO $db): void
    {
        $suffix=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        // Environment-specific authentication secrets are intentionally excluded from Schema::TABLES exports.
        $db->exec("CREATE TABLE IF NOT EXISTS user_mfa (user_id VARCHAR(36) PRIMARY KEY, secret TEXT NOT NULL, recovery_hashes TEXT NOT NULL, last_counter BIGINT NOT NULL DEFAULT -1, enabled_at VARCHAR(20) NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id))".$suffix);
        $db->exec('INSERT INTO schema_versions(version) VALUES(9)');
    }
}
