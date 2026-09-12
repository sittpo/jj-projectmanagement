<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
try {
    require dirname(__DIR__) . '/src/app.php';
    require dirname(__DIR__) . '/src/DataTransfer.php';
    $command = $argv[1] ?? 'help';
    if ($command === 'migrate') {
        Schema::migrate($db);
        echo 'Schema is up to date (version ' . Schema::VERSION . ").\n";
    } elseif ($command === 'seed-admin') {
        if ($config['environment'] !== 'dev') { throw new RuntimeException('The demo administrator can only be created in dev.'); }
        if ($users->byUsername('admin')) { throw new RuntimeException('The admin username already exists. Use user management to edit it.'); }
        $password = getenv('DEV_ADMIN_PASSWORD');
        if (!$password) { throw new RuntimeException('Set DEV_ADMIN_PASSWORD for this command only.'); }
        $users->save(['username' => 'admin', 'display_name' => 'Administrator', 'role' => 'admin', 'active' => 1, 'password' => $password], null, '');
        echo "Development administrator created.\n";
    } elseif ($command === 'data:export') {
        $path = $argv[2] ?? throw new RuntimeException('Specify an output JSON file outside public/.');
        $public = realpath(dirname(__DIR__) . '/public');
        $directory = realpath(dirname($path));
        if (!$directory || str_starts_with(strtolower($directory . DIRECTORY_SEPARATOR), strtolower($public . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('Choose an existing output directory outside public/.');
        }
        (new DataTransfer($db))->export($path);
        echo "Data exported.\n";
    } elseif ($command === 'data:import') {
        if ($config['environment'] !== 'dev' || !in_array('--replace', $argv, true)) {
            throw new RuntimeException('Import requires APP_ENV=dev and --replace.');
        }
        $path = $argv[2] ?? throw new RuntimeException('Specify an input JSON file.');
        if (!is_file($path)) { throw new RuntimeException('Input file not found.'); }
        $transfer = new DataTransfer($db);
        $backup = dirname(__DIR__) . '/storage/before-import-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.json';
        $transfer->export($backup);
        $transfer->import($path, $config['environment']);
        echo "Imported successfully. Previous data saved to $backup\n";
    } else {
        echo "Commands: migrate | seed-admin | data:export <file> | data:import <file> --replace\n";
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
