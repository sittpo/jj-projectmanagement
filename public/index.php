<?php
declare(strict_types=1);
if (!in_array(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), ['/', '/index.php'], true)) {
    http_response_code(404); exit('Not found');
}
if ((int)($_SERVER['CONTENT_LENGTH']??0)>64*1024*1024) { http_response_code(413);exit('Upload too large. Use up to 6 photos of 10 MB each.'); }
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
header('Referrer-Policy: same-origin');
ini_set('session.use_strict_mode', '1');
session_name('jj_session');
session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Lax', 'path' => '/']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
require dirname(__DIR__) . '/src/view.php';
try {
    require dirname(__DIR__) . '/src/app.php';
    $auth = new Auth($db, $users);
    $user = $auth->user();
} catch (Throwable $exception) {
    error_log((string) $exception);
    http_response_code(503); exit('The application is unavailable. Please try again shortly.');
}
$page = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'dashboard';
if (!in_array($page, ['dashboard','login','logout','users','user-edit','report-export','stores','store','store-edit','companies','company-edit','templates','template-edit','settings','smtp','photo','step-update','store-report','team'], true)) {
    http_response_code(404); exit('Not found');
}
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($isPost && (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf']))) {
    http_response_code(403); exit('Your session has changed. Refresh the page and try again.');
}
if (!$user && $page !== 'login') { redirect('login'); }
if ($user && $page === 'login') { redirect('dashboard'); }
if (in_array($page, ['users','user-edit'], true) && $user['role'] !== 'admin') {
    http_response_code(403);
    $page = 'forbidden';
}
if ($page === 'report-export' && !in_array($user['role'], ['admin','pm'], true)) {
    http_response_code(403); $page = 'forbidden';
}
$error = $_SESSION['flash_error']??null;
unset($_SESSION['flash_error']);
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
if ($page === 'login' && $isPost) {
    try {
        if ($auth->login((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''), $_SERVER['REMOTE_ADDR'] ?? 'unknown')) {
            redirect('dashboard');
        }
        $error = 'The username or password is incorrect.';
    } catch (DomainException $exception) { $error = $exception->getMessage(); }
}
if ($page === 'logout') {
    if (!$isPost) { http_response_code(405); header('Allow: POST'); exit('Use the sign-out button.'); }
    $auth->logout(); redirect('login');
}
$editing = null;
if ($page === 'user-edit') {
    $id = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : null;
    $editing = $id ? $users->find($id) : null;
    if ($id && !$editing) { http_response_code(404); exit('User not found.'); }
    if ($isPost) {
        try {
            $users->save($_POST, $id, $user['id']);
            if ($id === $user['id']) {
                $_SESSION['user_version'] = (int) $users->find($id)['session_version'];
                session_regenerate_id(true);
            }
            $_SESSION['flash'] = $id ? 'User updated.' : 'User created.';
            redirect('users');
        } catch (DomainException $exception) {
            $error = $exception->getMessage();
        } catch (PDOException $exception) {
            error_log((string) $exception);
            $error = 'The user could not be saved. Check for a duplicate username and try again.';
        }
        $editing = ['id' => $id, 'username' => $_POST['username'] ?? '', 'display_name' => $_POST['display_name'] ?? '',
            'email' => $_POST['email'] ?? '', 'role' => $_POST['role'] ?? 'contractor', 'active' => isset($_POST['active']) ? 1 : 0, 'company_id'=>$_POST['company_id']??null];
    }
}
if ($page === 'report-export') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sample-rollout-summary.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Data source', 'Metric', 'Value', 'Unit', 'Note'], ',', '"', '');
    foreach (DashboardReport::sample()['metrics'] as $metric) {
        fputcsv($output, ['Sample data', $metric['label'], $metric['value'], $metric['unit'], $metric['trend']], ',', '"', '');
    }
    fclose($output); exit;
}
require dirname(__DIR__).'/src/project_routes.php';
$title = match ($page) { 'login' => 'Sign in', 'users' => 'Users', 'user-edit' => isset($editing['id']) ? 'Edit user' : 'Create user', 'forbidden' => 'Access restricted', 'stores'=>'Stores','store'=>$store['name']??'Store','store-edit'=>'Store details','companies'=>'Contracting companies','company-edit'=>'Company details','templates'=>'Task templates','template-edit'=>'Subtask template','settings'=>'Reminder schedule','smtp'=>'SMTP connector','team'=>'Company team','step-error'=>'Step update', default => 'Dashboard' };
require dirname(__DIR__) . '/views/layout.php';
