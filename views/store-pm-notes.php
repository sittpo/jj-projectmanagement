<?php if(Access::atLeast($user,'pm')): ?>
<section class="panel pm-notes-panel" aria-labelledby="pm-notes-title">
<div class="panel-heading"><h2 id="pm-notes-title">Project Manager notes</h2></div>
<div class="step-content">
<form method="post" action="<?= e(url('store',['id'=>$store['id']])) ?>" class="step-form">
<?= csrf() ?><input type="hidden" name="action" value="add-pm-note">
<label>New Project Manager note<textarea name="pm_note" rows="3" maxlength="20000" required><?= e(($_POST['action']??'')==='add-pm-note'?($_POST['pm_note']??''):'') ?></textarea></label>
<label class="checkbox-label"><input type="checkbox" name="include_in_report" value="1" <?= (($_POST['action']??'')!=='add-pm-note'||isset($_POST['include_in_report']))?'checked':'' ?>>Include in PDF report</label>
<div class="form-actions"><button class="button primary">Add note</button></div>
</form>
<?php foreach($pmNotes as $pmNote): ?><article class="pm-note">
<p class="field-help"><?= e($pmNote['author_name']) ?> · <time datetime="<?= e($pmNote['created_at']) ?>"><?= e((new DateTimeImmutable($pmNote['created_at']))->setTimezone(new DateTimeZone('Europe/Copenhagen'))->format('Y-m-d H:i:s T')) ?></time></p>
<p class="saved-note"><?= nl2br(e($pmNote['note'])) ?></p>
<form method="post" action="<?= e(url('store',['id'=>$store['id']])) ?>" class="pm-note-report-form"><?= csrf() ?><input type="hidden" name="action" value="pm-note-report"><input type="hidden" name="note_id" value="<?= e($pmNote['id']) ?>"><label class="checkbox-label"><input type="checkbox" name="include_in_report" value="1" <?= $pmNote['include_in_report']?'checked':'' ?>>Include in PDF report</label><button class="button">Save report setting</button></form>
</article><?php endforeach; ?>
<?php if(!$pmNotes): ?><p class="field-help">No Project Manager notes yet.</p><?php endif; ?>
</div></section>
<?php endif; ?>
