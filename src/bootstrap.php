<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$local = is_file($root . '/.env') ? parse_ini_file($root . '/.env', false, INI_SCANNER_RAW) : [];
if ($local === false) {
    throw new RuntimeException('Invalid environment configuration.');
}
$env = static function (string $key, string $default = '') use ($local): string {
    $value = getenv($key);
    return $value !== false ? $value : ($local[$key] ?? $default);
};

return [
    'environment' => $env('APP_ENV', 'production'),
    'driver' => $env('DB_CONNECTION', 'mysql'),
    'database' => $env('DB_DATABASE'),
    'host' => $env('DB_HOST', '127.0.0.1'),
    'port' => $env('DB_PORT', '3306'),
    'username' => $env('DB_USERNAME'),
    'password' => $env('DB_PASSWORD'),
    'root' => $root,
];
