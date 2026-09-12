<?php
declare(strict_types=1);
final class Migration5
{
    public static function run(PDO $db): void
    {
        $suffix=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $db->exec("CREATE TABLE IF NOT EXISTS store_pm_notes (
            id VARCHAR(36) PRIMARY KEY, store_id VARCHAR(36) NOT NULL, author_id VARCHAR(36) NOT NULL,
            author_name VARCHAR(120) NOT NULL, note TEXT NOT NULL, created_at VARCHAR(20) NOT NULL,
            include_in_report INTEGER NOT NULL DEFAULT 1 CHECK(include_in_report IN (0,1)),
            FOREIGN KEY(store_id) REFERENCES stores(id), FOREIGN KEY(author_id) REFERENCES users(id)
        )".$suffix);
        $db->exec('INSERT INTO schema_versions(version) VALUES(5)');
    }
}
