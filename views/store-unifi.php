<?php
$prerequisiteStore=$store+['finished'=>0];
foreach($project->storeListing($user,true,$store['id']) as $candidate)if($candidate['id']===$store['id']){$prerequisiteStore=$candidate;break;}
$prerequisiteToday=(new DateTimeImmutable('now',new DateTimeZone('Europe/Copenhagen')))->format('Y-m-d');
$storePrerequisites=(new PrerequisiteRepository($project))->forStore($prerequisiteStore);
?>
<section class="panel prerequisites-panel" aria-labelledby="prerequisites-title">
<div class="panel-heading"><h2 id="prerequisites-title">Prerequisites</h2></div>
<ul class="prerequisites-list"><?php foreach($storePrerequisites as $item): $issue=PrerequisiteRepository::issue($prerequisiteStore,$item,$prerequisiteToday); ?><li class="prerequisite-item">
<?php if(Access::atLeast($user,'pm')): ?>
<form method="post" action="<?= e(url('store',['id'=>$store['id']])) ?>" class="unifi-order-form"><?= csrf() ?><input type="hidden" name="action" value="prerequisite-status"><input type="hidden" name="prerequisite_id" value="<?= e($item['id']) ?>">
<label for="prerequisite-<?= e($item['id']) ?>"><?= e($item['name']) ?></label><select id="prerequisite-<?= e($item['id']) ?>" name="status_id"><?php foreach($item['statuses'] as $status): ?><option value="<?= e($status['id']) ?>" <?= $item['status']['id']===$status['id']?'selected':'' ?>><?= e($status['name']) ?></option><?php endforeach; ?></select><span class="unifi-feedback field-help" aria-live="polite"></span><noscript><button class="button">Save status</button></noscript>
</form>
<?php else: ?>
<div class="prerequisite-status"><span class="prerequisite-dot <?= $issue?'needs-attention':'good-to-go' ?>" aria-hidden="true"></span><div><strong><?= e($item['name']) ?></strong><p><?= e($item['status']['name']) ?> · <?= $issue?'Needs attention':'Good to go' ?></p><?php if($issue): ?><small class="field-help"><?= e($issue) ?></small><?php endif; ?></div></div>
<?php endif; ?></li><?php endforeach; ?></ul>
<?php if(!$storePrerequisites): ?><p class="empty-search">No prerequisites configured.</p><?php endif; ?></section>
