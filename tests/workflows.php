<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/app.php';
require __DIR__.'/DatabaseSandbox.php';
$name='jjtest_'.bin2hex(random_bytes(5));$db=DatabaseSandbox::create($config,$name);
$private=dirname(__DIR__).'/storage/workflow-test-'.bin2hex(random_bytes(5));
function verify(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){verify(true,$label);return;}throw new RuntimeException($label);}
try{
    Schema::migrate($db);Schema::migrate($db);$p=new ProjectRepository($db);$u=new UserRepository($db);$steps=new SubtaskRepository($db,$private.'/uploads');
    $a=$p->companySave(['name'=>'Alpha','active'=>1],null);$b=$p->companySave(['name'=>'Beta','active'=>1],null);
    $password=bin2hex(random_bytes(12));
    foreach(['admin'=>['admin',null],'manager'=>['pm',null],'lead'=>['contractor_admin',$a],'worker'=>['contractor',$a],'outsider'=>['contractor',$b],'unassigned'=>['contractor',$a]] as $username=>$values){
        $u->save(['username'=>$username,'display_name'=>ucfirst($username),'role'=>$values[0],'company_id'=>$values[1]??'','password'=>$password,'active'=>1],null,'');
        $actors[$username]=$u->byUsername($username);
    }
    $today=(new DateTimeImmutable('now',new DateTimeZone('Europe/Copenhagen')))->format('Y-m-d');
    $installation=(new DateTimeImmutable($today))->modify('+7 days')->format('Y-m-d');
    $input=['code'=>'STORE-A','name'=>'Alpha Store','city'=>'Test City','owner_name'=>'Store Owner','owner_email'=>'owner@example.test','contact_email'=>'contact@example.test','target_date'=>$installation,'company_id'=>$a,'contractors'=>[$actors['worker']['id']],'reminders_enabled'=>1];
    $ids=array_column($p->templates(),'id');$p->reorder(array_reverse($ids),'report_order');
    $storeId=$p->storeSave($input,null);$store=Access::store($db,$actors['admin'],$storeId);
    verify(count($steps->list($storeId))===4,'New store gets four active template snapshots');
    verify(array_column($steps->list($storeId),'template_id')===$ids,'Interface order is independent of report order');
    verify(array_column($steps->list($storeId,'report_order'),'template_id')===array_reverse($ids),'Report order is copied independently');
    $template=$p->templates()[0];$p->templateSave(['category'=>$template['category'],'title'=>'Changed template','instructions'=>'New instructions','active'=>1],$template['id']);
    verify($steps->list($storeId)[0]['title']!== 'Changed template','Template edits preserve existing store history');
    verify(count($p->stores($actors['lead']))===1,'Contractor admin sees all company stores');
    verify(count($p->stores($actors['worker']))===1,'Assigned contractor sees assigned store');
    verify(count($p->stores($actors['unassigned']))===0,'Unassigned contractor sees no stores');
    denied(fn()=>Access::store($db,$actors['outsider'],$storeId),'Other company cannot access store');
    denied(fn()=>Access::store($db,$actors['unassigned'],$storeId),'Unassigned contractor cannot access store directly');
    $bad=$input;$bad['contractors']=[$actors['outsider']['id']];
    denied(fn()=>$p->storeSave($bad,$storeId),'Cross-company assignment rejected');
    $step=$steps->list($storeId)[0];
    $steps->update($actors['worker'],$step['id'],['action'=>'save','version'=>$step['version'],'complete'=>1,'note'=>'Installed and tested.']);
    denied(fn()=>$steps->update($actors['worker'],$step['id'],['action'=>'save','version'=>$step['version'],'note'=>'Stale update']),'Stale step update rejected');
    $step=$steps->list($storeId)[0];
    denied(fn()=>$steps->update($actors['lead'],$step['id'],['action'=>'signoff','version'=>$step['version']]),'Contractor admin cannot sign off');
    $steps->update($actors['manager'],$step['id'],['action'=>'signoff','version'=>$step['version']]);
    $step=$steps->list($storeId)[0];verify($step['signed_name']==='Manager','PM sign-off records the signer');
    denied(fn()=>$steps->update($actors['worker'],$step['id'],['action'=>'save','version'=>$step['version'],'note'=>'Attempted edit']),'Signed-off step is locked');
    $steps->update($actors['admin'],$step['id'],['action'=>'reopen','version'=>$step['version']]);
    verify($steps->list($storeId)[0]['signed_at']===null,'Admin can reopen a signed step');
    verify(count($p->rows('SELECT * FROM task_audit WHERE subtask_id=?',[$step['id']]))===3,'Completion, sign-off, and reopen are audited');
    $smtp=new SmtpSettings($private.'/config');
    $secret=bin2hex(random_bytes(12));
    $smtp->save(['username'=>'test-user','password'=>$secret,'port'=>'587','from_email'=>'sender@example.test','from_name'=>'Project','enabled'=>1]);
    verify($smtp->read()['has_password']&&!isset($smtp->read()['password']),'SMTP secret is hidden from settings views');
    verify(!str_contains(file_get_contents($private.'/config/smtp.json'),$secret),'SMTP password is encrypted at rest');
    verify($smtp->read(true)['password']===$secret,'SMTP secret decrypts for transport only');
    $service=new ReminderService($p,$smtp);
    verify(count($service->due($today))===2,'Global schedule finds owner and contact reminders');
    $calls=[];$transport=function($to,$subject,$body)use(&$calls){$calls[]=$to;};
    verify(count($service->run(false))===2&&count($calls)===0,'Dry run does not send');
    $service->run(true,$transport);
    verify(count($calls)===2,'Reminder transport receives each recipient');
    verify(count($service->run(true,$transport))===0&&count($calls)===2,'Already sent reminders are not duplicated');
    verify(count($p->rows("SELECT * FROM reminder_log WHERE status='sent'"))===2,'SMTP acceptance is logged');
    $override=$input;$override['code']='STORE-B';$override['name']='Override Store';$override['reminder_date']=(new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
    $overrideId=$p->storeSave($override,null);verify(count($service->due($today))===0,'Per-store reminder date overrides global offset');
    $override['reminder_date']=$today;$override['contact_email']=$override['owner_email'];$p->storeSave($override,$overrideId);
    verify(count($service->due($today))===1,'Duplicate owner/contact address is sent only once');
    $service->run(true,static function(){throw new RuntimeException('Simulated SMTP failure');});
    verify(count($p->rows("SELECT * FROM reminder_log WHERE status='failed'"))===1,'Failed send is logged');
    verify(count($service->due($today))===0,'Failed attempts are not blindly retried');

    $manualCalls=[];$manualTransport=function($to,$subject,$body)use(&$manualCalls){$manualCalls[]=$to;};
    $requestId=bin2hex(random_bytes(16));
    denied(fn()=>$service->manual($actors['lead'],$storeId,$requestId,$manualTransport),'Contractor admin cannot manually send reminders');
    $manual=$service->manual($actors['manager'],$storeId,$requestId,$manualTransport);
    verify(count($manual)===2&&count($manualCalls)===2,'PM can immediately send owner/contact reminders');
    verify(count($service->manual($actors['manager'],$storeId,$requestId,$manualTransport))===0,'Duplicate manual submission does not send again');
    verify(count($p->rows('SELECT * FROM manual_reminder_log WHERE actor_name=?',['Manager']))===2,'Manual log records sender and recipients');
    $service->manual($actors['admin'],$storeId,bin2hex(random_bytes(16)),$manualTransport);
    verify(count($manualCalls)===4,'A deliberate new manual request can resend');
    verify(count($p->rows('SELECT * FROM reminder_log'))===3,'Manual sends preserve automatic reminder history');
    $service->manual($actors['admin'],$storeId,bin2hex(random_bytes(16)),static function(){throw new RuntimeException('Simulated failure');});
    verify(count($p->rows("SELECT * FROM manual_reminder_log WHERE status='failed'"))===2,'Manual send failures are logged');
    denied(fn()=>$service->test('invalid-address',$manualTransport),'SMTP test rejects invalid recipients');
    $testCalls=[];$service->test('test@example.test',function($to,$subject,$body)use(&$testCalls){$testCalls[]=[$to,$subject];});
    verify($testCalls[0][0]==='test@example.test'&&str_contains($testCalls[0][1],'SMTP test'),'SMTP test targets the specified recipient');

    verify(!$p->showAllStores($actors['admin']['id']),'Show all defaults off');
    $p->saveStorePreference($actors['admin']['id'],true);
    verify((new ProjectRepository($db))->showAllStores($actors['admin']['id']),'Preference persists across repository instances');
    verify(!$p->showAllStores($actors['worker']['id']),'Preference is isolated per user');
    $p->saveStorePreference($actors['admin']['id'],false);
    verify(!$p->showAllStores($actors['admin']['id']),'Preference can be turned off');
    verify(count($p->storeListing($actors['worker'],true,'store-a'))===1,'Store code search is case insensitive');
    verify(count($p->storeListing($actors['worker'],true,$storeId))===1,'Internal store ID can be searched');
    verify(count($p->storeListing($actors['outsider'],true,'Alpha'))===0,'Search and show all preserve company isolation');
    verify(count($p->storeListing($actors['admin'],true,'%'))===0,'Search treats wildcard characters literally');
    foreach($steps->list($storeId) as $item){
        $steps->update($actors['admin'],$item['id'],['action'=>'save','version'=>$item['version'],'complete'=>1]);
    }
    verify(count($p->storeListing($actors['admin'],false,'STORE-A'))===1,'Completed checklist awaiting sign-off remains unfinished');
    foreach($steps->list($storeId) as $item)$steps->update($actors['admin'],$item['id'],['action'=>'signoff','version'=>$item['version']]);
    verify(count($p->storeListing($actors['admin'],false,'STORE-A'))===0,'Signed-off stores are hidden by default');
    verify(count($p->storeListing($actors['admin'],true,'STORE-A'))===1,'Show all includes signed-off stores');
    $sortIds=[];
    foreach(['2020-01-01',$today,'2099-01-01',''] as $index=>$date){
        $fixture=$input;$fixture['code']='SORT-'.$index;$fixture['name']='Sort '.$index;$fixture['target_date']=$date;
        $sortIds[]=$p->storeSave($fixture,null);
    }
    verify(array_column($p->storeListing($actors['admin'],false,'SORT-'),'id')===$sortIds,'Installation order includes overdue first and unscheduled last');


    $noteId=$p->addPmNote($actors['manager'],$storeId,['pm_note'=>'Stakeholder update','include_in_report'=>1]);
    $note=$p->pmNotes($actors['admin'],$storeId)[0];
    verify($note['author_name']==='Manager'&&preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}Z$/',$note['created_at'])===1,'PM note records author and creation timestamp');
    verify(count($p->pmNotes($actors['worker'],$storeId,true))===1,'Included PM notes are available in authorized reports');
    $p->setPmNoteReport($actors['admin'],$storeId,$noteId,false);
    verify(count($p->pmNotes($actors['admin'],$storeId))===1&&count($p->pmNotes($actors['worker'],$storeId,true))===0,'Excluded note remains stored and is omitted from reports');
    denied(fn()=>$p->addPmNote($actors['lead'],$storeId,['pm_note'=>'Not allowed']),'Contractor admin cannot add PM notes');
    denied(fn()=>$p->setPmNoteReport($actors['worker'],$storeId,$noteId,true),'Contractor cannot change note report inclusion');
    denied(fn()=>$p->pmNotes($actors['worker'],$storeId),'Contractor cannot read private PM notes');
    denied(fn()=>$p->pmNotes($actors['outsider'],$storeId,true),'Other company cannot read note reports');
    denied(fn()=>$p->setPmNoteReport($actors['manager'],$overrideId,$noteId,true),'Cannot change a note through a different store');
    $p->setPmNoteReport($actors['manager'],$storeId,$noteId,true);
    verify($p->pmNotes($actors['manager'],$storeId)[0]['created_at']===$note['created_at'],'Report setting changes preserve original note timestamp');

    denied(fn()=>$p->deletePmNote($actors['worker'],$storeId,$noteId),'Contractor cannot delete PM notes');
    denied(fn()=>$p->deletePmNote($actors['manager'],$overrideId,$noteId),'Cannot delete a note through a different store');
    $p->deletePmNote($actors['admin'],$storeId,$noteId);
    verify(count($p->pmNotes($actors['manager'],$storeId))===0,'Admin can delete a PM note');

    $live=DashboardReport::live($p,$actors['admin']);
    verify((int)$live['metrics'][0]['value']===1&&(int)$live['total']===6,'Dashboard uses actual complete and created store counts');
    verify(count($live['attention'])===2,'Dashboard flags missing dates and overdue unfinished stores');
    verify(count($live['visits'])===3,'Dashboard visits include today and future unfinished stores');
    verify(count($live['activity'])>0,'Dashboard reads recorded activity');
    denied(fn()=>DashboardReport::live($p,$actors['worker']),'Contractor cannot access global dashboard data');

    echo "All store-workflow tests passed.\n";
}finally{
    DatabaseSandbox::drop($config,$name);
    foreach(['smtp.json','smtp.key'] as $file)if(is_file($private.'/config/'.$file))unlink($private.'/config/'.$file);
    if(is_dir($private.'/config'))rmdir($private.'/config');
    if(is_dir($private))rmdir($private);
}
