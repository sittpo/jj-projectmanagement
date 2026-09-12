<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/database.php';
require dirname(__DIR__) . '/src/Schema.php';
require dirname(__DIR__) . '/src/UserRepository.php';
require dirname(__DIR__) . '/src/DataTransfer.php';
require dirname(__DIR__) . '/src/ProjectRepository.php';
function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
    echo "PASS: $message\n";
}
function rejects(callable $callback, string $message): void {
    try { $callback(); } catch (Throwable) { check(true, $message); return; }
    throw new RuntimeException("Expected rejection: $message");
}
$testConfig = require dirname(__DIR__) . '/src/bootstrap.php';
$useMariaDb = getenv('TEST_MARIADB') === '1' || (getenv('TEST_MARIADB') !== '0' && $testConfig['driver'] === 'mysql');
if ($useMariaDb) {
    require __DIR__ . '/DatabaseSandbox.php';
    $testName = 'jjtest_' . bin2hex(random_bytes(5));
    $db = DatabaseSandbox::create($testConfig, $testName);
    register_shutdown_function(static function () use ($testConfig, $testName): void {
        DatabaseSandbox::drop($testConfig, $testName);
    });
    echo 'Testing MariaDB ' . $db->query('SELECT VERSION()')->fetchColumn() . "\n";
} else {
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys=ON');
}
Schema::migrate($db);
Schema::migrate($db);
$users = new UserRepository($db);
$password = bin2hex(random_bytes(12));
$users->save(['username'=>'admin','display_name'=>'Administrator','role'=>'admin','active'=>1,'password'=>$password], null, '');
$admin = $users->byUsername('ADMIN');
check($admin !== null && password_verify($password, $admin['password_hash']), 'Passwords are hashed and usernames normalized');
rejects(fn() => $users->save(['username'=>'ADMIN','display_name'=>'Duplicate','role'=>'admin','active'=>1,'password'=>$password], null, ''), 'Duplicate usernames rejected');
rejects(fn() => $users->save(['username'=>'admin','display_name'=>'Administrator','role'=>'contractor','active'=>1], $admin['id'], $admin['id']), 'Administrator cannot remove own access');
rejects(fn() => $users->save(['username'=>'admin','display_name'=>'Administrator','role'=>'admin'], $admin['id'], $admin['id']), 'Administrator cannot deactivate self');
rejects(fn() => $users->save(['username'=>'other','display_name'=>'Other','role'=>'owner','active'=>1,'password'=>$password], null, ''), 'Invalid roles rejected');
$users->save(['username'=>'worker','display_name'=>'Worker','role'=>'contractor','active'=>1,'password'=>$password], null, $admin['id']);
$worker = $users->byUsername('worker');
$users->save(['username'=>'worker','display_name'=>'Worker','role'=>'contractor','password'=>''], $worker['id'], $admin['id']);
$updated = $users->find($worker['id']);
check((int)$updated['active'] === 0 && (int)$updated['session_version'] > (int)$worker['session_version'], 'Deactivation invalidates existing sessions');
check($updated['password_hash'] === $worker['password_hash'], 'Blank password preserves existing password');
$store = Schema::id();
$db->prepare('INSERT INTO stores (id,code,name,city,created_at) VALUES (?,?,?,?,?)')->execute([$store,'TEST-01','Test Store','Test City',gmdate('Y-m-d\TH:i:s\Z')]);
$db->prepare('INSERT INTO store_assignments (store_id,user_id) VALUES (?,?)')->execute([$store,$worker['id']]);
$task = Schema::id();
$db->prepare('INSERT INTO tasks (id,store_id,category,status,created_at) VALUES (?,?,?,?,?)')->execute([$task,$store,'network','completed',gmdate('Y-m-d\TH:i:s\Z')]);
$photo = Schema::id();
$db->prepare('INSERT INTO task_photos (id,task_id,uploaded_by,storage_key,original_name,mime_type,created_at) VALUES (?,?,?,?,?,?,?)')->execute([$photo,$task,$worker['id'],'test/photo.jpg','photo.jpg','image/jpeg',gmdate('Y-m-d\TH:i:s\Z')]);
$export = dirname(__DIR__) . '/storage/test-export-' . bin2hex(random_bytes(5)) . '.json';
$invalid = $export . '.invalid';
try {
    (new ProjectRepository($db))->saveStorePreference($admin['id'],true);
    $db->prepare("INSERT INTO store_prerequisites(store_id,prerequisite_id,status_id) VALUES(?,'unifi','shipped')")->execute([$store]);
    $transfer = new DataTransfer($db);
    $transfer->export($export);
    rejects(fn() => $transfer->import($export, 'production'), 'Import into production rejected');
    $db->exec("UPDATE stores SET name='Changed'");
    $transfer->import($export, 'dev');
    check($db->query('SELECT name FROM stores')->fetchColumn() === 'Test Store', 'Data round trip restores store data');
    check((new ProjectRepository($db))->showAllStores($admin['id']), 'Account preferences survive data transfer');
    check($db->query('SELECT task_id FROM task_photos')->fetchColumn() === $task, 'Stable IDs and relationships survive export/import');
    check((int)$users->find($admin['id'])['session_version'] !== (int)$admin['session_version'], 'Import invalidates old sessions');

    if ($useMariaDb) {
        $sqlite = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $sqlite->exec('PRAGMA foreign_keys=ON');
        Schema::migrate($sqlite);
        (new DataTransfer($sqlite))->import($export, 'dev');
        check($sqlite->query('SELECT task_id FROM task_photos')->fetchColumn() === $task, 'MariaDB-to-SQLite import preserves relationships');
        check((new ProjectRepository($sqlite))->showAllStores($admin['id']), 'Account preferences transfer to SQLite');
        check($sqlite->query('SELECT status_id FROM store_prerequisites')->fetchColumn()==='shipped', 'Prerequisite selections transfer to SQLite');
        $crossExport = $export . '.cross';
        try {
            (new DataTransfer($sqlite))->export($crossExport);
            $transfer->import($crossExport, 'dev');
            check($db->query('SELECT task_id FROM task_photos')->fetchColumn() === $task, 'SQLite-to-MariaDB import preserves relationships');
            check($db->query('SELECT status_id FROM store_prerequisites')->fetchColumn()==='shipped', 'Prerequisite selections round trip to MariaDB');
        } finally { if (is_file($crossExport)) { unlink($crossExport); } }
    }
    $data = json_decode(file_get_contents($export), true);
    $data['tables']['task_photos'][0]['task_id'] = 'missing-task';
    file_put_contents($invalid, json_encode($data));
    rejects(fn() => $transfer->import($invalid, 'dev'), 'Invalid foreign keys reject import');
    check($db->query('SELECT task_id FROM task_photos')->fetchColumn() === $task, 'Failed import rolls back all changes');
} finally {
    if (is_file($export)) { unlink($export); }
    if (is_file($invalid)) { unlink($invalid); }
}
echo "All repository and transfer tests passed.\n";
