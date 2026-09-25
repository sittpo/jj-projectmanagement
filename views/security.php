<div class="page-heading"><div><div class="eyebrow">YOUR ACCOUNT</div><h1>Account security</h1><p>Add an authenticator app as a second step when you sign in.</p></div></div>
<section class="panel form-panel"><div class="panel-heading"><h2>Multi-factor authentication</h2><span class="badge <?= $user['mfa_enabled']?'positive':'warning' ?>"><?= $user['mfa_enabled']?'Enabled':'Not enabled' ?></span></div>
<div class="user-form">
<?php if($recoveryCodes): ?>
<div class="notice success" role="status">Store these recovery codes somewhere safe. Each works once in place of an authenticator code. They will not be shown again.</div>
<ul class="recovery-codes"><?php foreach($recoveryCodes as $code): ?><li><code><?= e($code) ?></code></li><?php endforeach; ?></ul>
<?php endif; ?>
<?php if($user['mfa_enabled']): ?>
<p>Your account is protected by an authenticator code at sign-in.</p><p class="field-help">If you lose access to your app, use a recovery code. Administrators can reset MFA; Project Managers can reset it for contractors.</p>
<?php elseif($setup): ?>
<p>In Google Authenticator or Microsoft Authenticator, add an account by scanning this QR code. In Microsoft Authenticator, select “Other account”.</p>
<img class="mfa-qr" src="<?= e($setup['qr']) ?>" width="260" height="260" alt="Scan this QR code with your authenticator app">
<details><summary>Cannot scan? Enter the setup key manually</summary><p><code class="mfa-secret"><?= e($setup['secret']) ?></code></p><p class="field-help">Time-based code, 6 digits, 30 seconds. Keep this key private.</p></details>
<form method="post" class="form-stack" action="<?= e(url('security')) ?>"><?= csrf() ?><input type="hidden" name="action" value="enable"><label>Authenticator code<input name="code" aria-label="Authenticator code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></label><button class="button primary">Enable MFA</button></form>
<form method="post" class="form-actions" action="<?= e(url('security')) ?>"><?= csrf() ?><button class="button" name="action" value="cancel">Cancel setup</button></form>
<?php else: ?>
<p>Use Google Authenticator, Microsoft Authenticator, or another compatible authenticator app. Confirm a code to activate MFA, then save your recovery codes.</p>
<form method="post" class="form-stack" action="<?= e(url('security')) ?>"><?= csrf() ?><input type="hidden" name="action" value="start"><label>Current password<input type="password" name="password" autocomplete="current-password" maxlength="72" required></label><button class="button primary">Set up MFA</button></form>
<?php endif; ?>
</div></section>
