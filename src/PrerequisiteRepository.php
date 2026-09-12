<?php
declare(strict_types=1);
final class PrerequisiteRepository
{
    public function __construct(private ProjectRepository $project) {}
    private function manage(array $actor): void {if(!Access::atLeast($actor,'pm'))throw new AccessDenied('Only a PM or Admin can manage prerequisites.');}
    public function definitions(bool $activeOnly=false): array
    {
        $items=$this->project->rows('SELECT * FROM prerequisites'.($activeOnly?' WHERE active=1':'').' ORDER BY sort_order,id');
        foreach($items as &$item)$item['statuses']=$this->project->rows('SELECT * FROM prerequisite_statuses WHERE prerequisite_id=? ORDER BY sort_order,id',[$item['id']]);
        return $items;
    }
    public function addItem(array $actor,array $input): void
    {
        $this->manage($actor);$name=ProjectRepository::text($input,'name',160,true);$id=Schema::id();$db=$this->project->db;$db->beginTransaction();
        try{
            $order=(int)$db->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM prerequisites')->fetchColumn();
            $this->project->execute('INSERT INTO prerequisites(id,name,sort_order,active) VALUES(?,?,?,1)',[$id,$name,$order]);
            $this->project->execute("INSERT INTO prerequisite_statuses(id,prerequisite_id,name,sort_order,is_default) VALUES(?,?,'Not started',1,1)",[Schema::id(),$id]);$db->commit();
        }catch(Throwable $error){$db->rollBack();throw $error;}
    }
    public function addStatus(array $actor,string $id,array $input): void
    {
        $this->manage($actor);$name=ProjectRepository::text($input,'name',120,true);
        if(!$this->project->rows('SELECT id FROM prerequisites WHERE id=?',[$id]))throw new DomainException('Prerequisite not found.');
        $order=(int)$this->project->rows('SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM prerequisite_statuses WHERE prerequisite_id=?',[$id])[0]['n'];
        $this->project->execute('INSERT INTO prerequisite_statuses(id,prerequisite_id,name,sort_order,is_default) VALUES(?,?,?,?,0)',[Schema::id(),$id,$name,$order]);
    }
    public function save(array $actor,string $id,array $input): void
    {
        $this->manage($actor);$name=ProjectRepository::text($input,'name',160,true);
        $actual=$this->project->rows('SELECT id FROM prerequisite_statuses WHERE prerequisite_id=?',[$id]);
        if(!$actual)throw new DomainException('Prerequisite not found.');
        $ids=$input['status_ids']??[];$sorted=$ids;$expected=array_column($actual,'id');sort($expected);
        if(!is_array($ids))throw new DomainException('Invalid statuses.');sort($sorted);
        if($sorted!==$expected)throw new DomainException('Statuses changed. Reload and try again.');
        $default=ProjectRepository::text($input,'default_status',36,true);
        if(!in_array($default,$ids,true))throw new DomainException('Choose a default status.');
        $rows=[];
        foreach($ids as $statusId){
            $statusName=ProjectRepository::text($input['labels']??[],$statusId,120,true);
            $days=null;
            if(isset($input['attention'][$statusId])){
                $days=filter_var($input['days'][$statusId]??null,FILTER_VALIDATE_INT);
                if($days===false||$days<1||$days>365)throw new DomainException('Attention threshold must be 1–365 working days.');
            }
            $rows[]=[$statusId,$statusName,$days];
        }
        $db=$this->project->db;$db->beginTransaction();
        try{
            $this->project->execute('UPDATE prerequisites SET name=?,active=? WHERE id=?',[$name,isset($input['active'])?1:0,$id]);
            foreach($rows as $i=>[$statusId,$label,$days])$this->project->execute('UPDATE prerequisite_statuses SET name=?,sort_order=?,is_default=?,attention_days=? WHERE id=? AND prerequisite_id=?',[$label,$i+1,$statusId===$default?1:0,$days,$statusId,$id]);
            $db->commit();
        }catch(Throwable $error){$db->rollBack();throw $error;}
    }
    public function setStatus(array $actor,string $storeId,string $itemId,string $statusId): void
    {
        $this->manage($actor);Access::store($this->project->db,$actor,$storeId);
        if(!$this->project->rows('SELECT s.id FROM prerequisite_statuses s JOIN prerequisites p ON p.id=s.prerequisite_id WHERE p.active=1 AND p.id=? AND s.id=?',[$itemId,$statusId]))throw new DomainException('Choose a valid prerequisite status.');
        $sql=$this->project->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
            ?'INSERT INTO store_prerequisites(store_id,prerequisite_id,status_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE status_id=VALUES(status_id)'
            :'INSERT INTO store_prerequisites(store_id,prerequisite_id,status_id) VALUES(?,?,?) ON CONFLICT(store_id,prerequisite_id) DO UPDATE SET status_id=excluded.status_id';
        $this->project->execute($sql,[$storeId,$itemId,$statusId]);
    }
    public function forStore(array $store,?array $definitions=null): array
    {
        $items=$definitions??$this->definitions(true);
        $values=array_column($this->project->rows('SELECT prerequisite_id,status_id FROM store_prerequisites WHERE store_id=?',[$store['id']]),'status_id','prerequisite_id');
        foreach($items as &$item){
            $selected=$values[$item['id']]??null;
            $item['status']=null;
            foreach($item['statuses'] as $status)if($status['id']===$selected)$item['status']=$status;
            if(!$item['status'])foreach($item['statuses'] as $status)if($status['is_default']){$item['status']=$status;break;}
        }
        return $items;
    }
    public static function issue(array $store,array $item,string $today): ?string
    {
        if($store['finished']||$item['status']['attention_days']===null)return null;
        if(!$store['target_date'])return $item['name'].' · Installation date needed to assess readiness';
        $days=ProjectRepository::workingDaysUntil($today,$store['target_date']);
        return $days<(int)$item['status']['attention_days']?$item['name'].' · '.$item['status']['name'].' · '.$days.' working days to installation':null;
    }
}
