<?php
declare(strict_types=1);
final class Migration2
{
    public static function run(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        // Update the existing role constraint without changing IDs or dependent records.
        if ($mysql) {
            $db->exec("ALTER TABLE users MODIFY COLUMN role VARCHAR(20) NOT NULL CHECK (role IN ('contractor','contractor_admin','pm','admin'))");
        } else {
            $sql = $db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
            $sql = str_replace("CREATE TABLE users", "CREATE TABLE users_v2", $sql);
            $sql = str_replace("'admin','pm','contractor'", "'admin','pm','contractor','contractor_admin'", $sql);
            $db->exec('PRAGMA foreign_keys=OFF');
            try {
                $db->beginTransaction();
                $db->exec($sql);
                $db->exec('INSERT INTO users_v2 SELECT * FROM users');
                $db->exec('DROP TABLE users');
                $db->exec('ALTER TABLE users_v2 RENAME TO users');
                $db->commit();
            } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
            finally { $db->exec('PRAGMA foreign_keys=ON'); }
        }
        $tables = [
            'companies' => 'id VARCHAR(36) PRIMARY KEY, name VARCHAR(160) NOT NULL UNIQUE, email VARCHAR(254) NULL, phone VARCHAR(60) NULL, active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1))',
            'project_settings' => 'setting_key VARCHAR(80) PRIMARY KEY, setting_value TEXT NOT NULL',
            'task_templates' => "id VARCHAR(36) PRIMARY KEY, category VARCHAR(20) NOT NULL CHECK(category IN ('network','audio','dvr','rack')), title VARCHAR(200) NOT NULL, instructions TEXT NOT NULL, ui_order INTEGER NOT NULL, report_order INTEGER NOT NULL, active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1))",
            'subtasks' => 'id VARCHAR(36) PRIMARY KEY, task_id VARCHAR(36) NOT NULL, template_id VARCHAR(36) NULL, title VARCHAR(200) NOT NULL, instructions TEXT NOT NULL, ui_order INTEGER NOT NULL, report_order INTEGER NOT NULL, note TEXT NOT NULL, complete INTEGER NOT NULL DEFAULT 0 CHECK(complete IN (0,1)), completed_by VARCHAR(36) NULL, completed_at VARCHAR(20) NULL, signed_by VARCHAR(36) NULL, signed_name VARCHAR(120) NULL, signed_at VARCHAR(20) NULL, version INTEGER NOT NULL DEFAULT 1, FOREIGN KEY(task_id) REFERENCES tasks(id), FOREIGN KEY(template_id) REFERENCES task_templates(id), FOREIGN KEY(completed_by) REFERENCES users(id), FOREIGN KEY(signed_by) REFERENCES users(id)',
            'subtask_photos' => 'id VARCHAR(36) PRIMARY KEY, subtask_id VARCHAR(36) NOT NULL, uploaded_by VARCHAR(36) NOT NULL, storage_key VARCHAR(255) NOT NULL, original_name VARCHAR(255) NOT NULL, mime_type VARCHAR(80) NOT NULL, created_at VARCHAR(20) NOT NULL, FOREIGN KEY(subtask_id) REFERENCES subtasks(id), FOREIGN KEY(uploaded_by) REFERENCES users(id)',
            'task_audit' => 'id VARCHAR(36) PRIMARY KEY, subtask_id VARCHAR(36) NOT NULL, actor_id VARCHAR(36) NOT NULL, actor_name VARCHAR(120) NOT NULL, action VARCHAR(40) NOT NULL, details TEXT NOT NULL, created_at VARCHAR(20) NOT NULL, FOREIGN KEY(subtask_id) REFERENCES subtasks(id), FOREIGN KEY(actor_id) REFERENCES users(id)',
            'reminder_log' => "id VARCHAR(36) PRIMARY KEY, store_id VARCHAR(36) NOT NULL, recipient VARCHAR(254) NOT NULL, installation_date VARCHAR(10) NOT NULL, scheduled_for VARCHAR(10) NOT NULL, status VARCHAR(20) NOT NULL CHECK(status IN ('sending','sent','failed','unknown')), error TEXT NULL, created_at VARCHAR(20) NOT NULL, sent_at VARCHAR(20) NULL, UNIQUE(store_id,recipient,installation_date,scheduled_for), FOREIGN KEY(store_id) REFERENCES stores(id)",
        ];
        foreach ($tables as $table => $columns) { $db->exec("CREATE TABLE IF NOT EXISTS $table ($columns)$suffix"); }
        self::column($db, 'users', 'company_id', 'VARCHAR(36) NULL REFERENCES companies(id)');
        foreach ([
            'company_id'=>'VARCHAR(36) NULL REFERENCES companies(id)', 'owner_name'=>'VARCHAR(120) NULL', 'owner_phone'=>'VARCHAR(60) NULL',
            'owner_email'=>'VARCHAR(254) NULL', 'contact_name'=>'VARCHAR(120) NULL', 'contact_phone'=>'VARCHAR(60) NULL',
            'contact_email'=>'VARCHAR(254) NULL', 'reminder_date'=>'VARCHAR(10) NULL', 'reminders_enabled'=>'INTEGER NOT NULL DEFAULT 1',
        ] as $column => $definition) { self::column($db, 'stores', $column, $definition); }
        if ($mysql) {
            foreach (['users','stores'] as $table) {
                $q=$db->prepare("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='company_id' AND REFERENCED_TABLE_NAME='companies'");
                $q->execute([$table]);
                if (!(int)$q->fetchColumn()) $db->exec("ALTER TABLE $table ADD FOREIGN KEY(company_id) REFERENCES companies(id)");
            }
        }
        $q=$db->prepare('SELECT setting_value FROM project_settings WHERE setting_key=?');$q->execute(['reminder_days']);
        if ($q->fetchColumn()===false) $db->exec("INSERT INTO project_settings VALUES ('reminder_days','7')");
        if (!(int)$db->query('SELECT COUNT(*) FROM task_templates')->fetchColumn()) {
            $q=$db->prepare('INSERT INTO task_templates(id,category,title,instructions,ui_order,report_order,active) VALUES (?,?,?,?,?,?,1)');
            foreach (['network'=>'Replace networking equipment','audio'=>'Replace audio equipment','dvr'=>'Replace DVR equipment','rack'=>'Mount the new rack cabinet'] as $index=>$title) {
                $order=($order??0)+1; $q->execute([Schema::id(),$index,$title,'Confirm the installation and add photo documentation.',$order,$order]);
            }
        }
        $db->exec('INSERT INTO schema_versions(version) VALUES (2)');
    }
    private static function column(PDO $db,string $table,string $column,string $definition): void
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') {
            $q=$db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
            $q->execute([$table,$column]);$exists=(bool)$q->fetchColumn();
        } else { $exists=in_array($column,array_column($db->query("PRAGMA table_info($table)")->fetchAll(),'name'),true); }
        if (!$exists) $db->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    }
}
