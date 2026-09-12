<?php
declare(strict_types=1);

final class DatabaseSandbox
{
    public static function create(array $config, string $name): PDO
    {
        self::validate($config, $name);
        $server = connectDatabase($config);
        $server->exec("CREATE DATABASE $name CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        return connectDatabase(array_replace($config, ['database' => $name]));
    }

    public static function drop(array $config, string $name): void
    {
        self::validate($config, $name);
        connectDatabase($config)->exec("DROP DATABASE $name");
    }

    private static function validate(array $config, string $name): void
    {
        if ($config['environment'] !== 'dev' || $config['driver'] !== 'mysql'
            || !preg_match('/^jjtest_[a-f0-9]{10}$/D', $name)) {
            throw new RuntimeException('Test databases require dev, MariaDB, and a generated jjtest_<10 hex digits> name.');
        }
    }
}
