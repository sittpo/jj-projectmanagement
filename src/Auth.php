<?php
declare(strict_types=1);
final class Auth
{
    public function __construct(private PDO $db, private UserRepository $users,private ?MfaService $mfa=null) {}
    public function user(): ?array
    {
        $user=isset($_SESSION['user_id'])?$this->users->find($_SESSION['user_id']):null;
        if(!$user||!(int)$user['active']||(int)$user['session_version']!==($_SESSION['user_version']??0)){
            unset($_SESSION['user_id'],$_SESSION['user_version'],$_SESSION['mfa_setup'],$_SESSION['mfa_recovery']);
            return null;
        }
        return $user;
    }
    public function throttle(string $scope): void
    {
        $key=hash('sha256',$scope);$this->db->prepare('DELETE FROM login_attempts WHERE attempted_at<?')->execute([time()-900]);
        $q=$this->db->prepare('SELECT COUNT(*) FROM login_attempts WHERE attempt_key=?');$q->execute([$key]);
        if((int)$q->fetchColumn()>=10)throw new DomainException('Too many attempts. Please try again in 15 minutes.');
        $this->db->prepare('INSERT INTO login_attempts(id,attempt_key,attempted_at) VALUES(?,?,?)')->execute([Schema::id(),$key,time()]);
    }
    public function confirmPassword(array $user,string $password): void
    {
        $this->throttle('password-confirm:'.$user['id']);
        $fresh=$this->users->find($user['id']);
        if(!$fresh||!$fresh['active']||(int)$fresh['session_version']!==(int)$user['session_version']||!password_verify($password,$fresh['password_hash']))throw new DomainException('Your current password is incorrect or your session changed.');
    }
    public function login(string $username,string $password,string $ip): bool
    {
        unset($_SESSION['mfa_pending']);
        $this->throttle('login:'.$ip);
        $user=$this->users->byUsername($username);
        $valid=password_verify($password,$user['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        if(!$user||!$valid||!(int)$user['active'])return false;
        $this->db->prepare('DELETE FROM login_attempts WHERE attempt_key=?')->execute([hash('sha256','login:'.$ip)]);
        session_regenerate_id(true);
        unset($_SESSION['user_id'],$_SESSION['user_version'],$_SESSION['mfa_setup'],$_SESSION['mfa_recovery']);
        $_SESSION['csrf']=bin2hex(random_bytes(32));
        if($user['mfa_enabled']){
            $_SESSION['mfa_pending']=['id'=>$user['id'],'version'=>(int)$user['session_version'],'expires'=>time()+300];
        }else $this->establish($user);
        return true;
    }
    public function pending(): ?array
    {
        $pending=$_SESSION['mfa_pending']??null;
        $user=$pending?$this->users->find($pending['id']):null;
        if(!$user||!$user['active']||!$user['mfa_enabled']||$pending['expires']<time()||(int)$user['session_version']!==$pending['version']){
            unset($_SESSION['mfa_pending']);return null;
        }
        return $user;
    }
    public function challenge(string $code,string $ip): bool
    {
        $user=$this->pending();
        if(!$user)throw new DomainException('Your sign-in expired. Sign in again.');
        $this->throttle('mfa-ip:'.$ip);$this->throttle('mfa-user:'.$user['id']);
        if(!$this->mfa||!$this->mfa->verify($user['id'],$code))return false;
        if(!$this->pending())throw new DomainException('Your account changed. Sign in again.');
        $this->establish($user);return true;
    }
    private function establish(array $user): void
    {
        session_regenerate_id(true);
        unset($_SESSION['mfa_pending']);
        $_SESSION['user_id']=$user['id'];$_SESSION['user_version']=(int)$user['session_version'];
        $_SESSION['csrf']=bin2hex(random_bytes(32));
    }
    public function logout(): void
    {
        $_SESSION=[];session_regenerate_id(true);
    }
}
