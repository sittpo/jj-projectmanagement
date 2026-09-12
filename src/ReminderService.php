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
    private function send(string $recipient,string $subject,string $body): void
    {
        require_once dirname(__DIR__).'/vendor/autoload.php';
        $settings=$this->settings->read(true);
        if(!$settings['enabled'])throw new DomainException('SMTP reminders are disabled.');
        $mail=new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();$mail->Host=$settings['host'];$mail->Port=(int)$settings['port'];
        $mail->SMTPAuth=true;$mail->Username=$settings['username'];$mail->Password=$settings['password']??'';
        $mail->SMTPSecure=PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Timeout=20;$mail->CharSet='UTF-8';
        $mail->setFrom($settings['from_email'],$settings['from_name']);$mail->addAddress($recipient);
        $mail->Subject=$subject;$mail->Body=$body;$mail->send();
    }
}
