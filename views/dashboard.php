<?php $report = Access::atLeast($user,'pm')?DashboardReport::live($project,$user):null; ?>
<div class="page-heading"><div><div class="eyebrow">ROLLOUT OVERVIEW</div><h1>Project dashboard</h1><p>Every store, every workstream. One clear picture.</p></div>
<?php if (Access::atLeast($user,'pm')): ?><a class="button" href="<?= e(url('report-export')) ?>"><?= icon('download') ?>Export summary</a><?php endif; ?></div>
<?php if (!Access::atLeast($user,'pm')): ?>
<div class="panel empty-state"><?= icon('store') ?><h2>Your workspace is ready</h2><p>Open Stores to view your assigned installations and complete their checklists.</p><a class="button primary" href="<?= e(url('stores')) ?>">View stores</a></div>
<?php else: ?>

<section class="metric-grid" aria-label="Rollout statistics">
<?php foreach ($report['metrics'] as $metric): ?>
<article class="metric-card"><div class="metric-top"><span><?= e($metric['label']) ?></span><span class="metric-icon"><?= icon($metric['icon']) ?></span></div><div class="metric-value"><?= e($metric['value']) ?><span><?= e($metric['unit']) ?></span></div><div class="metric-trend"><?= icon('check') ?><?= e($metric['trend']) ?></div></article>
<?php endforeach; ?>
</section>
<div class="overview-grid">
<section class="panel workstreams"><div class="panel-heading"><div><h2>Workstream progress</h2><p>Completed installations across <?= e($report['total']) ?> stores</p></div><span class="pill">4 workstreams</span></div>
<div class="workstream-list">
<?php foreach ($report['workstreams'] as $stream): ?>
<div class="workstream"><span class="stream-icon"><?= icon($stream['icon']) ?></span><div class="stream-body"><div class="stream-label"><strong><?= e($stream['label']) ?></strong><span><b><?= e($stream['complete']) ?></b> / <?= e($stream['total']) ?></span></div><progress value="<?= e($stream['complete']) ?>" max="<?= e(max(1,$stream['total'])) ?>" aria-label="<?= e($stream['label']) ?> completion"></progress></div><span class="stream-percent"><?= ($stream['total']?round($stream['complete'] / $stream['total'] * 100):0) ?>%</span></div>
<?php endforeach; ?>
</div><div class="panel-foot"><?= icon('pulse') ?><span><?= e($report['done']) ?> of <?= e($report['task_total']) ?> installations complete</span><strong><?= $report['task_total']?round($report['done']/$report['task_total']*100):0 ?>%</strong></div>
</section>
<section class="panel attention"><div class="panel-heading"><div><h2><a href="<?= e(url('attention')) ?>">Needs attention <?= icon('arrow') ?></a></h2><p>A few things to keep moving</p></div><span class="count-badge"><?= count($report['attention']) ?></span></div>
<div class="attention-list">
<?php foreach($report['attention'] as $item): ?><a class="attention-item" href="<?= e(url('store-edit',['id'=>$item['id']])) ?>"><span class="attention-icon amber"><?= icon('calendar') ?></span><div><strong><?= e($item['name']) ?></strong><p><?= e($item['code']) ?> · <?= e($item['reason']) ?></p><span class="text-tag">Edit store</span></div></a><?php endforeach; ?>
<?php if(!$report['attention']): ?><p class="empty-search">No stores need attention.</p><?php endif; ?>
</div></section>
</div>
<div class="detail-grid">
<section class="panel visits"><div class="panel-heading"><div><h2>Upcoming store visits</h2><p>The next stops on the rollout</p></div><span class="pill"><?= icon('calendar') ?>Next 6 visits</span></div>
<div class="table-scroll"><table><thead><tr><th>Store</th><th>Contractor</th><th>Visit</th><th>Status</th></tr></thead><tbody>
<?php foreach ($report['visits'] as $visit): ?>
<tr><td><a class="store-name" href="<?= e(url('store',['id'=>$visit['id']])) ?>"><strong><?= e($visit['name']) ?></strong></a><small><?= e($visit['code']) ?> · <?= e($visit['city']) ?></small></td><td><?= e($visit['company_name']?:'Unassigned') ?></td><td class="nowrap"><?= e($visit['target_date']) ?></td><td><span class="badge <?= $visit['target_date']===$report['today']?'positive':'neutral' ?>"><?= $visit['target_date']===$report['today']?'Due today':'Scheduled' ?></span></td></tr>
<?php endforeach; ?>
</tbody></table></div><?php if(!$report['visits']): ?><p class="empty-search">No upcoming store visits.</p><?php endif; ?></section>
<section class="panel"><div class="panel-heading"><div><h2><a href="<?= e(url('activity')) ?>">Recent activity <?= icon('arrow') ?></a></h2><p>Latest updates from the field</p></div></div><div class="activity-list">
<?php foreach ($report['activity'] as $activity): ?>
<a class="activity" href="<?= e(url('store',['id'=>$activity['store_id']])) ?>"><span class="activity-icon"><?= icon('clock') ?></span><div><strong><?= e(match($activity['action']){'save'=>'Step updated: '.$activity['title'],'signoff'=>'Step signed off: '.$activity['title'],'reopen'=>'Step reopened: '.$activity['title'],default=>$activity['title']}) ?></strong><p><?= e($activity['code'].' · '.$activity['store_name'].($activity['actor_name']?' · '.$activity['actor_name']:'')) ?></p><small><?= e((new DateTimeImmutable($activity['created_at']))->setTimezone(new DateTimeZone('Europe/Copenhagen'))->format('Y-m-d H:i T')) ?></small></div></a>
<?php endforeach; ?>
<?php if(!$report['activity']): ?><p class="empty-search">No activity recorded yet.</p><?php endif; ?>
</div></section>
</div>
<?php endif; ?>
