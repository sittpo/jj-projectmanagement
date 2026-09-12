<div class="page-heading"><div><div class="eyebrow">WORKSPACE</div><h1>Stores</h1><p><?= Access::atLeast($user,'pm')?'Manage installations, contacts, and contractor assignments.':($user['role']==='contractor_admin'?'All stores assigned to your contracting company.':'Stores assigned to you.') ?></p></div><?php if(Access::atLeast($user,'pm')): ?><a class="button primary" href="<?= e(url('store-edit')) ?>"><?= icon('plus') ?>Create store</a><?php endif; ?></div>
<section class="panel stores-panel">
<div class="stores-toolbar">
<form method="get" action="index.php" class="store-search-form" role="search"><input type="hidden" name="page" value="stores"><label for="store-search">Search stores</label><div class="store-search-controls"><input type="search" id="store-search" name="q" maxlength="160" placeholder="Store name or ID" value="<?= e($storeQuery) ?>" aria-controls="store-results"><button class="button" type="submit">Search</button></div></form>
<form method="post" action="<?= e(url('stores')) ?>" class="store-preference-form"><?= csrf() ?><input type="hidden" name="action" value="store-preference"><input type="hidden" name="q" value="<?= e($storeQuery) ?>"><label class="checkbox-label"><input type="checkbox" name="show_all" value="1" <?= $showAllStores?'checked':'' ?>>Show all stores</label><noscript><button class="button">Save preference</button></noscript></form>
</div>
<p class="stores-help">Unfinished stores include work awaiting PM sign-off. Undated stores appear last. “Show all stores” is saved to your account.</p>
<p class="stores-feedback sr-only" role="status" aria-live="polite"></p>
<div id="store-results"><?php require __DIR__.'/stores-results.php'; ?></div>
</section>
