<?php $emailTemplate=(new ReminderTemplate($project))->read(); if($isPost&&in_array($_POST['action']??'',['save-email','test-email'],true))$emailTemplate=['subject'=>is_string($_POST['subject']??null)?$_POST['subject']:'','body'=>is_string($_POST['body']??null)?$_POST['body']:'']; ?>
<section class="panel form-panel reminder-email-panel">
<div class="panel-heading"><div><h2>Reminder email</h2><p>Edit the message used for scheduled and manual store reminders.</p></div></div>
<form class="user-form reminder-email-form" method="post" action="<?= e(url('settings')) ?>">
<?= csrf() ?>
<label for="reminder-subject">Subject</label><input id="reminder-subject" name="subject" maxlength="200" required value="<?= e($emailTemplate['subject']) ?>">
<label for="reminder-body">Message</label><textarea id="reminder-body" name="body" rows="10" maxlength="10000" required><?= e($emailTemplate['body']) ?></textarea>
<p class="field-help">Plain text; line breaks are preserved. Insert these placeholders where store details should appear:</p>
<p class="field-help"><?php foreach(ReminderTemplate::FIELDS as $field=>$sample): ?><code>{{<?= e($field) ?>}}</code> <?php endforeach ?></p>
<p class="field-help">Missing optional store details appear blank. Saving applies to future emails.</p>
<div class="form-actions"><button class="button primary" name="action" value="save-email">Save reminder email</button></div>
<h3>Send a test email</h3>
<p class="field-help">Sends the current draft with example store details to this address only, using the saved SMTP connector. Does not save the draft or add a store reminder log.</p>
<label>Test recipient<input type="email" name="test_recipient" maxlength="254" value="<?= e(is_string($_POST['test_recipient']??null)?$_POST['test_recipient']:'') ?>" placeholder="you@example.com"></label>
<div class="form-actions"><button class="button secondary" name="action" value="test-email">Send test reminder</button></div>
</form>
</section>


