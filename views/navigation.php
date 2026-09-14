<?php
declare(strict_types=1);
$navigationRoot=match($page){
    'store','store-edit','store-report','step-error'=>'stores',
    'company-edit'=>'companies',
    'template-edit'=>'templates',
    'user-edit'=>'users',
    'activity','attention'=>'dashboard',
    default=>$page
};
$navigationLabels=['dashboard'=>'Dashboard','stores'=>'Stores','companies'=>'Contracting companies','templates'=>'Task templates','users'=>'Users'];
$breadcrumbs=[['label'=>'Workspace','href'=>url('dashboard')]];
if($navigationRoot!==$page){
    $breadcrumbs[]=['label'=>$navigationLabels[$navigationRoot]??$navigationRoot,'href'=>url($navigationRoot)];
}
if($page==='store-edit'){
    if(isset($store))$breadcrumbs[]=['label'=>$store['name'],'href'=>url('store',['id'=>$store['id']])];
    $breadcrumbTitle=isset($store)?'Edit store':'Create store';
}elseif($page==='company-edit'){
    $breadcrumbTitle=isset($id)?'Edit company':'Create company';
}elseif($page==='template-edit'){
    $breadcrumbTitle=isset($id)?'Edit task template':'Create task template';
}else $breadcrumbTitle=$title;
$breadcrumbs[]=['label'=>$breadcrumbTitle,'href'=>null];
