<?php
declare(strict_types=1);
require_once __DIR__.'/PrerequisiteRepository.php';
final class ProjectRepository
{
    public const UNIFI_STATES=['not_ordered'=>'Not ordered','ordered'=>'Ordered','shipped'=>'Shipped','delivered'=>'Delivered'];
    public function setUnifiOrder(array $actor,string $storeId,string $state): void
    {
        if(!Access::atLeast($actor,'pm'))throw new AccessDenied('Only a PM or Admin can update UniFi orders.');
        Access::store($this->db,$actor,$storeId);
        if(!isset(self::UNIFI_STATES[$state]))throw new DomainException('Choose a valid UniFi order state.');
        (new PrerequisiteRepository($this))->setStatus($actor,$storeId,'unifi',$state);
        $this->execute('UPDATE stores SET unifi_order=? WHERE id=?',[$state,$storeId]);
    }
    public static function workingDaysUntil(string $today,string $installation): int
    {
        $start=new DateTimeImmutable($today);$end=new DateTimeImmutable($installation);
        if($end<=$start)return 0;
        $days=(int)$start->diff($end)->days;$weeks=intdiv($days,7);$count=$weeks*5;
        for($i=1;$i<=$days%7;$i++)if((int)$start->modify('+'.$i.' days')->format('N')<=5)$count++;
        return $count;
    }
    public static function unifiAttention(array $store,string $today): ?string
    {
        if($store['finished']||!$store['target_date'])return null;
        $days=self::workingDaysUntil($today,$store['target_date']);
        $state=$store['unifi_order']??'not_ordered';
        if($days<3&&$state!=='delivered')return 'UniFi not delivered · '.$days.' working days to installation';
        if($days<7&&$state==='not_ordered')return 'UniFi not ordered · '.$days.' working days to installation';
        return null;
    }
    public const CATEGORIES=['network'=>'Networking','audio'=>'Audio','dvr'=>'DVR','rack'=>'Rack cabinet'];
    public function __construct(public PDO $db) {}
    public function rows(string $sql,array $args=[]): array { $q=$this->db->prepare($sql);$q->execute($args);return $q->fetchAll(); }
    public function execute(string $sql,array $args=[]): void { $this->db->prepare($sql)->execute($args); }
    public function companies(): array { return $this->rows('SELECT * FROM companies ORDER BY name'); }
    public function companySave(array $input,?string $id): string
    {
        $name=self::text($input,'name',160,true);$email=self::email($input,'email');$phone=self::text($input,'phone',60);
        if ($id) {
            if (!$this->rows('SELECT id FROM companies WHERE id=?',[$id])) throw new DomainException('Company not found.');
            $this->execute('UPDATE companies SET name=?,email=?,phone=?,active=? WHERE id=?',[$name,$email,$phone,isset($input['active'])?1:0,$id]);
        } else {
            $id=Schema::id();$this->execute('INSERT INTO companies(id,name,email,phone,active) VALUES (?,?,?,?,?)',[$id,$name,$email,$phone,isset($input['active'])?1:0]);
        }
        return $id;
    }
    public function stores(array $user): array
    {
        $sql='SELECT s.*,c.name AS company_name FROM stores s LEFT JOIN companies c ON c.id=s.company_id';
        if (Access::atLeast($user,'pm')) return $this->rows($sql.' ORDER BY s.name');
        if (!$user['company_id']) return [];
        if ($user['role']==='contractor_admin') return $this->rows($sql.' WHERE s.target_date IS NOT NULL AND s.target_date<>\'\' AND s.company_id=? ORDER BY s.name',[$user['company_id']]);
        return $this->rows($sql.' WHERE s.target_date IS NOT NULL AND s.target_date<>\'\' AND s.company_id=? AND EXISTS(SELECT 1 FROM store_assignments a WHERE a.store_id=s.id AND a.user_id=?) ORDER BY s.name',[$user['company_id'],$user['id']]);
    }
    public function showAllStores(string $userId): bool
    {
        return (bool)($this->rows('SELECT show_all_stores FROM user_preferences WHERE user_id=?',[$userId])[0]['show_all_stores']??false);
    }
    public function saveStorePreference(string $userId,bool $showAll): void
    {
        $sql=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
            ?'INSERT INTO user_preferences(user_id,show_all_stores) VALUES(?,?) ON DUPLICATE KEY UPDATE show_all_stores=VALUES(show_all_stores)'
            :'INSERT INTO user_preferences(user_id,show_all_stores) VALUES(?,?) ON CONFLICT(user_id) DO UPDATE SET show_all_stores=excluded.show_all_stores';
        $this->execute($sql,[$userId,(int)$showAll]);
    }
    public function storeListing(array $user,bool $showAll=false,string $query=''): array
    {
        // Checklist sign-off is authoritative; legacy stores without checklists use task status.
        $sql="SELECT s.*,c.name AS company_name,
            CASE WHEN EXISTS(SELECT 1 FROM tasks t JOIN subtasks st ON st.task_id=t.id WHERE t.store_id=s.id)
            THEN NOT EXISTS(SELECT 1 FROM tasks t JOIN subtasks st ON st.task_id=t.id WHERE t.store_id=s.id AND (st.complete=0 OR st.signed_at IS NULL))
            ELSE EXISTS(SELECT 1 FROM tasks t WHERE t.store_id=s.id) AND NOT EXISTS(SELECT 1 FROM tasks t WHERE t.store_id=s.id AND t.status<>'completed')
            END AS finished FROM stores s LEFT JOIN companies c ON c.id=s.company_id";
        $conditions=[];$args=[];
        if(!Access::atLeast($user,'pm')){
            if(!$user['company_id'])return [];
            $conditions[]="s.target_date IS NOT NULL AND s.target_date<>''";
            $conditions[]='s.company_id=?';$args[]=$user['company_id'];
            if($user['role']!=='contractor_admin'){
                $conditions[]='EXISTS(SELECT 1 FROM store_assignments a WHERE a.store_id=s.id AND a.user_id=?)';$args[]=$user['id'];
            }
        }
        if($query!==''){
            $pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],mb_strtolower($query)).'%';
            $conditions[]="(LOWER(s.name) LIKE ? ESCAPE '!' OR LOWER(s.code) LIKE ? ESCAPE '!' OR LOWER(s.id) LIKE ? ESCAPE '!')";
            array_push($args,$pattern,$pattern,$pattern);
        }
        if($conditions)$sql.=' WHERE '.implode(' AND ',$conditions);
        $sql='SELECT * FROM ('.$sql.') listing'.($showAll?'':' WHERE finished=0').' ORDER BY CASE WHEN target_date IS NULL THEN 1 ELSE 0 END,target_date,name,id';
        return $this->rows($sql,$args);
    }
    public function pmNotes(array $actor,string $storeId,bool $reportOnly=false): array
    {
        Access::store($this->db,$actor,$storeId);
        if(!$reportOnly&&!Access::atLeast($actor,'pm'))throw new AccessDenied('Only a PM or Admin can manage Project Manager notes.');
        return $this->rows('SELECT * FROM store_pm_notes WHERE store_id=?'.($reportOnly?' AND include_in_report=1':'').' ORDER BY created_at,id',[$storeId]);
    }
    public function addPmNote(array $actor,string $storeId,array $input): string
    {
        if(!Access::atLeast($actor,'pm'))throw new AccessDenied('Only a PM or Admin can add Project Manager notes.');
        Access::store($this->db,$actor,$storeId);
        $note=self::text($input,'pm_note',20000,true);$id=Schema::id();
        $this->execute('INSERT INTO store_pm_notes(id,store_id,author_id,author_name,note,created_at,include_in_report) VALUES(?,?,?,?,?,?,?)',
            [$id,$storeId,$actor['id'],$actor['display_name'],$note,gmdate('Y-m-d\TH:i:s\Z'),isset($input['include_in_report'])?1:0]);
        return $id;
    }
    public function setPmNoteReport(array $actor,string $storeId,string $noteId,bool $include): void
    {
        if(!Access::atLeast($actor,'pm'))throw new AccessDenied('Only a PM or Admin can manage Project Manager notes.');
        Access::store($this->db,$actor,$storeId);
        if(!$this->rows('SELECT id FROM store_pm_notes WHERE id=? AND store_id=?',[$noteId,$storeId]))throw new DomainException('Note not found for this store.');
        $this->execute('UPDATE store_pm_notes SET include_in_report=? WHERE id=? AND store_id=?',[(int)$include,$noteId,$storeId]);
    }
    public function deletePmNote(array $actor,string $storeId,string $noteId): void
    {
        if(!Access::atLeast($actor,'pm'))throw new AccessDenied('Only a PM or Admin can delete Project Manager notes.');
        Access::store($this->db,$actor,$storeId);
        if(!$this->rows('SELECT id FROM store_pm_notes WHERE id=? AND store_id=?',[$noteId,$storeId]))throw new DomainException('Note not found for this store.');
        $this->execute('DELETE FROM store_pm_notes WHERE id=? AND store_id=?',[$noteId,$storeId]);
    }
    public function templates(): array { return $this->rows('SELECT * FROM task_templates ORDER BY ui_order,id'); }
    public function templateSave(array $input,?string $id): void
    {
        $category=self::text($input,'category',20,true);
        if (!isset(self::CATEGORIES[$category])) throw new DomainException('Choose a workstream.');
        $title=self::text($input,'title',200,true);$instructions=self::text($input,'instructions',10000);
        if ($id && !$this->rows('SELECT id FROM task_templates WHERE id=?',[$id])) throw new DomainException('Template not found.');
        if ($id) $this->execute('UPDATE task_templates SET category=?,title=?,instructions=?,active=? WHERE id=?',[$category,$title,$instructions,isset($input['active'])?1:0,$id]);
        else {
            $order=(int)$this->db->query('SELECT COALESCE(MAX(ui_order),0)+1 FROM task_templates')->fetchColumn();
            $report=(int)$this->db->query('SELECT COALESCE(MAX(report_order),0)+1 FROM task_templates')->fetchColumn();
            $this->execute('INSERT INTO task_templates(id,category,title,instructions,ui_order,report_order,active) VALUES (?,?,?,?,?,?,?)',[Schema::id(),$category,$title,$instructions,$order,$report,isset($input['active'])?1:0]);
        }
    }
    public function reorder(array $ids,string $kind): void
    {
        if (!in_array($kind,['ui_order','report_order'],true)) throw new DomainException('Invalid ordering.');
        $actual=array_column($this->templates(),'id');$submitted=$ids;sort($actual);sort($submitted);
        if ($actual!==$submitted) throw new DomainException('The template list changed. Reload before saving the order.');
        $this->db->beginTransaction();
        try { foreach ($ids as $index=>$id) $this->execute("UPDATE task_templates SET $kind=? WHERE id=?",[$index+1,$id]);$this->db->commit(); }
        catch(Throwable $error){$this->db->rollBack();throw $error;}
    }
    public function reminderDays(): int { return (int)($this->rows("SELECT setting_value FROM project_settings WHERE setting_key='reminder_days'")[0]['setting_value']??7); }
    public function reminderDate(array $store): ?string {
        if (!$store['target_date']) return null;
        return $store['reminder_date'] ?: (new DateTimeImmutable($store['target_date']))->modify('-'.$this->reminderDays().' days')->format('Y-m-d');
    }
    public function storeSave(array $input,?string $id): string
    {
        $code=self::text($input,'code',40,true);$name=self::text($input,'name',160,true);$city=self::text($input,'city',120,true);
        $company=self::text($input,'company_id',36)?:null;
        $target=self::date($input,'target_date');$reminder=self::date($input,'reminder_date');
        if ($reminder && (!$target || $reminder>$target)) throw new DomainException('The reminder date must be on or before the installation date.');
        if ($company && !$this->rows('SELECT id FROM companies WHERE id=? AND active=1',[$company])) throw new DomainException('Choose an active contracting company.');
        $assigned=$input['contractors']??[];
        if (!is_array($assigned)) throw new DomainException('Invalid contractor selection.');
        $assigned=array_values(array_unique($assigned));
        foreach ($assigned as $contractor) {
            if (!$company || !is_string($contractor) || !$this->rows("SELECT id FROM users WHERE id=? AND company_id=? AND role IN ('contractor','contractor_admin') AND active=1",[$contractor,$company])) throw new DomainException('Assigned contractors must be active members of the selected company.');
        }
        $fields=[$code,$name,$city,$target,$company,self::text($input,'owner_name',120,true),self::text($input,'owner_phone',60),self::email($input,'owner_email'),self::text($input,'contact_name',120),self::text($input,'contact_phone',60),self::email($input,'contact_email'),$reminder,isset($input['reminders_enabled'])?1:0];
        $this->db->beginTransaction();
        try {
            if ($id) {
                if (!$this->rows('SELECT id FROM stores WHERE id=?',[$id])) throw new DomainException('Store not found.');
                $this->execute('UPDATE stores SET code=?,name=?,city=?,target_date=?,company_id=?,owner_name=?,owner_phone=?,owner_email=?,contact_name=?,contact_phone=?,contact_email=?,reminder_date=?,reminders_enabled=? WHERE id=?',[...$fields,$id]);
                $this->execute('DELETE FROM store_assignments WHERE store_id=?',[$id]);
            } else {
                $id=Schema::id();
                $this->execute('INSERT INTO stores(code,name,city,target_date,company_id,owner_name,owner_phone,owner_email,contact_name,contact_phone,contact_email,reminder_date,reminders_enabled,id,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[...$fields,$id,gmdate('Y-m-d\TH:i:s\Z')]);
                foreach (self::CATEGORIES as $category=>$label) {
                    $task=Schema::id();$this->execute("INSERT INTO tasks(id,store_id,category,status,due_date,created_at) VALUES (?,?,?,'planned',?,?)",[$task,$id,$category,$target,gmdate('Y-m-d\TH:i:s\Z')]);
                    foreach ($this->rows('SELECT * FROM task_templates WHERE category=? AND active=1',[$category]) as $template) {
                        $this->execute("INSERT INTO subtasks(id,task_id,template_id,title,instructions,ui_order,report_order,note) VALUES (?,?,?,?,?,?,?,'')",[Schema::id(),$task,$template['id'],$template['title'],$template['instructions'],$template['ui_order'],$template['report_order']]);
                    }
                }
            }
            foreach ($assigned as $contractor) $this->execute('INSERT INTO store_assignments(store_id,user_id) VALUES (?,?)',[$id,$contractor]);
            $this->execute('UPDATE tasks SET due_date=? WHERE store_id=?',[$target,$id]);
            $this->db->commit();
        } catch(Throwable $error){$this->db->rollBack();throw $error;}
        return $id;
    }
    public static function text(array $input,string $key,int $max,bool $required=false): string {
        if (isset($input[$key]) && !is_string($input[$key])) throw new DomainException('Invalid field: '.$key);
        $value=trim($input[$key]??'');
        if (($required && $value==='') || mb_strlen($value)>$max) throw new DomainException(ucfirst(str_replace('_',' ',$key)).' is required or exceeds '.$max.' characters.');
        return $value;
    }
    public static function email(array $input,string $key): ?string { $value=self::text($input,$key,254);if($value!==''&&!filter_var($value,FILTER_VALIDATE_EMAIL))throw new DomainException('Enter a valid email address.');return $value?:null; }
    public static function date(array $input,string $key): ?string {
        $value=self::text($input,$key,10);if($value==='')return null;
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if(!$date||$date->format('Y-m-d')!==$value)throw new DomainException('Enter a valid date.');
        return $value;
    }
}
