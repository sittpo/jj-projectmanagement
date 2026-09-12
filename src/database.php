<?php
declare(strict_types=1);

function connectDatabase(array $config): PDO
{
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
    if ($config['driver'] === 'sqlite') {
        $path = $config['database'];
        if ($path === '') {
            throw new RuntimeException('DB_DATABASE is required.');
        }
        if (!str_starts_with($path, '/') && !preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
            $path = $config['root'] . '/' . $path;
        }
        $pdo = new PDO('sqlite:' . $path, null, null, $options);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        return $pdo;
    }
    if ($config['driver'] !== 'mysql') {
        throw new RuntimeException('Unsupported database driver.');
    }
    $options[PDO::ATTR_EMULATE_PREPARES] = false;
    return new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['database']),
        $config['username'],
        $config['password'],
        $options,
    );
}
