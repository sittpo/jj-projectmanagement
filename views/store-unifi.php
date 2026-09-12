<?php
$prerequisiteStore=$store+['finished'=>0];
foreach($project->storeListing($user,true,$store['id']) as $candidate){
    if($candidate['id']===$store['id']){$prerequisiteStore=$candidate;break;}
}
$prerequisiteToday=(new DateTimeImmutable('now',new DateTimeZone('Europe/Copenhagen')))->format('Y-m-d');
$prerequisiteIssue=ProjectRepository::unifiAttention($prerequisiteStore,$prerequisiteToday);
if(!$store['target_date']&&!$prerequisiteStore['finished']&&$store['unifi_order']!=='delivered')$prerequisiteIssue='Installation date needed to assess hardware readiness.';
?>
<section class="panel prerequisites-panel" aria-labelledby="prerequisites-title">
<div class="panel-heading"><h2 id="prerequisites-title">Prerequisites</h2></div>
<ul class="prerequisites-list"><li class="prerequisite-item">
<?php if(Access::atLeast($user,'pm')): ?>
<form method="post" action="<?= e(url('store',['id'=>$store['id']])) ?>" class="unifi-order-form"><?= csrf() ?><input type="hidden" name="action" value="unifi-order">
<label for="unifi-order">UniFi order</label><select id="unifi-order" name="unifi_order"><?php foreach(ProjectRepository::UNIFI_STATES as $value=>$label): ?><option value="<?= e($value) ?>" <?= $store['unifi_order']===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select><span class="unifi-feedback field-help" aria-live="polite"></span><noscript><button class="button">Save order state</button></noscript>
</form>
<?php else: ?>
<div class="prerequisite-status"><span class="prerequisite-dot <?= $prerequisiteIssue?'needs-attention':'good-to-go' ?>" aria-hidden="true"></span><div><strong>UniFi order</strong><p><?= e(ProjectRepository::UNIFI_STATES[$store['unifi_order']]) ?> · <?= $prerequisiteIssue?'Needs attention':'Good to go' ?></p><?php if($prerequisiteIssue): ?><small class="field-help"><?= e($prerequisiteIssue) ?></small><?php endif; ?></div></div>
<?php endif; ?>
</li></ul></section>
