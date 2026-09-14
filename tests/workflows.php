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

    $templateEditor=new ReminderTemplate($p);
    $originalTemplate=$templateEditor->read();
    $draft=['subject'=>'Visit {{store_code}}','body'=>"Hello {{owner_name}}\n{{store_name}}: {{installation_date}}"];
    denied(fn()=>$templateEditor->save($actors['worker'],$draft),'Contractors cannot edit email templates');
    denied(fn()=>$templateEditor->validate(['subject'=>"Bad\nSubject",'body'=>'Message']),'Multiline subjects rejected');
    denied(fn()=>$templateEditor->validate(['subject'=>'{{unknown}}','body'=>'Message']),'Unknown placeholders rejected');
    $templateEditor->save($actors['manager'],$draft);
    verify((new ReminderTemplate($p))->read()===$draft,'Reminder template persists');
    $rendered=$templateEditor->render($store);
    verify($rendered['subject']==='Visit STORE-A'&&str_contains($rendered['body'],$installation),'Store placeholders render');
    $captured=[];
    $service->testReminder($actors['admin'],'preview@example.test',$draft,function(...$args)use(&$captured){$captured[]=$args;});
    verify(count($captured)===1&&$captured[0][0]==='preview@example.test'&&$captured[0][1]==='[TEST] Visit DEMO-001','Test sends sample data only to the specified recipient');
    denied(fn()=>$service->testReminder($actors['worker'],'preview@example.test',$draft),'Contractors cannot test reminder emails');
    denied(fn()=>$service->testReminder($actors['admin'],'invalid',$draft),'Invalid test recipient rejected');
    $templateEditor->save($actors['admin'],$originalTemplate);

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
    verify(count(array_filter($live['attention'],fn($item)=>str_contains($item['reason'],'Installation date missing')||str_contains($item['reason'],'Overdue')))===2,'Dashboard flags missing dates and overdue unfinished stores');
    verify(count($live['visits'])===3,'Dashboard visits include today and future unfinished stores');
    verify(count($live['activity'])>0,'Dashboard reads recorded activity');
    denied(fn()=>DashboardReport::live($p,$actors['worker']),'Contractor cannot access global dashboard data');

    $first=DashboardReport::activity($p,$actors['admin'],1,10);
    $second=DashboardReport::activity($p,$actors['admin'],2,10);
    verify(count($live['activity'])===5,'Dashboard activity is limited to five entries');
    verify(count($first['rows'])===10&&$first['pages']>1,'Activity history paginates ten rows');
    verify(!array_intersect(array_column($first['rows'],'id'),array_column($second['rows'],'id')),'Activity pages do not overlap');
    verify(DashboardReport::activity($p,$actors['admin'],999,50)['page']===1,'Out-of-range activity page is clamped');
    denied(fn()=>DashboardReport::activity($p,$actors['worker']),'Contractors cannot read global activity history');

    verify(ProjectRepository::workingDaysUntil('2026-09-11','2026-09-14')===1,'Working days skip weekends');
    verify(ProjectRepository::workingDaysUntil('2026-09-14','2026-09-14')===0,'Installation today has zero working days remaining');
    $fixture=['finished'=>0,'target_date'=>'2026-09-23','unifi_order'=>'not_ordered'];
    verify(ProjectRepository::unifiAttention($fixture,'2026-09-14')===null,'Exactly seven working days does not warn');
    $fixture['target_date']='2026-09-22';
    verify(ProjectRepository::unifiAttention($fixture,'2026-09-14')!==null,'Six working days warns for not ordered');
    $fixture['unifi_order']='ordered';
    verify(ProjectRepository::unifiAttention($fixture,'2026-09-14')===null,'Ordered clears the seven-day warning');
    $fixture['target_date']='2026-09-17';
    verify(ProjectRepository::unifiAttention($fixture,'2026-09-14')===null,'Exactly three working days does not warn for ordered');
    $fixture['target_date']='2026-09-16';
    foreach(['not_ordered','ordered','shipped'] as $state){$fixture['unifi_order']=$state;verify(ProjectRepository::unifiAttention($fixture,'2026-09-14')!==null,'Two working days warns for '.$state);}
    $fixture['unifi_order']='delivered';
    verify(ProjectRepository::unifiAttention($fixture,'2026-09-14')===null,'Delivered clears imminent installation warning');
    $fixture['finished']=1;$fixture['unifi_order']='not_ordered';
    verify(ProjectRepository::unifiAttention($fixture,'2026-09-14')===null,'Finished store has no UniFi warning');
    $p->setUnifiOrder($actors['manager'],$storeId,'shipped');
    verify(Access::store($db,$actors['manager'],$storeId)['unifi_order']==='shipped','PM order state persists');
    denied(fn()=>$p->setUnifiOrder($actors['worker'],$storeId,'delivered'),'Contractor cannot update order');
    denied(fn()=>$p->setUnifiOrder($actors['admin'],$storeId,'invalid'),'Invalid order state rejected');

    $unscheduled=$sortIds[3];
    foreach(['worker','lead'] as $role){
        verify(count($p->storeListing($actors[$role],true,'SORT-3'))===0,'Unscheduled store hidden from '.$role.' including Show all');
        verify(!in_array($unscheduled,array_column($p->stores($actors[$role]),'id'),true),'Unscheduled store absent from legacy list for '.$role);
        denied(fn()=>Access::store($db,$actors[$role],$unscheduled),'Unscheduled direct access denied for '.$role);
    }
    verify(Access::store($db,$actors['manager'],$unscheduled)['id']===$unscheduled,'PM retains unscheduled access');
    $p->execute('UPDATE stores SET target_date=? WHERE id=?',[$today,$unscheduled]);
    verify(Access::store($db,$actors['worker'],$unscheduled)['id']===$unscheduled,'Scheduling restores assigned contractor access');
    $p->execute('UPDATE stores SET target_date=NULL WHERE id=?',[$unscheduled]);
    denied(fn()=>Access::store($db,$actors['worker'],$unscheduled),'Removing date revokes contractor access');

    $autoStep=$steps->list($sortIds[0])[0];
    $steps->update($actors['worker'],$autoStep['id'],['action'=>'save','version'=>$autoStep['version'],'note'=>'Keep this note']);
    $autoStep=$steps->list($sortIds[0])[0];
    $steps->update($actors['worker'],$autoStep['id'],['action'=>'completion','version'=>$autoStep['version'],'complete'=>1]);
    $savedStep=$steps->list($sortIds[0])[0];
    verify($savedStep['complete']==1&&$savedStep['note']==='Keep this note','Completion autosave preserves stored notes');
    denied(fn()=>$steps->update($actors['worker'],$autoStep['id'],['action'=>'completion','version'=>$autoStep['version']]),'Stale completion autosave rejected');
    $steps->update($actors['worker'],$autoStep['id'],['action'=>'completion','version'=>$savedStep['version']]);
    verify($steps->list($sortIds[0])[0]['complete']==0,'Unchecking completion persists');


    $photoStep=$steps->list($sortIds[0])[0];
    if(!is_dir($private.'/uploads'))mkdir($private.'/uploads',0700,true);
    $photoFixture=function()use($p,$private,$photoStep,$actors){
        $id=Schema::id();file_put_contents($private.'/uploads/'.$id.'.png','test fixture');
        $p->execute('INSERT INTO subtask_photos(id,subtask_id,uploaded_by,storage_key,original_name,mime_type,created_at) VALUES(?,?,?,?,?,?,?)',[$id,$photoStep['id'],$actors['worker']['id'],$id.'.png','fixture.png','image/png',gmdate('Y-m-d\TH:i:s\Z')]);
        return $id;
    };
    $deleteId=$photoFixture();
    denied(fn()=>$steps->deletePhoto($actors['outsider'],$deleteId,(string)$photoStep['version']),'Other company cannot delete photos');
    $steps->deletePhoto($actors['worker'],$deleteId,(string)$photoStep['version']);
    verify(!is_file($private.'/uploads/'.$deleteId.'.png')&&!$p->rows('SELECT id FROM subtask_photos WHERE id=?',[$deleteId]),'Contractor deletion removes photo file and metadata');
    $photoStep=$steps->list($sortIds[0])[0];
    $steps->update($actors['worker'],$photoStep['id'],['action'=>'completion','version'=>$photoStep['version'],'complete'=>1]);
    $photoStep=$steps->list($sortIds[0])[0];
    $steps->update($actors['manager'],$photoStep['id'],['action'=>'signoff','version'=>$photoStep['version']]);
    $photoStep=$steps->list($sortIds[0])[0];$deleteId=$photoFixture();
    denied(fn()=>$steps->deletePhoto($actors['worker'],$deleteId,(string)$photoStep['version']),'Signed-off photo cannot be deleted by contractor');
    $steps->deletePhoto($actors['manager'],$deleteId,(string)$photoStep['version']);
    verify(count($steps->photos($photoStep['id']))===0,'PM can delete signed-off photos');
    rmdir($private.'/uploads');


    $statusStep=['signed_at'=>null,'complete'=>0,'note'=>'','photo_count'=>0];
    verify(SubtaskRepository::displayStatus($statusStep)==='To do','Empty step is To do');
    verify(SubtaskRepository::displayStatus(array_replace($statusStep,['note'=>'Work started']))==='In progress','Saved note makes step In progress');
    verify(SubtaskRepository::displayStatus(array_replace($statusStep,['photo_count'=>1]))==='In progress','Uploaded photo makes step In progress');
    verify(SubtaskRepository::displayStatus(array_replace($statusStep,['complete'=>1,'photo_count'=>1]))==='Awaiting sign-off','Completion takes precedence over In progress');
    verify(SubtaskRepository::displayStatus(array_replace($statusStep,['signed_at'=>'2026-09-12T12:00:00Z','complete'=>1]))==='Signed off','Sign-off takes precedence');


    $prereqs=new PrerequisiteRepository($p);
    $prereqs->addItem($actors['manager'],['name'=>'Access arranged']);
    $definition=array_values(array_filter($prereqs->definitions(),fn($d)=>$d['name']==='Access arranged'))[0];
    $prereqs->addStatus($actors['admin'],$definition['id'],['name'=>'Confirmed']);
    $definition=array_values(array_filter($prereqs->definitions(),fn($d)=>$d['id']===$definition['id']))[0];
    $firstStatus=$definition['statuses'][0]['id'];$confirmed=$definition['statuses'][1]['id'];
    $prereqs->save($actors['manager'],$definition['id'],['name'=>'Access arranged','active'=>1,'status_ids'=>[$confirmed,$firstStatus],'labels'=>[$firstStatus=>'Pending',$confirmed=>'Confirmed'],'default_status'=>$firstStatus,'attention'=>[$firstStatus=>1],'days'=>[$firstStatus=>5]]);
    $definition=array_values(array_filter($prereqs->definitions(),fn($d)=>$d['id']===$definition['id']))[0];
    verify(array_column($definition['statuses'],'id')===[$confirmed,$firstStatus],'Prerequisite dropdown order is configurable');
    $storeForRules=Access::store($db,$actors['manager'],$sortIds[0])+['finished'=>0];
    $item=array_values(array_filter($prereqs->forStore($storeForRules),fn($i)=>$i['id']===$definition['id']))[0];
    verify($item['status']['id']===$firstStatus,'New prerequisite applies default to existing stores independently of order');
    verify(PrerequisiteRepository::issue($storeForRules,$item,$today)!==null,'Custom status threshold raises attention');
    $prereqs->setStatus($actors['manager'],$storeForRules['id'],$definition['id'],$confirmed);
    $item=array_values(array_filter($prereqs->forStore($storeForRules),fn($i)=>$i['id']===$definition['id']))[0];
    verify(PrerequisiteRepository::issue($storeForRules,$item,$today)===null,'Status without attention clears custom alert');
    denied(fn()=>$prereqs->setStatus($actors['worker'],$storeForRules['id'],$definition['id'],$firstStatus),'Contractor cannot edit custom prerequisite');
    denied(fn()=>$prereqs->setStatus($actors['manager'],$storeForRules['id'],$definition['id'],'ordered'),'Cross-item status rejected');
    denied(fn()=>$prereqs->addItem($actors['lead'],['name'=>'Forbidden']),'Contractor admin cannot manage prerequisite definitions');


    require_once dirname(__DIR__).'/src/StoreImport.php';
    $import=new StoreImport($p);
    $csv="code,name,post_code,city,address\nIMP-001,Import store,0012,Test City,12 Main Street\n";
    $semicolon=$import->preview($actors['admin'],"\"code\";\"name\";\"post_code\";\"city\";\"address\"\n\"SEP-1\";\"Shop; Central\";\"0012\";\"København\";\"Main Street, 12\"\n");
    verify($semicolon['changes'][0]['input']['name']==='Shop; Central'&&$semicolon['changes'][0]['input']['address']==='Main Street, 12','Semicolon CSV preserves quoted delimiters and Unicode');
    $tab=$import->preview($actors['admin'],"code\tname\tpost_code\tcity\nTAB-1\tTab store\t0012\tCity\n");
    verify(count($tab['changes'])===1,'Tab-separated store files supported');
    $draft=$import->preview($actors['admin'],$csv);
    verify(count($draft['changes'])===1&&!$p->rows("SELECT id FROM stores WHERE code='IMP-001'"),'Import preview makes no persistent changes');
    $import->apply($actors['admin'],$draft,$draft['token']);
    $imported=$p->rows("SELECT * FROM stores WHERE code='IMP-001'")[0];
    verify($imported['post_code']==='0012'&&$imported['address']==='12 Main Street','Import preserves post code leading zeros and address');
    verify(count($steps->list($imported['id']))>0,'Imported stores receive template steps');
    verify($import->preview($actors['admin'],$csv)['ignored']===1,'Unchanged imported rows are ignored');
    $changed="code,name,post_code,city,address\nIMP-001,Updated import,0012,Test City,\n";
    $draft=$import->preview($actors['admin'],$changed);
    verify(isset($draft['changes'][0]['diff']['name'])&&!isset($draft['changes'][0]['diff']['address']),'Review lists only changed fields and preserves blank optional cells');
    $import->apply($actors['admin'],$draft,$draft['token']);
    verify($p->rows("SELECT id FROM stores WHERE code='IMP-001'")[0]['id']===$imported['id'],'Store code updates preserve internal IDs');
    denied(fn()=>$import->preview($actors['manager'],$csv),'PM cannot import stores');
    denied(fn()=>$import->preview($actors['admin'],"code,name,post_code,city\nDUP,One,001,City\ndup,Two,001,City\n"),'Duplicate CSV store codes rejected');
    denied(fn()=>$import->preview($actors['admin'],"code,name,post_code,city\nBAD,Missing code,,City\n"),'Post code required in CSV');
    denied(fn()=>$import->preview($actors['admin'],"code,name,post_code,city,target_date\nGOOD,Good,001,City,2026-10-01\nBAD,Bad,001,City,invalid\n"),'Invalid row rejects whole preview');
    verify(!$p->rows("SELECT id FROM stores WHERE code='GOOD'"),'Failed preview rolls back earlier rows');
    $draft=$import->preview($actors['admin'],$csv);
    $p->execute("UPDATE stores SET city='Changed externally' WHERE id=?",[$imported['id']]);
    denied(fn()=>$import->apply($actors['admin'],$draft,$draft['token']),'Changed data requires new preview before import');


    require_once dirname(__DIR__).'/src/StoreDeletion.php';
    $deletion=new StoreDeletion($p,$private.'/uploads');
    denied(fn()=>$deletion->delete($actors['worker'],[$storeId]),'Contractors cannot delete stores');
    denied(fn()=>$deletion->delete($actors['admin'],[]),'Empty bulk deletion rejected');
    denied(fn()=>$deletion->delete($actors['admin'],[$storeId,Schema::id()]),'Missing selection rejects entire deletion');
    verify((bool)$p->rows('SELECT id FROM stores WHERE id=?',[$storeId]),'Rejected deletion preserves stores');
    mkdir($private.'/uploads',0700,true);$bulkPhoto=Schema::id();
    file_put_contents($private.'/uploads/'.$bulkPhoto.'.png','bulk test');
    $bulkStep=$steps->list($storeId)[0];
    $p->execute('INSERT INTO subtask_photos(id,subtask_id,uploaded_by,storage_key,original_name,mime_type,created_at) VALUES(?,?,?,?,?,?,?)',[$bulkPhoto,$bulkStep['id'],$actors['worker']['id'],$bulkPhoto.'.png','bulk.png','image/png',gmdate('Y-m-d\\TH:i:s\\Z')]);
    $result=$deletion->delete($actors['manager'],[$storeId,$overrideId]);
    verify($result['deleted']===2&&!$p->rows('SELECT id FROM stores WHERE id IN (?,?)',[$storeId,$overrideId]),'PM can delete multiple selected stores');
    verify(!$p->rows('SELECT id FROM tasks WHERE store_id=?',[$storeId])&&!$p->rows('SELECT id FROM manual_reminder_log WHERE store_id=?',[$storeId]),'Dependent task and reminder data removed');
    verify(!is_file($private.'/uploads/'.$bulkPhoto.'.png'),'Bulk deletion removes uploaded files');
    verify((bool)$p->rows('SELECT id FROM stores WHERE id=?',[$sortIds[0]]),'Unselected stores retained');
    rmdir($private.'/uploads');

    verify(PrerequisiteRepository::issue(['finished'=>0,'target_date'=>null],['name'=>'UniFi order','status'=>['attention_days'=>7]],$today)===null,'Unscheduled stores do not raise prerequisite readiness alerts');
    require_once dirname(__DIR__).'/src/StoreTemplateUpdater.php';
    $updater=new StoreTemplateUpdater($p);
    $patchStore=$p->storeSave(['code'=>'PATCH','name'=>'Template update','city'=>'Test','target_date'=>$installation],null);
    $patchOther=$p->storeSave(['code'=>'PATCH-OTHER','name'=>'Unselected','city'=>'Test'],null);
    $before=$steps->list($patchStore);
    $p->execute('UPDATE subtasks SET note=?,complete=1,signed_by=?,signed_name=?,signed_at=? WHERE id=?',['Keep evidence',$actors['manager']['id'],'Manager',gmdate('Y-m-d\TH:i:s\Z'),$before[0]['id']]);
    $before=$steps->list($patchStore);
    $p->templateSave(['category'=>'network','title'=>'New check A','instructions'=>'New instructions','active'=>1],null);
    $p->templateSave(['category'=>'audio','title'=>'New check B','instructions'=>'Check audio','active'=>1],null);
    $p->templateSave(['category'=>'rack','title'=>'Inactive check'],null);
    $newA=$p->rows("SELECT id FROM task_templates WHERE title='New check A'")[0]['id'];
    $newB=$p->rows("SELECT id FROM task_templates WHERE title='New check B'")[0]['id'];
    $p->execute('UPDATE task_templates SET report_order=100 WHERE id=?',[$newA]);
    $p->execute('UPDATE task_templates SET report_order=99 WHERE id=?',[$newB]);
    denied(fn()=>$updater->addMissing($actors['worker'],[$patchStore]),'Contractor cannot bulk add templates');
    denied(fn()=>$updater->addMissing($actors['lead'],[$patchStore]),'Contractor admin cannot bulk add templates');
    denied(fn()=>$updater->addMissing($actors['admin'],[]),'Empty template update selection rejected');
    denied(fn()=>$updater->addMissing($actors['admin'],[$patchStore,Schema::id()]),'Missing store rejects entire template batch');
    verify($steps->list($patchStore)===$before,'Rejected template batch preserves evidence');
    $result=$updater->addMissing($actors['manager'],[$patchStore,$patchStore]);
    verify($result===['added'=>2,'updated'=>1,'unchanged'=>0],'Missing active templates added once per selected store');
    $after=$steps->list($patchStore);
    verify(array_values(array_filter($after,fn($s)=>in_array($s['id'],array_column($before,'id'),true)))===$before,'Existing snapshots, notes, completion, sign-off and ordering preserved');
    $new=array_values(array_filter($after,fn($s)=>in_array($s['template_id'],[$newA,$newB],true)));
    verify(count($new)===2&&!$new[0]['complete']&&!$new[0]['signed_at']&&$new[0]['note']==='','New steps start empty and unchecked');
    verify(array_column(array_slice($steps->list($patchStore,'report_order'),-2),'template_id')===[$newB,$newA],'New steps preserve independent report order');
    verify(count($steps->list($patchOther))===count($before),'Unselected store unchanged');
    verify($updater->addMissing($actors['admin'],[$patchStore])===['added'=>0,'updated'=>0,'unchanged'=>1],'Repeated template updates do not duplicate steps');
    verify(count($p->rows("SELECT id FROM task_audit WHERE action='template-added' AND actor_id=?",[$actors['manager']['id']]))===2,'Template additions record the PM in activity');

    echo "All store-workflow tests passed.\n";
}finally{
    DatabaseSandbox::drop($config,$name);
    foreach(['smtp.json','smtp.key'] as $file)if(is_file($private.'/config/'.$file))unlink($private.'/config/'.$file);
    if(is_dir($private.'/config'))rmdir($private.'/config');
    if(is_dir($private))rmdir($private);
}
