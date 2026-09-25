<?php
declare(strict_types=1);

final class ReminderTemplate
{
    public const FIELDS = ['store_name'=>'Example store','store_code'=>'DEMO-001','address'=>'123 Example Street','post_code'=>'1000','city'=>'Example City','installation_date'=>'2026-10-15','owner_name'=>'Example owner','contact_name'=>'Example contact'];
    public function __construct(private ProjectRepository $project) {}
    public function read(): array
    {
        $rows=$this->project->rows("SELECT setting_value FROM project_settings WHERE setting_key='reminder_template'");
        return $rows ? json_decode($rows[0]['setting_value'],true,512,JSON_THROW_ON_ERROR) : [
            'subject'=>'Upcoming installation: {{store_name}} on {{installation_date}}',
            'body'=>"Hello,\n\nThis is a reminder of the equipment installation at {{store_name}} ({{store_code}}) on {{installation_date}}.\n\nPlease make sure the installation team can access the work area. Contact your project manager if arrangements need to change.\n\nRollout Management"
        ];
    }
    public function validate(array $input): array
    {
        $subject=ProjectRepository::text($input,'subject',200,true);
        $body=ProjectRepository::text($input,'body',10000,true);
        if(preg_match('/[\r\n\x00]/',$subject)||str_contains($body,"\0"))throw new DomainException('The subject must be a single line and the message cannot contain null characters.');
        preg_match_all('/{{(.*?)}}/s',$subject."\n".$body,$matches);
        foreach($matches[1] as $field)if(!array_key_exists($field,self::FIELDS))throw new DomainException('Unknown placeholder: {{'.$field.'}}. Use one of the listed placeholders.');
        return ['subject'=>$subject,'body'=>$body];
    }
    public function save(array $actor,array $input): void
    {
        if(!Access::atLeast($actor,'pm'))throw new AccessDenied('Only a PM or Admin can edit reminder emails.');
        $json=json_encode($this->validate($input),JSON_THROW_ON_ERROR);
        $sql=$this->project->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
            ? "INSERT INTO project_settings(setting_key,setting_value) VALUES('reminder_template',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
            : "INSERT INTO project_settings(setting_key,setting_value) VALUES('reminder_template',?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value";
        $this->project->execute($sql,[$json]);
    }
    public function render(array $store,?array $draft=null): array
    {
        $template=$this->validate($draft??$this->read());
        $values=[];
        foreach(self::FIELDS as $field=>$sample){
            $column=match($field){'store_name'=>'name','store_code'=>'code','installation_date'=>'target_date',default=>$field};
            $values['{{'.$field.'}}']=(string)($store[$column]??'');
        }
        return ['subject'=>preg_replace('/[\r\n\x00]+/',' ',strtr($template['subject'],$values)),'body'=>strtr($template['body'],$values)];
    }
    public static function sampleStore(): array
    {
        $store=self::FIELDS;
        $store['name']=$store['store_name'];$store['code']=$store['store_code'];
        $store['target_date']=(new DateTimeImmutable('today',new DateTimeZone('Europe/Copenhagen')))->modify('+7 days')->format('Y-m-d');
        return $store;
    }
}
