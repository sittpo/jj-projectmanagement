<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/vendor/autoload.php';

final class MfaService
{
    public function __construct(private PDO $db,private string $directory) {}
    public function enabled(string $id): bool
    {
        $q=$this->db->prepare('SELECT user_id FROM user_mfa WHERE user_id=?');$q->execute([$id]);
        return (bool)$q->fetchColumn();
    }
    public static function otp(string $secret): OTPHP\TOTP
    {
        return OTPHP\TOTP::create($secret,30,'sha1',6);
    }
    public function setup(array $user): array
    {
        if($this->enabled($user['id']))throw new DomainException('MFA is already enabled.');
        $otp=OTPHP\TOTP::create();$otp->setLabel($user['username']);$otp->setIssuer('Rollout Management');
        $uri=$otp->getProvisioningUri();
        $renderer=new BaconQrCode\Renderer\ImageRenderer(new BaconQrCode\Renderer\RendererStyle\RendererStyle(260,4),new BaconQrCode\Renderer\Image\SvgImageBackEnd());
        return ['secret'=>$otp->getSecret(),'qr'=>'data:image/svg+xml;base64,'.base64_encode((new BaconQrCode\Writer($renderer))->writeString($uri)),'expires'=>time()+600,'version'=>(int)$user['session_version']];
    }
    private function counter(string $secret,string $code,int $last): ?int
    {
        if(!preg_match('/^[0-9]{6}$/D',$code))return null;
        $otp=self::otp($secret);$now=intdiv(time(),30);
        foreach([$now,$now-1,$now+1] as $counter)if($counter>$last&&hash_equals($otp->at($counter*30),$code))return $counter;
        return null;
    }
    public function enable(array $user,array $setup,string $code): array
    {
        if(($setup['expires']??0)<time()||($setup['version']??0)!==(int)$user['session_version'])throw new DomainException('Setup expired. Start again.');
        $counter=$this->counter($setup['secret'],$code,-1);
        if($counter===null)throw new DomainException('The authenticator code is incorrect. Check your device time and try again.');
        $codes=[];for($i=0;$i<8;$i++)$codes[]=bin2hex(random_bytes(8));
        $hashes=array_map(fn($code)=>hash('sha256',$code),$codes);
        $encrypted=$this->encrypt($setup['secret'],$user['id']);
        $this->db->beginTransaction();
        try{
            $q=$this->db->prepare('UPDATE users SET session_version=session_version+1 WHERE id=? AND session_version=? AND active=1');
            $q->execute([$user['id'],$user['session_version']]);
            if(!$q->rowCount()||$this->enabled($user['id']))throw new DomainException('Your account changed. Reload and try again.');
            $q=$this->db->prepare('INSERT INTO user_mfa(user_id,secret,recovery_hashes,last_counter,enabled_at) VALUES(?,?,?,?,?)');
            $q->execute([$user['id'],$encrypted,json_encode($hashes,JSON_THROW_ON_ERROR),$counter,gmdate('Y-m-d\TH:i:s\Z')]);
            $this->db->commit();
        }catch(Throwable $error){$this->db->rollBack();throw $error;}
        return $codes;
    }
    public function verify(string $id,string $code): bool
    {
        $q=$this->db->prepare('SELECT * FROM user_mfa WHERE user_id=?');$q->execute([$id]);$row=$q->fetch();
        if(!$row)return false;
        $code=trim($code);
        $counter=preg_match('/^[0-9]{6}$/D',$code)?$this->counter($this->decrypt($row['secret'],$id),$code,(int)$row['last_counter']):null;
        if($counter!==null){
            $q=$this->db->prepare('UPDATE user_mfa SET last_counter=? WHERE user_id=? AND last_counter=? AND secret=?');
            $q->execute([$counter,$id,$row['last_counter'],$row['secret']]);return $q->rowCount()===1;
        }
        if(!preg_match('/^[a-f0-9]{16}$/Di',$code))return false;
        $hashes=json_decode($row['recovery_hashes'],true,512,JSON_THROW_ON_ERROR);$match=null;
        foreach($hashes as $i=>$hash)if(hash_equals($hash,hash('sha256',strtolower($code))))$match=$i;
        if($match===null)return false;
        unset($hashes[$match]);
        $q=$this->db->prepare('UPDATE user_mfa SET recovery_hashes=? WHERE user_id=? AND recovery_hashes=? AND secret=?');
        $q->execute([json_encode(array_values($hashes),JSON_THROW_ON_ERROR),$id,$row['recovery_hashes'],$row['secret']]);return $q->rowCount()===1;
    }
    public function reset(array $actor,string $id): void
    {
        $this->db->beginTransaction();
        try{
            $lock=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $ids=array_values(array_unique([$actor['id'],$id]));sort($ids);
            $q=$this->db->prepare('SELECT * FROM users WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY id'.$lock);$q->execute($ids);
            $rows=array_column($q->fetchAll(),null,'id');$fresh=$rows[$actor['id']]??null;$target=$rows[$id]??null;
            if(!$fresh||!$fresh['active']||(int)$fresh['session_version']!==(int)$actor['session_version']||!$target||!UserRepository::canManage($fresh,$target))throw new AccessDenied('You cannot reset MFA for this user.');
            $this->db->prepare('DELETE FROM user_mfa WHERE user_id=?')->execute([$id]);
            $this->db->prepare('UPDATE users SET session_version=session_version+1 WHERE id=?')->execute([$id]);
            $this->db->commit();
        }catch(Throwable $error){$this->db->rollBack();throw $error;}
    }
    private function key(bool $create): string
    {
        if(!is_dir($this->directory)&&$create&&!mkdir($this->directory,0700,true))throw new RuntimeException('Cannot create MFA key directory.');
        $path=$this->directory.'/mfa.key';
        if(!is_file($path)&&$create){
            $file=@fopen($path,'x');
            if($file){fwrite($file,random_bytes(32));fclose($file);chmod($path,0600);}
        }
        $key=is_file($path)?file_get_contents($path):false;
        if($key===false||strlen($key)!==32)throw new RuntimeException('MFA encryption key is unavailable.');
        return $key;
    }
    private function encrypt(string $secret,string $id): string
    {
        $iv=random_bytes(12);$encrypted=openssl_encrypt($secret,'aes-256-gcm',$this->key(true),OPENSSL_RAW_DATA,$iv,$tag,$id);
        if($encrypted===false)throw new RuntimeException('Cannot encrypt MFA secret.');
        return base64_encode($iv.$tag.$encrypted);
    }
    private function decrypt(string $encrypted,string $id): string
    {
        $bytes=base64_decode($encrypted,true);
        if($bytes===false||strlen($bytes)<29)throw new RuntimeException('Invalid MFA secret.');
        $secret=openssl_decrypt(substr($bytes,28),'aes-256-gcm',$this->key(false),OPENSSL_RAW_DATA,substr($bytes,0,12),substr($bytes,12,16),$id);
        if($secret===false)throw new RuntimeException('Cannot decrypt MFA secret.');
        return $secret;
    }
}
