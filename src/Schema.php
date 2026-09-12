<?php
declare(strict_types=1);

final class Schema
{
    public const VERSION = 2;
    // Parent-first order is also used by portable data transfers.
    public const TABLES = ['companies','users','project_settings','task_templates','stores','store_assignments','tasks','task_photos','subtasks','subtask_photos','task_audit','reminder_log'];

    public static function migrate(PDO $db): void
    {
        $suffix = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $db->exec('CREATE TABLE IF NOT EXISTS schema_versions (version INTEGER PRIMARY KEY)' . $suffix);
        $current=(int)$db->query('SELECT COALESCE(MAX(version),0) FROM schema_versions')->fetchColumn();
        if ($current >= self::VERSION) {
            return;
        }
        if ($current < 1) {
        $statements = [
            "users" => "id VARCHAR(36) PRIMARY KEY, username VARCHAR(80) NOT NULL UNIQUE,
                display_name VARCHAR(120) NOT NULL, email VARCHAR(254) NULL, password_hash VARCHAR(255) NOT NULL,
                role VARCHAR(20) NOT NULL CHECK (role IN ('admin','pm','contractor')),
                active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0,1)),
                session_version INTEGER NOT NULL DEFAULT 1, created_at VARCHAR(20) NOT NULL, updated_at VARCHAR(20) NOT NULL",
            "stores" => "id VARCHAR(36) PRIMARY KEY, code VARCHAR(40) NOT NULL UNIQUE, name VARCHAR(160) NOT NULL,
                city VARCHAR(120) NOT NULL, target_date VARCHAR(10) NULL, created_at VARCHAR(20) NOT NULL",
            "store_assignments" => "store_id VARCHAR(36) NOT NULL, user_id VARCHAR(36) NOT NULL,
                PRIMARY KEY (store_id, user_id), FOREIGN KEY (store_id) REFERENCES stores(id),
                FOREIGN KEY (user_id) REFERENCES users(id)",
            "tasks" => "id VARCHAR(36) PRIMARY KEY, store_id VARCHAR(36) NOT NULL,
                category VARCHAR(20) NOT NULL CHECK (category IN ('network','audio','dvr','rack')),
                status VARCHAR(20) NOT NULL CHECK (status IN ('planned','in_progress','blocked','completed')),
                due_date VARCHAR(10) NULL, completed_at VARCHAR(20) NULL, created_at VARCHAR(20) NOT NULL,
                FOREIGN KEY (store_id) REFERENCES stores(id)",
            "task_photos" => "id VARCHAR(36) PRIMARY KEY, task_id VARCHAR(36) NOT NULL, uploaded_by VARCHAR(36) NOT NULL,
                storage_key VARCHAR(255) NOT NULL, original_name VARCHAR(255) NOT NULL,
                mime_type VARCHAR(80) NOT NULL, created_at VARCHAR(20) NOT NULL,
                FOREIGN KEY (task_id) REFERENCES tasks(id), FOREIGN KEY (uploaded_by) REFERENCES users(id)",
            "login_attempts" => "id VARCHAR(36) PRIMARY KEY, attempt_key VARCHAR(64) NOT NULL, attempted_at INTEGER NOT NULL",
        ];
        foreach ($statements as $table => $columns) {
            $db->exec("CREATE TABLE IF NOT EXISTS $table ($columns)" . $suffix);
        }
        $db->exec('INSERT INTO schema_versions (version) VALUES (1)');
        }
        require_once __DIR__.'/Migration2.php';
        Migration2::run($db);
    }

    public static function id(): string
    {
        return bin2hex(random_bytes(16));
    }
}
