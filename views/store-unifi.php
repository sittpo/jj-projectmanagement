<?php if(Access::atLeast($user,'pm')): ?>
<section class="panel unifi-panel"><div><h2>UniFi order</h2><p class="field-help">Track hardware readiness for installation.</p></div>
<form method="post" action="<?= e(url('store',['id'=>$store['id']])) ?>" class="unifi-order-form"><?= csrf() ?><input type="hidden" name="action" value="unifi-order">
<label class="sr-only" for="unifi-order">UniFi order</label><select id="unifi-order" name="unifi_order"><?php foreach(ProjectRepository::UNIFI_STATES as $value=>$label): ?><option value="<?= e($value) ?>" <?= $store['unifi_order']===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select><span class="unifi-feedback field-help" aria-live="polite"></span><noscript><button class="button">Save order state</button></noscript>
</form></section>
<?php endif; ?>
