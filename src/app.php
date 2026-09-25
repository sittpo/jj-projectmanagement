<?php
declare(strict_types=1);
$config = require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/Schema.php';
require_once __DIR__ . '/UserRepository.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__.'/MfaService.php';
require_once __DIR__ . '/DashboardReport.php';
require_once __DIR__.'/Access.php';
require_once __DIR__.'/ProjectRepository.php';
require_once __DIR__.'/SubtaskRepository.php';
require_once __DIR__.'/SmtpSettings.php';
require_once __DIR__.'/ReminderService.php';
$db = connectDatabase($config);
$project = new ProjectRepository($db);
$users = new UserRepository($db);
