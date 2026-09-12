<?php
declare(strict_types=1);
final class SmtpSettings
{
    public function __construct(private string $directory) {}
    public function read(bool $secret=false): array
    {
        $path=$this->directory.'/smtp.json';
        $data=is_file($path)?json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR):[];
        $data+=['host'=>'mail.smtp2go.com','port'=>587,'username'=>'','from_email'=>'','from_name'=>'JJ Project Management','enabled'=>false];
        $data['has_password']=isset($data['password']);
        if($secret&&isset($data['password'])){
            $key=$this->key(false);$bytes=base64_decode($data['password'],true);
            if($bytes===false||strlen($bytes)<28)throw new RuntimeException('Invalid SMTP secret.');
            $plain=openssl_decrypt(substr($bytes,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($bytes,0,12),substr($bytes,12,16));
            if($plain===false)throw new RuntimeException('Cannot decrypt SMTP credentials.');
            $data['password']=$plain;
        }else unset($data['password']);
        return $data;
    }
    public function save(array $input): void
    {
        $username=ProjectRepository::text($input,'username',255);$from=ProjectRepository::email($input,'from_email');
        $name=ProjectRepository::text($input,'from_name',120,true);$password=ProjectRepository::text($input,'password',1000);
        $port=filter_var($input['port']??'',FILTER_VALIDATE_INT);
        if(!in_array($port,[587,2525,8025,25,80],true))throw new DomainException('Choose a supported SMTP2Go STARTTLS port.');
        $enabled=isset($input['enabled']);
        $old=is_file($this->directory.'/smtp.json')?json_decode(file_get_contents($this->directory.'/smtp.json'),true,512,JSON_THROW_ON_ERROR):[];
        if($enabled&&(!$username||!$from||(!$password&&!isset($old['password']))))throw new DomainException('Provide SMTP username, password, and a verified sender email before enabling reminders.');
        $data=['host'=>'mail.smtp2go.com','port'=>$port,'username'=>$username,'from_email'=>$from??'','from_name'=>$name,'enabled'=>$enabled];
        if($password!==''){
            $iv=random_bytes(12);$encrypted=openssl_encrypt($password,'aes-256-gcm',$this->key(true),OPENSSL_RAW_DATA,$iv,$tag);
            if($encrypted===false)throw new RuntimeException('Cannot encrypt SMTP credentials.');
            $data['password']=base64_encode($iv.$tag.$encrypted);
        }elseif(isset($old['password']))$data['password']=$old['password'];
        if(!is_dir($this->directory))mkdir($this->directory,0700,true);
        $temp=$this->directory.'/smtp-'.bin2hex(random_bytes(6)).'.tmp';
        if(file_put_contents($temp,json_encode($data,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('Cannot save SMTP configuration.');
        chmod($temp,0600);
        if(!rename($temp,$this->directory.'/smtp.json'))throw new RuntimeException('Cannot activate SMTP configuration.');
    }
    private function key(bool $create): string
    {
        if(!is_dir($this->directory)&&$create)mkdir($this->directory,0700,true);
        $path=$this->directory.'/smtp.key';
        if(!is_file($path)&&$create){
            $file=fopen($path,'x');if(!$file)throw new RuntimeException('Cannot create SMTP encryption key.');
            fwrite($file,random_bytes(32));fclose($file);chmod($path,0600);
        }
        if(!is_file($path)||filesize($path)!==32)throw new RuntimeException('SMTP encryption key is missing.');
        return file_get_contents($path);
    }
}
