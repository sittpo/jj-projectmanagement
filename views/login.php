<div class="login-page">
    <section class="login-story">
        <a class="brand" href="/"><span class="brand-mark">JJ<span></span></span><span>Project Management<small>STORE ROLLOUT</small></span></a>
        <div class="login-intro"><span class="eyebrow">EVERY STORE. EVERY DETAIL.</span><h1>A clear view of<br>your rollout.</h1><p>One workspace for your teams, store upgrades, and the details that keep everything moving.</p><div class="login-features"><span><?= icon('network') ?>Connected teams</span><span><?= icon('check') ?>Confident delivery</span></div></div>
        <span class="login-caption">Networking · Audio · Video surveillance · Rack cabinets</span>
    </section>
    <section class="login-form-area">
        <button class="icon-button theme-toggle login-theme" aria-label="Switch color theme"><?= icon('moon') ?></button>
        <div class="login-form-wrap"><span class="pill"><?= e(strtoupper($config['environment'])) ?> WORKSPACE</span><h2>Welcome back</h2><p class="muted">Sign in to your project workspace.</p>
        <?php if ($error): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="<?= e(url('login')) ?>" class="form-stack">
            <?= csrf() ?>
            <label>Username<input name="username" autocomplete="username" required maxlength="80" value="<?= e($_POST['username'] ?? '') ?>" autofocus></label>
            <label>Password<input type="password" name="password" autocomplete="current-password" required maxlength="72"></label>
            <button class="button primary full-width" type="submit">Sign in <?= icon('arrow') ?></button>
        </form>
        <p class="login-help">Need access? Contact your project administrator.</p></div>
    </section>
</div>
