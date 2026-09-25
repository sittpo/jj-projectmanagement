<?php
declare(strict_types=1);
ob_start();session_start();
require dirname(__DIR__).'/src/app.php';
require __DIR__.'/DatabaseSandbox.php';
require dirname(__DIR__).'/src/DataTransfer.php';
$name='jjtest_'.bin2hex(random_bytes(5));$db=DatabaseSandbox::create($config,$name);
$directory=dirname(__DIR__).'/storage/security-test-'.bin2hex(random_bytes(5));
function verifySecurity(bool $ok,string $label): void {if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
function denySecurity(callable $fn,string $label): void {try{$fn();}catch(DomainException){verifySecurity(true,$label);return;}throw new RuntimeException($label);}
try{
    Schema::migrate($db);Schema::migrate($db);
    $users=new UserRepository($db);$mfa=new MfaService($db,$directory);$auth=new Auth($db,$users,$mfa);$password=bin2hex(random_bytes(12));
    foreach(['admin'=>'admin','manager'=>'pm','worker'=>'contractor','lead'=>'contractor_admin'] as $username=>$role){
        $users->save(['username'=>$username,'display_name'=>$username,'role'=>$role,'active'=>1,'password'=>$password],null,'');
        $actors[$username]=$users->byUsername($username);
    }
    $input=['username'=>'newworker','display_name'=>'New worker','role'=>'contractor','password'=>$password,'active'=>1];
    $users->saveManaged($actors['manager'],$input,null);
    $new=$users->byUsername('newworker');
    verifySecurity((bool)$new,'PM can create contractor');
    $users->saveManaged($actors['manager'],array_replace($input,['active'=>null,'password'=>'changed-password']),$new['id']);
    $new=$users->find($new['id']);
    verifySecurity(!$new['active']&&password_verify('changed-password',$new['password_hash']),'PM can disable contractor and set password');
    denySecurity(fn()=>$users->saveManaged($actors['manager'],array_replace($input,['role'=>'admin']),null),'PM cannot create admin');
    denySecurity(fn()=>$users->saveManaged($actors['manager'],array_replace($input,['role'=>'pm']),$actors['worker']['id']),'PM cannot promote contractor to PM');
    foreach(['admin','manager'] as $nameActor)denySecurity(fn()=>$users->saveManaged($actors['manager'],$input,$actors[$nameActor]['id']),'PM cannot modify '.$nameActor);
    denySecurity(fn()=>$users->saveManaged($actors['worker'],$input,null),'Contractor cannot manage users');
    verifySecurity(count($users->all($actors['manager']))===3,'PM list is limited to contractors');
    verifySecurity(MfaService::otp('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ')->at(59)==='287082','TOTP matches RFC 6238 six-digit test vector');
    $worker=$actors['worker'];$setup=$mfa->setup($worker);
    verifySecurity(str_starts_with($setup['qr'],'data:image/svg+xml;base64,'),'Enrollment QR generated locally');
    denySecurity(fn()=>$mfa->enable($worker,array_replace($setup,['expires'=>0]),'123456'),'Expired setup rejected');
    $codes=$mfa->enable($worker,$setup,MfaService::otp($setup['secret'])->at(time()-30));
    verifySecurity(count($codes)===8&&$users->find($worker['id'])['mfa_enabled'],'MFA activated after valid code with recovery codes');
    $secret=$db->query('SELECT secret FROM user_mfa')->fetchColumn();
    verifySecurity(!str_contains($secret,$setup['secret']),'MFA secret encrypted at rest');
    verifySecurity($auth->login('worker',$password,'mfa-test')&&$auth->pending()&&!$auth->user(),'Password alone cannot establish MFA session');
    verifySecurity(!$auth->challenge('not-a-code','mfa-test'),'Invalid MFA code rejected');
    $current=MfaService::otp($setup['secret'])->now();
    verifySecurity($auth->challenge($current,'mfa-test')&&$auth->user(),'Authenticator challenge completes login');
    verifySecurity(!$mfa->verify($worker['id'],$current),'TOTP replay rejected');
    verifySecurity($mfa->verify($worker['id'],$codes[0])&&!$mfa->verify($worker['id'],$codes[0]),'Recovery codes work only once');
    $key=file_get_contents($directory.'/mfa.key');unlink($directory.'/mfa.key');
    verifySecurity($mfa->verify($worker['id'],$codes[1]),'Recovery code works when the authenticator encryption key is unavailable');
    file_put_contents($directory.'/mfa.key',$key);
    denySecurity(fn()=>$mfa->reset($actors['manager'],$actors['admin']['id']),'PM cannot reset admin MFA');
    denySecurity(fn()=>$mfa->reset($actors['manager'],$actors['manager']['id']),'PM cannot reset PM MFA');
    denySecurity(fn()=>$mfa->reset($actors['worker'],$worker['id']),'Contractor cannot reset MFA');
    $mfa->reset($actors['manager'],$worker['id']);
    verifySecurity(!$mfa->enabled($worker['id'])&&!$auth->user(),'PM reset removes MFA and revokes active sessions');
    $worker=$users->find($worker['id']);$setup=$mfa->setup($worker);
    $mfa->enable($worker,$setup,MfaService::otp($setup['secret'])->now());
    $auth->login('worker',$password,'pending-test');$mfa->reset($actors['admin'],$worker['id']);
    verifySecurity(!$auth->pending(),'Reset invalidates pending sign-ins');
    $admin=$users->find($actors['admin']['id']);$adminSetup=$mfa->setup($admin);
    $mfa->enable($admin,$adminSetup,MfaService::otp($adminSetup['secret'])->now());
    $mfa->reset($users->find($admin['id']),$admin['id']);
    verifySecurity(!$mfa->enabled($admin['id']),'Admin can reset own MFA');
    for($i=0;$i<10;$i++)$auth->throttle('test-limit');
    denySecurity(fn()=>$auth->throttle('test-limit'),'Rate limiting enforced');
    $worker=$users->find($worker['id']);$setup=$mfa->setup($worker);
    $mfa->enable($worker,$setup,MfaService::otp($setup['secret'])->now());
    $transfer=new DataTransfer($db);$export=$directory.'/export.json';$transfer->export($export);
    verifySecurity(!str_contains(file_get_contents($export),$setup['secret'])&&!str_contains(file_get_contents($export),'recovery_hashes')&&!str_contains(file_get_contents($export),'user_mfa'),'Portable exports exclude MFA credentials');
    $transfer->import($export,'dev');verifySecurity(!$mfa->enabled($worker['id']),'Dev import clears environment-specific MFA enrollment');
    echo "All security tests passed.\n";
}finally{
    DatabaseSandbox::drop($config,$name);
    foreach(['mfa.key','export.json'] as $file)if(is_file($directory.'/'.$file))unlink($directory.'/'.$file);
    if(is_dir($directory))rmdir($directory);
    $_SESSION=[];session_destroy();ob_end_flush();
}

