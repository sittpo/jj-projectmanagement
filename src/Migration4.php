<?php
declare(strict_types=1);
final class Migration4
{
    public static function run(PDO $db): void
    {
        $suffix=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $db->exec("CREATE TABLE IF NOT EXISTS user_preferences (
            user_id VARCHAR(36) PRIMARY KEY, show_all_stores INTEGER NOT NULL DEFAULT 0 CHECK(show_all_stores IN (0,1)),
            FOREIGN KEY(user_id) REFERENCES users(id)
        )".$suffix);
        $db->exec('INSERT INTO schema_versions(version) VALUES(4)');
    }
}
