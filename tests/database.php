<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
require dirname(__DIR__) . '/src/database.php';
require __DIR__ . '/DatabaseSandbox.php';
$config = require dirname(__DIR__) . '/src/bootstrap.php';
try {
    if (($argv[1] ?? '') === 'driver') { echo $config['driver']; exit; }
    $name = $argv[2] ?? '';
    match ($argv[1] ?? '') {
        'create' => DatabaseSandbox::create($config, $name),
        'drop' => DatabaseSandbox::drop($config, $name),
        default => throw new RuntimeException('Specify create or drop, followed by the generated test database name.'),
    };
} catch (Throwable $exception) { fwrite(STDERR, $exception->getMessage() . "\n"); exit(1); }
