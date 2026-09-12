<?php
declare(strict_types=1);

if (!in_array(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), ['/', '/index.php'], true)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
$environment = 'production';
$connected = false;
try {
    $config = require dirname(__DIR__) . '/src/bootstrap.php';
    require dirname(__DIR__) . '/src/database.php';
    $environment = $config['environment'];
    $pdo = connectDatabase($config);
    $connected = (int) $pdo->query('SELECT 1')->fetchColumn() === 1;
} catch (Throwable $exception) {
    error_log((string) $exception);
}
if (!$connected) {
    http_response_code(503);
}
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>JJ Project Management</title>
    <style>
        :root { color-scheme: light dark; font-family: system-ui, sans-serif; }
        body { margin: 0; min-height: 100svh; display: grid; place-items: center; background: light-dark(#f3f5f8, #10141d); color: light-dark(#182234, #eef2fa); }
        main { margin: 24px; padding: clamp(24px, 6vw, 56px); max-width: 540px; border: 1px solid light-dark(#dce2eb, #30394a); border-radius: 20px; background: light-dark(#fff, #181e2a); }
        small { color: light-dark(#596579, #a6b3c9); letter-spacing: .08em; }
        h1 { font-size: clamp(32px, 7vw, 48px); margin: 20px 0; }
        p { line-height: 1.6; }
        code { color: light-dark(#275cbd, #9fc0ff); }
    </style>
</head>
<body>
<main>
    <small>JJ PROJECT MANAGEMENT</small>
    <h1><?= 'Hello World' ?></h1>
    <p>Environment: <code><?= $escape($environment) ?></code></p>
    <p>Database: <strong><?= $connected ? 'Connected' : 'Unavailable' ?></strong></p>
    <?php if ($environment === 'dev' && $connected): ?>
        <p>PHP <?= $escape(PHP_VERSION) ?> · <?= $escape($config['driver']) ?> · Connection verified with <code>SELECT 1</code>.</p>
    <?php endif; ?>
</main>
</body>
</html>
