<?php
declare(strict_types=1);
final class ReminderService
{
    public function __construct(private ProjectRepository $project,private SmtpSettings $settings) {}
    public function due(string $today): array
    {
        $jobs=[];
        foreach($this->project->rows('SELECT * FROM stores WHERE reminders_enabled=1 AND target_date>=?',[$today]) as $store){
            $date=$this->project->reminderDate($store);
            if(!$date||$date>$today)continue;
            foreach(array_unique(array_filter([$store['owner_email'],$store['contact_email']])) as $recipient){
                $existing=$this->project->rows('SELECT id FROM reminder_log WHERE store_id=? AND recipient=? AND installation_date=? AND scheduled_for=?',[$store['id'],$recipient,$store['target_date'],$date]);
                if(!$existing)$jobs[]=['store'=>$store,'recipient'=>$recipient,'scheduled_for'=>$date];
            }
        }
        return $jobs;
    }
    public function run(bool $send=false,?callable $transport=null): array
    {
        $today=(new DateTimeImmutable('now',new DateTimeZone('Europe/Copenhagen')))->format('Y-m-d');
        $jobs=$this->due($today);$results=[];
        if(!$send)return array_map(fn($job)=>['store'=>$job['store']['code'],'recipient'=>$job['recipient'],'status'=>'due','scheduled_for'=>$job['scheduled_for']],$jobs);
        if(!$transport&&!$this->settings->read()['enabled'])throw new DomainException('SMTP reminders are disabled.');
        foreach($jobs as $job){
            $id=Schema::id();$now=gmdate('Y-m-d\TH:i:s\Z');$store=$job['store'];
            try{
                $this->project->execute("INSERT INTO reminder_log(id,store_id,recipient,installation_date,scheduled_for,status,created_at) VALUES (?,?,?,?,?,'sending',?)",[$id,$store['id'],$job['recipient'],$store['target_date'],$job['scheduled_for'],$now]);
            }catch(PDOException $error){if((string)$error->getCode()==='23000')continue;throw $error;}
            try{
                $subject='Upcoming installation: '.$store['name'].' on '.$store['target_date'];
                $body="Hello,\n\nThis is a reminder of the equipment installation at ".$store['name']." (".$store['code'].") on ".$store['target_date'].".\n\nPlease make sure the installation team can access the work area. Contact your project manager if arrangements need to change.\n\nJJ Project Management";
                if($transport)$transport($job['recipient'],$subject,$body);else $this->send($job['recipient'],$subject,$body);
                $this->project->execute("UPDATE reminder_log SET status='sent',sent_at=? WHERE id=?",[gmdate('Y-m-d\TH:i:s\Z'),$id]);$status='sent';
            }catch(Throwable $error){
                // SMTP acceptance can be ambiguous on a dropped connection. Never retry automatically.
                $this->project->execute("UPDATE reminder_log SET status='failed',error=? WHERE id=?",['SMTP attempt failed or acceptance could not be confirmed. Check SMTP2Go activity before retrying.',$id]);
                error_log('Reminder '.$id.' failed: '.get_class($error));$status='failed';
            }
            $results[]=['store'=>$store['code'],'recipient'=>$job['recipient'],'status'=>$status];
        }
        return $results;
    }
    public function manual(array $actor,string $storeId,string $requestId,?callable $transport=null): array
    {
        if(!Access::atLeast($actor,'pm'))throw new AccessDenied('Only a PM or Admin can send store reminders.');
        $store=Access::store($this->project->db,$actor,$storeId);
        if(!preg_match('/^[a-f0-9]{32}$/D',$requestId))throw new DomainException('Invalid reminder request. Reload the store.');
        if(!$store['target_date'])throw new DomainException('Set an installation date before sending a reminder.');
        $recipients=array_values(array_unique(array_map('strtolower',array_filter([$store['owner_email'],$store['contact_email']]))));
        if(!$recipients)throw new DomainException('Add an owner or contact email address before sending a reminder.');
        if(!$transport)$this->configured(false);
        $results=[];
        foreach($recipients as $recipient){
            $id=Schema::id();$now=gmdate('Y-m-d\TH:i:s\Z');
            try{
                $this->project->execute("INSERT INTO manual_reminder_log(id,store_id,actor_id,actor_name,request_id,recipient,installation_date,scheduled_for,status,created_at) VALUES(?,?,?,?,?,?,?,?,'sending',?)",
                    [$id,$storeId,$actor['id'],$actor['display_name'],$requestId,$recipient,$store['target_date'],substr($now,0,10),$now]);
            }catch(PDOException $error){if((string)$error->getCode()==='23000')continue;throw $error;}
            try{
                $subject='Installation reminder: '.$store['name'].' on '.$store['target_date'];
                $body="Hello,\n\nThis is a reminder of the equipment installation at ".$store['name']." (".$store['code'].") on ".$store['target_date'].".\n\nPlease make sure the installation team can access the work area. Contact your project manager if arrangements need to change.\n\nJJ Project Management";
                if($transport)$transport($recipient,$subject,$body);else $this->send($recipient,$subject,$body,false);
                $this->project->execute("UPDATE manual_reminder_log SET status='sent',sent_at=? WHERE id=?",[gmdate('Y-m-d\TH:i:s\Z'),$id]);$status='sent';
            }catch(Throwable $error){
                $this->project->execute("UPDATE manual_reminder_log SET status='failed',error=? WHERE id=?",['SMTP attempt failed or acceptance could not be confirmed. Check SMTP2Go activity before sending again.',$id]);
                error_log('Manual reminder '.$id.' failed: '.get_class($error));$status='failed';
            }
            $results[]=['recipient'=>$recipient,'status'=>$status];
        }
        return $results;
    }
    public function test(string $recipient,?callable $transport=null): void
    {
        if(!filter_var($recipient,FILTER_VALIDATE_EMAIL)||strlen($recipient)>254)throw new DomainException('Enter a valid test recipient email.');
        if(!$transport)$this->configured(false);
        $subject='JJ Project Management — SMTP test';
        $body="This test message confirms that JJ Project Management can submit email through the saved SMTP connection.\n\nNo store reminder was triggered.";
        try{
            if($transport)$transport($recipient,$subject,$body);else $this->send($recipient,$subject,$body,false);
        }catch(Throwable $error){error_log('SMTP test failed: '.get_class($error));throw new DomainException('SMTP test failed or acceptance could not be confirmed. Check the saved credentials, verified sender, and SMTP2Go activity.');}
    }
    private function configured(bool $enabled): void
    {
        $settings=$this->settings->read();
        if($enabled&&!$settings['enabled'])throw new DomainException('SMTP reminders are disabled.');
        if(!$settings['username']||!$settings['from_email']||!$settings['has_password'])throw new DomainException('Save the SMTP username, password, and sender email in SMTP settings first.');
    }
    private function send(string $recipient,string $subject,string $body,bool $requireEnabled=true): void
    {
        require_once dirname(__DIR__).'/vendor/autoload.php';
        $settings=$this->settings->read(true);
        $this->configured($requireEnabled);
        $mail=new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();$mail->Host=$settings['host'];$mail->Port=(int)$settings['port'];
        $mail->SMTPAuth=true;$mail->Username=$settings['username'];$mail->Password=$settings['password']??'';
        $mail->SMTPSecure=PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Timeout=20;$mail->CharSet='UTF-8';
        $mail->setFrom($settings['from_email'],$settings['from_name']);$mail->addAddress($recipient);
        $mail->Subject=$subject;$mail->Body=$body;$mail->send();
    }
}
