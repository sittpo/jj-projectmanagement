<?php
declare(strict_types=1);
if($page==='mfa-login'){
    if(!$auth->pending())redirect('login');
    if($isPost){
        try{
            if($auth->challenge(ProjectRepository::text($_POST,'code',64,true),$_SERVER['REMOTE_ADDR']??'unknown'))redirect('dashboard');
            $error='The code is incorrect or has already been used. Try a new code or a recovery code.';
        }catch(DomainException $exception){$error=$exception->getMessage();}
        catch(Throwable $exception){error_log('MFA verification unavailable: '.get_class($exception));$error='Verification is unavailable. Contact an administrator.';}
    }
}
if($page==='security'){
    if($isPost){
        try{
            $action=ProjectRepository::text($_POST,'action',30,true);
            if($action==='start'){
                $auth->confirmPassword($user,(is_string($_POST['password']??null)?$_POST['password']:''));
                $_SESSION['mfa_setup']=$mfa->setup($user);redirect('security');
            }elseif($action==='cancel'){
                unset($_SESSION['mfa_setup']);redirect('security');
            }elseif($action==='enable'){
                $auth->throttle('mfa-enroll:'.$user['id']);
                $codes=$mfa->enable($user,$_SESSION['mfa_setup']??[],ProjectRepository::text($_POST,'code',6,true));
                unset($_SESSION['mfa_setup']);
                $_SESSION['user_version']=(int)$users->find($user['id'])['session_version'];
                session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));
                $_SESSION['mfa_recovery']=$codes;$_SESSION['flash']='MFA enabled. Save your recovery codes now.';redirect('security');
            }else throw new DomainException('Unknown security action.');
        }catch(DomainException $exception){$error=$exception->getMessage();}
        catch(Throwable $exception){error_log('MFA setup unavailable: '.get_class($exception));$error='MFA setup could not be completed. Please contact an administrator.';}
    }
    $setup=$_SESSION['mfa_setup']??null;
    if($setup&&($setup['expires']<time()||$setup['version']!==(int)$user['session_version'])){
        unset($_SESSION['mfa_setup']);$setup=null;$error='Setup expired. Start again.';
    }
    $recoveryCodes=$_SESSION['mfa_recovery']??[];unset($_SESSION['mfa_recovery']);
}
if($page==='user-mfa-reset'){
    if(!$isPost){http_response_code(405);exit('Use the reset MFA form.');}
    try{
        $id=ProjectRepository::text($_POST,'id',36,true);
        $target=$users->find($id);
        if(!$target||!UserRepository::canManage($user,$target))throw new AccessDenied('You cannot reset MFA for this user.');
        if(($_POST['confirmed']??'')!=='1')throw new DomainException('Confirm the MFA reset.');
        $auth->confirmPassword($user,(is_string($_POST['password']??null)?$_POST['password']:''));
        $mfa->reset($user,$id);
        unset($_SESSION['mfa_setup'],$_SESSION['mfa_recovery']);
        if($id===$user['id']){$auth->logout();redirect('login');}
        $_SESSION['flash']='MFA reset. Existing sessions and recovery codes were revoked. The user can set up MFA again.';redirect('users');
    }catch(AccessDenied $exception){http_response_code(403);$page='forbidden';}
    catch(DomainException $exception){$_SESSION['flash_error']=$exception->getMessage();redirect('user-edit',['id'=>$id??'']);}
}
