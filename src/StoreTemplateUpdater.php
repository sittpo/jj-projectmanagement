<?php
declare(strict_types=1);
final class StoreTemplateUpdater
{
    public function __construct(private ProjectRepository $project) {}
    public function addMissing(array $actor,array $ids): array
    {
        if(!Access::atLeast($actor,'pm'))throw new AccessDenied('Only a PM or Admin can add missing templates.');
        if(!$ids)throw new DomainException('Select at least one store.');
        foreach($ids as $id)if(!is_string($id)||$id===''||strlen($id)>36)throw new DomainException('Invalid store selection.');
        $ids=array_values(array_unique($ids));sort($ids);
        $p=$this->project;$db=$p->db;$added=0;$updated=0;
        $lock=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $db->beginTransaction();
        try{
            $marks=implode(',',array_fill(0,count($ids),'?'));
            $stores=$p->rows("SELECT * FROM stores WHERE id IN ($marks) ORDER BY id".$lock,$ids);
            if(count($stores)!==count($ids))throw new DomainException('The store selection changed. Reload and select the stores again.');
            $templates=$p->rows('SELECT * FROM task_templates WHERE active=1 ORDER BY ui_order,id');
            foreach($stores as $store){
                $tasks=$p->rows('SELECT * FROM tasks WHERE store_id=? ORDER BY id'.$lock,[$store['id']]);
                $steps=$p->rows('SELECT s.* FROM subtasks s JOIN tasks t ON t.id=s.task_id WHERE t.store_id=? ORDER BY s.id'.$lock,[$store['id']]);
                $existing=array_column($steps,'template_id');
                $missing=array_values(array_filter($templates,fn($template)=>!in_array($template['id'],$existing,true)));
                if(!$missing)continue;
                $updated++;
                $ui=$steps?max(array_column($steps,'ui_order')):0;
                $report=$steps?max(array_column($steps,'report_order')):0;
                $reportTemplates=$missing;
                usort($reportTemplates,fn($a,$b)=>($a['report_order']<=>$b['report_order'])?:strcmp($a['id'],$b['id']));
                $reportPositions=array_flip(array_column($reportTemplates,'id'));
                foreach($missing as $template){
                    $task=null;
                    foreach($tasks as $candidate)if($candidate['category']===$template['category']){$task=$candidate;break;}
                    if(!$task){
                        $task=['id'=>Schema::id(),'category'=>$template['category']];$tasks[]=$task;
                        $p->execute("INSERT INTO tasks(id,store_id,category,status,due_date,created_at) VALUES(?,?,?,'planned',?,?)",[$task['id'],$store['id'],$template['category'],$store['target_date'],gmdate('Y-m-d\TH:i:s\Z')]);
                    }
                    $stepId=Schema::id();
                    $p->execute("INSERT INTO subtasks(id,task_id,template_id,title,instructions,ui_order,report_order,note) VALUES(?,?,?,?,?,?,?,'')",[$stepId,$task['id'],$template['id'],$template['title'],$template['instructions'],++$ui,$report+$reportPositions[$template['id']]+1]);
                    $p->execute("INSERT INTO task_audit(id,subtask_id,actor_id,actor_name,action,details,created_at) VALUES(?,?,?,?,'template-added',?,?)",[Schema::id(),$stepId,$actor['id'],$actor['display_name'],'Added missing template: '.$template['title'],gmdate('Y-m-d\TH:i:s\Z')]);
                    $added++;
                }
            }
            $db->commit();
        }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
        return ['added'=>$added,'updated'=>$updated,'unchanged'=>count($ids)-$updated];
    }
}
