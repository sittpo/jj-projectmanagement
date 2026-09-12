<?php
declare(strict_types=1);
$projectPages=['stores','store','store-edit','companies','company-edit','templates','template-edit','settings','smtp','photo','step-update','store-report','team'];
if(in_array($page,$projectPages,true)){
    $subtasks=new SubtaskRepository($db,getenv('UPLOAD_ROOT')?:dirname(__DIR__).'/storage/uploads');
    $smtpSettings=new SmtpSettings(dirname(__DIR__).'/storage/config');
    $id=is_string($_GET['id']??null)?$_GET['id']:null;
    if(in_array($page,['store-edit','companies','company-edit','templates','template-edit','settings'],true)&&!Access::atLeast($user,'pm')){http_response_code(403);$page='forbidden';}
    if($page==='smtp'&&!Access::atLeast($user,'admin')){http_response_code(403);$page='forbidden';}
    if($page==='team'&&!Access::atLeast($user,'contractor_admin')){http_response_code(403);$page='forbidden';}
    try{
        if(in_array($page,['store','store-edit','store-report'],true)&&$id)$store=Access::store($db,$user,$id);
        if($page==='photo'){
            $photo=$subtasks->photo($user,$id??'');
            header('Content-Type: '.$photo['mime_type']);header('Content-Length: '.filesize($photo['path']));header('Content-Disposition: inline; filename="photo.'.pathinfo($photo['storage_key'],PATHINFO_EXTENSION).'"');
            readfile($photo['path']);exit;
        }
        if($page==='step-update'){
            if(!$isPost){http_response_code(405);exit('Use the step form.');}
            $storeId=$subtasks->update($user,$id??'',$_POST,$_FILES['photos']??[]);
            $_SESSION['flash']='Step updated.';redirect('store',['id'=>$storeId]);
        }
        if($page==='store-edit'&&$isPost){$saved=$project->storeSave($_POST,$id);$_SESSION['flash']=$id?'Store updated.':'Store created with the current task templates.';redirect('store',['id'=>$saved]);}
        if($page==='company-edit'&&$isPost){$project->companySave($_POST,$id);$_SESSION['flash']='Company saved.';redirect('companies');}
        if($page==='template-edit'&&$isPost){$project->templateSave($_POST,$id);$_SESSION['flash']='Template saved. Changes apply to newly created stores.';redirect('templates');}
        if($page==='templates'&&$isPost){
            $ids=$_POST['order']??[];if(!is_array($ids))throw new DomainException('Invalid order.');
            $project->reorder($ids,(string)($_POST['kind']??''));$_SESSION['flash']='Template order saved for new stores.';redirect('templates');
        }
        if($page==='settings'&&$isPost){
            $days=filter_var($_POST['reminder_days']??'',FILTER_VALIDATE_INT);
            if($days===false||$days<0||$days>365)throw new DomainException('Choose 0–365 days before installation.');
            $project->execute("UPDATE project_settings SET setting_value=? WHERE setting_key='reminder_days'",[(string)$days]);
            $_SESSION['flash']='Reminder schedule updated.';redirect('settings');
        }
        if($page==='smtp'&&$isPost){$smtpSettings->save($_POST);$_SESSION['flash']='SMTP settings saved. No email was sent.';redirect('smtp');}
    }catch(AccessDenied $exception){http_response_code(403);$page='forbidden';
    }catch(DomainException $exception){
        if(!$isPost){http_response_code(403);$page='forbidden';}
        else{$error=$exception->getMessage();if($page==='step-update'){$page='step-error';}}
    }catch(Throwable $exception){
        error_log((string)$exception);$error='The change could not be saved. Check for duplicate codes or names and try again.';
        if($page==='step-update')$page='step-error';
    }
    if($page==='stores')$storeRows=$project->stores($user);
    if(in_array($page,['store','store-report'],true)){
        if(!isset($store)){http_response_code(404);exit('Store not found.');}
        $steps=$subtasks->list($store['id'],$page==='store-report'?'report_order':'ui_order');
        $assigned=$project->rows('SELECT u.display_name FROM store_assignments a JOIN users u ON u.id=a.user_id WHERE a.store_id=?',[$store['id']]);
        $logs=$project->rows('SELECT * FROM reminder_log WHERE store_id=? ORDER BY created_at DESC',[$store['id']]);
        $audits=$project->rows('SELECT a.*,s.title FROM task_audit a JOIN subtasks s ON s.id=a.subtask_id JOIN tasks t ON t.id=s.task_id WHERE t.store_id=? ORDER BY a.created_at DESC,a.id',[$store['id']]);
    }
    if($page==='store-edit'){
        $form=$isPost?$_POST:($store??['reminders_enabled'=>1]);
        $selected=$isPost?($_POST['contractors']??[]):array_column($project->rows('SELECT user_id FROM store_assignments WHERE store_id=?',[$id??'']),'user_id');
        $contractors=$project->rows("SELECT id,display_name,company_id FROM users WHERE active=1 AND role IN ('contractor','contractor_admin') ORDER BY display_name");
    }
    if($page==='company-edit')$form=$isPost?$_POST:($id?($project->rows('SELECT * FROM companies WHERE id=?',[$id])[0]??[]):['active'=>1]);
    if($page==='template-edit')$form=$isPost?$_POST:($id?($project->rows('SELECT * FROM task_templates WHERE id=?',[$id])[0]??[]):['active'=>1]);
    if($page==='team')$teamRows=Access::atLeast($user,'pm')?$project->rows("SELECT u.display_name,u.email,u.role,c.name AS company_name FROM users u LEFT JOIN companies c ON c.id=u.company_id WHERE u.role IN ('contractor','contractor_admin') ORDER BY u.display_name"):$project->rows('SELECT u.display_name,u.email,u.role,c.name AS company_name FROM users u JOIN companies c ON c.id=u.company_id WHERE u.company_id=? ORDER BY u.display_name',[$user['company_id']??'']);
    if($page==='store-report'){require dirname(__DIR__).'/views/store-report.php';exit;}
}
