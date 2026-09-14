<div class="page-heading"><div><div class="eyebrow">ROLLOUT OVERVIEW</div><h1>Recent activity</h1><p>Recorded step changes, Project Manager notes, and store creations.</p></div><a class="button" href="<?= e(url('dashboard')) ?>">Back to dashboard</a></div>
<section class="panel">
<div class="panel-heading"><h2><?= e($activityPage['total']) ?> activities</h2>
<form method="get" action="/index.php" class="activity-page-size"><input type="hidden" name="page" value="activity"><label>Activities per page <select name="per_page"><?php foreach([10,50,100] as $size): ?><option value="<?= $size ?>" <?= $activityPage['per_page']===$size?'selected':'' ?>><?= $size ?></option><?php endforeach; ?></select></label><noscript><button class="button">Apply</button></noscript></form></div>
<div class="activity-list">
<?php foreach($activityPage['rows'] as $activity): ?>
<a class="activity" href="<?= e(url('store',['id'=>$activity['store_id']])) ?>"><span class="activity-icon"><?= icon('clock') ?></span><div><strong><?= e(match($activity['action']){'template-added'=>'Step added: '.$activity['title'],'save'=>'Step updated: '.$activity['title'],'signoff'=>'Step signed off: '.$activity['title'],'reopen'=>'Step reopened: '.$activity['title'],default=>$activity['title']}) ?></strong><p><?= e($activity['code'].' · '.$activity['store_name'].($activity['actor_name']?' · '.$activity['actor_name']:'')) ?></p><small><?= e((new DateTimeImmutable($activity['created_at']))->setTimezone(new DateTimeZone('Europe/Copenhagen'))->format('Y-m-d H:i:s T')) ?></small></div></a>
<?php endforeach; ?>
<?php if(!$activityPage['rows']): ?><p class="empty-search">No activity recorded yet.</p><?php endif; ?>
</div>
<nav class="activity-pagination" aria-label="Activity pages">
<?php if($activityPage['page']>1): ?><a class="button" href="<?= e(url('activity',['per_page'=>$activityPage['per_page'],'p'=>$activityPage['page']-1])) ?>">Previous</a><?php endif; ?>
<span>Page <?= $activityPage['page'] ?> of <?= $activityPage['pages'] ?></span>
<form method="get" action="/index.php"><input type="hidden" name="page" value="activity"><input type="hidden" name="per_page" value="<?= $activityPage['per_page'] ?>"><label>Go to page <input type="number" name="p" min="1" max="<?= $activityPage['pages'] ?>" value="<?= $activityPage['page'] ?>" required></label><button class="button">Go</button></form>
<?php if($activityPage['page']<$activityPage['pages']): ?><a class="button" href="<?= e(url('activity',['per_page'=>$activityPage['per_page'],'p'=>$activityPage['page']+1])) ?>">Next</a><?php endif; ?>
</nav></section>
