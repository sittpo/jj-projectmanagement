<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title><?= e($title) ?> · Rollout Management</title><?php require __DIR__ . '/icons.php'; ?>
    <link rel="stylesheet" href="/assets/app.css">
    <script src="/assets/app.js" defer></script>
</head>
<body>
<?php if (in_array($page,['login','mfa-login'],true)): ?>
    <?php require __DIR__ . ($page==='login'?'/login.php':'/mfa-login.php'); ?>
<?php else: ?>
<?php require __DIR__ . "/navigation.php"; ?>
<aside class="sidebar" id="navigation">
    <a class="brand" href="<?= e(url('dashboard')) ?>"><span class="brand-mark"><img src="/assets/serenit-s.svg" width="19" height="23" alt=""></span><span>Rollout Management<small>STORE ROLLOUT</small></span></a>
    <nav aria-label="Main navigation">
        <p class="nav-heading">Workspace</p>
        <a class="nav-link <?= $navigationRoot === 'dashboard' ? 'selected' : '' ?>" <?= $navigationRoot === 'dashboard' ? 'aria-current="page"' : '' ?> href="<?= e(url('dashboard')) ?>"><?= icon('grid') ?>Dashboard</a>
        <a class="nav-link <?= $navigationRoot==='stores'?'selected':'' ?>" <?= $navigationRoot==='stores'?'aria-current="page"':'' ?> href="<?= e(url('stores')) ?>"><?= icon('store') ?>Stores</a>
        <?php if(Access::atLeast($user,'contractor_admin')): ?><a class="nav-link <?= $page==='team'?'selected':'' ?>" href="<?= e(url('team')) ?>"><?= icon('team') ?>Company team</a><?php endif; ?>
        <?php if(Access::atLeast($user,'pm')): ?>
        <p class="nav-heading">Project management</p>
        <?php foreach(['prerequisites'=>['Prerequisites','check'],'templates'=>['Task templates','tasks'],'companies'=>['Contracting companies','building'],'settings'=>['Reminder schedule','calendar']] as $route=>$nav): ?><a class="nav-link <?= $navigationRoot===$route?'selected':'' ?>" <?= $navigationRoot===$route?'aria-current="page"':'' ?> href="<?= e(url($route)) ?>"><?= icon($nav[1]) ?><?= e($nav[0]) ?></a><?php endforeach; ?>
        <?php endif; ?>
        <?php if ($user['role'] === 'pm'): ?><p class="nav-heading">User management</p><a class="nav-link <?= $navigationRoot==='users'?'selected':'' ?>" href="<?= e(url('users')) ?>"><?= icon('users') ?>Users</a><?php endif; ?>
        <?php if ($user['role'] === 'admin'): ?>
        <p class="nav-heading">Administration</p><a class="nav-link <?= $page==='store-import'?'selected':'' ?>" href="<?= e(url('store-import')) ?>"><?= icon('upload') ?>Import stores</a>
        <a class="nav-link <?= $navigationRoot==='users' ? 'selected' : '' ?>" <?= $navigationRoot==='users' ? 'aria-current="page"' : '' ?> href="<?= e(url('users')) ?>"><?= icon('users') ?>Users</a>
        <a class="nav-link <?= $page==='smtp'?'selected':'' ?>" href="<?= e(url('smtp')) ?>"><?= icon('network') ?>SMTP connector</a>
        <?php endif; ?>
    </nav>
    <div class="sidebar-bottom"><div class="environment-dot"></div><?= e(ucfirst($config['environment'])) ?> environment <span class="version">v0.3</span></div>
</aside>
<button class="nav-overlay" aria-label="Close navigation" tabindex="-1"></button>
<div class="workspace">
<header class="topbar">
    <div class="breadcrumb"><button class="icon-button menu-toggle" aria-label="Open navigation" aria-expanded="false" aria-controls="navigation"><?= icon('menu') ?></button><nav aria-label="Breadcrumb"><ol><?php foreach($breadcrumbs as $crumb): ?><li><?php if($crumb['href']!==null): ?><a href="<?= e($crumb['href']) ?>"><?= e($crumb['label']) ?></a><?php else: ?><strong aria-current="page"><?= e($crumb['label']) ?></strong><?php endif; ?></li><?php endforeach; ?></ol></nav></div>
    <div class="topbar-actions">
        <button class="icon-button theme-toggle" aria-label="Switch color theme" title="Switch color theme"><?= icon('moon') ?></button>
        <span class="topbar-divider"></span>
        <a class="account-security-link" href="<?= e(url('security')) ?>" title="<?= $user['mfa_enabled']?'Manage account security':'Set up MFA to protect your account' ?>" aria-label="<?= $user['mfa_enabled']?'Account security':'Account security — set up MFA' ?>"><span class="user-avatar"><?= e(strtoupper(mb_substr($user['display_name'], 0, 1))) ?></span><?php if(!$user['mfa_enabled']): ?><span class="mfa-notice" aria-hidden="true">!</span><?php endif; ?></a>
        <span class="user-label"><?= e($user['display_name']) ?><small><?= e(Access::label($user['role'])) ?></small></span>
        <form method="post" action="<?= e(url('logout')) ?>"><?= csrf() ?><button class="icon-button" aria-label="Sign out" title="Sign out"><?= icon('logout') ?></button></form>
    </div>
</header>
<main class="main-content" id="main">
    <?php if ($flash): ?><div class="notice success" role="status"><?= icon('check') ?><?= e($flash) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if ($page === 'forbidden'): ?>
    <div class="panel empty-state"><?= icon('shield') ?><h1>Access restricted</h1><p>You do not have permission to view this section.</p><a class="button primary" href="<?= e(url('dashboard')) ?>">Back to dashboard</a></div>
    <?php else: require __DIR__ . '/' . match ($page) { 'security'=>'security', 'attention'=>'attention', 'store-import'=>'store-import', 'prerequisites'=>'prerequisites', 'activity'=>'activity', 'users' => 'users', 'user-edit' => 'user-form', 'stores'=>'stores','store'=>'store','store-edit'=>'store-edit','companies'=>'companies','company-edit'=>'company-edit','templates'=>'templates','template-edit'=>'template-edit','settings'=>'settings','smtp'=>'smtp','team'=>'team','step-error'=>'step-error', default => 'dashboard' } . '.php'; endif; ?>
    <footer class="page-footer"><span>Rollout Management</span><span>Built for a connected rollout.</span></footer>
</main>
</div>
<?php endif; ?>
</body>
</html>
