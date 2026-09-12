<?php
declare(strict_types=1);
final class StoreDeletion
{
    public function __construct(private ProjectRepository $project,private string $uploadRoot) {}
    public function delete(array $actor,array $ids): array
    {
        if(!Access::atLeast($actor,'pm'))throw new AccessDenied('Only a PM or Admin can delete stores.');
        if(!$ids)throw new DomainException('Select at least one store.');
        foreach($ids as $id)if(!is_string($id)||$id===''||strlen($id)>36)throw new DomainException('Invalid store selection.');
        $ids=array_values(array_unique($ids));sort($ids);$db=$this->project->db;$files=[];
        $db->beginTransaction();
        try{
            $marks=implode(',',array_fill(0,count($ids),'?'));$lock=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $stores=$this->project->rows("SELECT id FROM stores WHERE id IN ($marks) ORDER BY id".$lock,$ids);
            if(count($stores)!==count($ids))throw new DomainException('The store selection changed. Reload and select the stores again.');
            foreach($ids as $id){
                $this->project->rows('SELECT id FROM tasks WHERE store_id=? ORDER BY id'.$lock,[$id]);
                $this->project->rows('SELECT s.id FROM subtasks s JOIN tasks t ON t.id=s.task_id WHERE t.store_id=? ORDER BY s.id'.$lock,[$id]);
                foreach($this->project->rows('SELECT p.storage_key FROM subtask_photos p JOIN subtasks s ON s.id=p.subtask_id JOIN tasks t ON t.id=s.task_id WHERE t.store_id=?',[$id]) as $photo)$files[]=$photo['storage_key'];
                foreach($this->project->rows('SELECT p.storage_key FROM task_photos p JOIN tasks t ON t.id=p.task_id WHERE t.store_id=?',[$id]) as $photo)$files[]=$photo['storage_key'];
                foreach(['task_audit','subtask_photos'] as $table)$this->project->execute("DELETE FROM $table WHERE subtask_id IN (SELECT s.id FROM subtasks s JOIN tasks t ON t.id=s.task_id WHERE t.store_id=?)",[$id]);
                $this->project->execute('DELETE FROM subtasks WHERE task_id IN (SELECT id FROM tasks WHERE store_id=?)',[$id]);
                $this->project->execute('DELETE FROM task_photos WHERE task_id IN (SELECT id FROM tasks WHERE store_id=?)',[$id]);
                foreach(['tasks','store_assignments','reminder_log','manual_reminder_log','store_pm_notes','store_prerequisites'] as $table)$this->project->execute("DELETE FROM $table WHERE store_id=?",[$id]);
                $this->project->execute('DELETE FROM stores WHERE id=?',[$id]);
            }
            $db->commit();
        }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
        $failed=0;$root=realpath($this->uploadRoot);
        foreach(array_unique($files) as $key){
            // Only remove files that resolve inside the private upload directory.
            if(!$root)continue;
            if($this->project->rows('SELECT id FROM subtask_photos WHERE storage_key=?',[$key])||$this->project->rows('SELECT id FROM task_photos WHERE storage_key=?',[$key]))continue;
            $path=realpath($root.DIRECTORY_SEPARATOR.$key);if(!$path||!is_file($path))continue;
            if(!str_starts_with(strtolower($path),strtolower($root.DIRECTORY_SEPARATOR))){$failed++;continue;}
            if(!@unlink($path))$failed++;
        }
        return ['deleted'=>count($ids),'cleanup_failed'=>$failed];
    }
}
