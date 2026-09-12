<?php $report = DashboardReport::sample(); ?>
<div class="page-heading"><div><div class="eyebrow">ROLLOUT OVERVIEW</div><h1>Project dashboard</h1><p>Every store, every workstream. One clear picture.</p></div>
<?php if (Access::atLeast($user,'pm')): ?><a class="button" href="<?= e(url('report-export')) ?>"><?= icon('download') ?>Export summary</a><?php endif; ?></div>
<?php if (!Access::atLeast($user,'pm')): ?>
<div class="panel empty-state"><?= icon('store') ?><h2>Your workspace is ready</h2><p>Open Stores to view your assigned installations and complete their checklists.</p><a class="button primary" href="<?= e(url('stores')) ?>">View stores</a></div>
<?php else: ?>
<div class="sample-strip"><span><span class="status-dot"></span>Design preview <span class="sample-separator">/</span> Sample data</span><span>Illustrative rollout · 3½ months</span></div>
<section class="metric-grid" aria-label="Rollout statistics">
<?php foreach ($report['metrics'] as $metric): ?>
<article class="metric-card"><div class="metric-top"><span><?= e($metric['label']) ?></span><span class="metric-icon"><?= icon($metric['icon']) ?></span></div><div class="metric-value"><?= e($metric['value']) ?><span><?= e($metric['unit']) ?></span></div><div class="metric-trend"><?= icon('check') ?><?= e($metric['trend']) ?></div></article>
<?php endforeach; ?>
</section>
<div class="overview-grid">
<section class="panel workstreams"><div class="panel-heading"><div><h2>Workstream progress</h2><p>Completed installations across 68 stores</p></div><span class="pill">4 workstreams</span></div>
<div class="workstream-list">
<?php foreach ($report['workstreams'] as $stream): ?>
<div class="workstream"><span class="stream-icon"><?= icon($stream['icon']) ?></span><div class="stream-body"><div class="stream-label"><strong><?= e($stream['label']) ?></strong><span><b><?= e($stream['complete']) ?></b> / <?= e($stream['total']) ?></span></div><progress value="<?= e($stream['complete']) ?>" max="<?= e($stream['total']) ?>" aria-label="<?= e($stream['label']) ?> completion"></progress></div><span class="stream-percent"><?= round($stream['complete'] / $stream['total'] * 100) ?>%</span></div>
<?php endforeach; ?>
</div><div class="panel-foot"><?= icon('pulse') ?><span>247 of 272 installations complete</span><strong>91%</strong></div>
</section>
<section class="panel attention"><div class="panel-heading"><div><h2>Needs attention</h2><p>A few things to keep moving</p></div><span class="count-badge">3</span></div>
<div class="attention-list">
<div class="attention-item"><span class="attention-icon amber"><?= icon('camera') ?></span><div><strong>Photo documentation pending</strong><p>8 completed tasks awaiting photos</p><span class="text-tag">Documentation</span></div></div>
<div class="attention-item"><span class="attention-icon amber"><?= icon('users') ?></span><div><strong>Contractor assignment needed</strong><p>Central Square · Visit on 16 Sep</p><span class="text-tag">Scheduling</span></div></div>
<div class="attention-item"><span class="attention-icon teal"><?= icon('clock') ?></span><div><strong>Store revisits to schedule</strong><p>2 stores with outstanding work</p><span class="text-tag">Follow-up</span></div></div>
</div></section>
</div>
<div class="detail-grid">
<section class="panel visits"><div class="panel-heading"><div><h2>Upcoming store visits</h2><p>The next stops on the rollout</p></div><span class="pill"><?= icon('calendar') ?>Sample schedule</span></div>
<div class="table-scroll"><table><thead><tr><th>Store</th><th>Contractor</th><th>Visit</th><th>Status</th></tr></thead><tbody>
<?php foreach ($report['visits'] as $visit): ?>
<tr><td><strong class="store-name"><?= e($visit['store']) ?></strong><small><?= e($visit['code']) ?> · <?= e($visit['work']) ?></small></td><td><?= e($visit['team']) ?></td><td class="nowrap"><?= e($visit['date']) ?></td><td><span class="badge <?= e($visit['tone']) ?>"><?= e($visit['status']) ?></span></td></tr>
<?php endforeach; ?>
</tbody></table></div></section>
<section class="panel"><div class="panel-heading"><div><h2>Recent activity</h2><p>Latest updates from the field</p></div></div><div class="activity-list">
<?php foreach ($report['activity'] as $activity): ?>
<div class="activity"><span class="activity-icon"><?= icon($activity['icon']) ?></span><div><strong><?= e($activity['title']) ?></strong><p><?= e($activity['detail']) ?></p><small><?= e($activity['time']) ?></small></div></div>
<?php endforeach; ?>
</div><div class="activity-note">Sample activity for the design preview</div></section>
</div>
<?php endif; ?>
