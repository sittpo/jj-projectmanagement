<?php
declare(strict_types=1);
$config = require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/Schema.php';
require_once __DIR__ . '/UserRepository.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/DashboardReport.php';
$db = connectDatabase($config);
$users = new UserRepository($db);
