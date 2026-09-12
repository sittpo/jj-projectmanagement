<?php
declare(strict_types=1);
// Run locally: php scripts/migrate-to-mariadb.php
// Supply a local administrative password through storage/mariadb-root-password.txt.
// The password file, generated application password, and backups remain outside Git.
if (PHP_SAPI !== 'cli') { exit; }
require dirname(__DIR__) . '/src/app.php';
require dirname(__DIR__) . '/src/DataTransfer.php';

try {
    if ($config['environment'] !== 'dev' || $config['driver'] !== 'sqlite') {
        throw new RuntimeException('This one-time migration requires the existing SQLite dev configuration.');
    }
    $root = dirname(__DIR__);
    $passwordFile = $root . '/storage/mariadb-root-password.txt';
    if (!is_file($passwordFile)) { throw new RuntimeException('Save the local MariaDB root password in storage/mariadb-root-password.txt first.'); }
    $adminPassword = rtrim(file_get_contents($passwordFile), "\r\n");
    $admin = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', $adminPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $databaseName = 'jj_project_management_dev';
    $username = 'jj_pm_dev';
    $existing = $admin->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
    $existing->execute([$databaseName]);
    if ((int) $existing->fetchColumn() !== 0) { throw new RuntimeException('Target database already exists. Review it before migrating.'); }
    $existing = $admin->prepare('SELECT COUNT(*) FROM mysql.user WHERE User=? AND Host=?');
    $existing->execute([$username, 'localhost']);
    if ((int) $existing->fetchColumn() !== 0) { throw new RuntimeException('Target database user already exists. Review it before migrating.'); }

    $stamp = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
    $export = $root . '/storage/sqlite-before-mariadb-' . $stamp . '.json';
    (new DataTransfer($db))->export($export);
    if (!copy($root . '/.env', $root . '/storage/env-before-mariadb-' . $stamp . '.backup')) {
        throw new RuntimeException('Could not back up the local configuration.');
    }
    $appPassword = bin2hex(random_bytes(24));
    $admin->exec("CREATE DATABASE $databaseName CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $admin->exec("CREATE USER '$username'@'localhost' IDENTIFIED BY " . $admin->quote($appPassword));
    $admin->exec("GRANT ALL PRIVILEGES ON $databaseName.* TO '$username'@'localhost'");
    $targetConfig = array_replace($config, ['driver'=>'mysql','database'=>$databaseName,'host'=>'127.0.0.1','port'=>'3306','username'=>$username,'password'=>$appPassword]);
    $target = connectDatabase($targetConfig);
    Schema::migrate($target);
    (new DataTransfer($target))->import($export, 'dev');
    foreach (Schema::TABLES as $table) {
        $sourceRows = $db->query("SELECT * FROM $table")->fetchAll();
        $targetRows = $target->query("SELECT * FROM $table")->fetchAll();
        $normalize = static function (array $rows) use ($table): array {
            foreach ($rows as &$row) {
                if ($table === 'users') { unset($row['session_version']); }
                ksort($row);
                foreach ($row as &$value) { if ($value !== null) { $value = (string) $value; } }
                unset($value);
            }
            unset($row);
            usort($rows, static fn(array $left, array $right): int => strcmp(json_encode($left), json_encode($right)));
            return $rows;
        };
        if ($normalize($sourceRows) !== $normalize($targetRows)) { throw new RuntimeException("Data verification failed for $table. SQLite remains active."); }
        echo "$table: " . count($targetRows) . " rows verified.\n";
    }
    // Write a candidate first. Switch .env only after the MariaDB test suite passes.
    $envText = "APP_ENV=dev\nDB_CONNECTION=mysql\nDB_DATABASE=$databaseName\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_USERNAME=$username\nDB_PASSWORD=$appPassword\n";
    file_put_contents($root . '/storage/mariadb.env', $envText, LOCK_EX);
    echo "Migration and data comparison complete on " . $target->query('SELECT VERSION()')->fetchColumn() . ".\n";
    echo "Candidate configuration: storage/mariadb.env. SQLite remains active until verification finishes.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
