<?php
declare(strict_types=1);
final class SubtaskRepository
{
    public function __construct(private PDO $db,private string $uploadRoot) {}
    public function list(string $storeId,string $order='ui_order'): array
    {
        $order=$order==='report_order'?'report_order':'ui_order';
        $q=$this->db->prepare("SELECT s.*,t.category,t.store_id,u.display_name AS completed_name FROM subtasks s JOIN tasks t ON t.id=s.task_id LEFT JOIN users u ON u.id=s.completed_by WHERE t.store_id=? ORDER BY s.$order,s.id");
        $q->execute([$storeId]);return $q->fetchAll();
    }
    public function photos(string $id): array { $q=$this->db->prepare('SELECT * FROM subtask_photos WHERE subtask_id=? ORDER BY created_at,id');$q->execute([$id]);return $q->fetchAll(); }
    public function update(array $user,string $id,array $input,array $files=[]): string
    {
        $moved=[];$this->db->beginTransaction();
        try {
            $lock=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $q=$this->db->prepare('SELECT s.*,t.store_id FROM subtasks s JOIN tasks t ON t.id=s.task_id WHERE s.id=?'.$lock);$q->execute([$id]);$row=$q->fetch();
            if(!$row)throw new DomainException('Step not found.');
            Access::store($this->db,$user,$row['store_id']);
            if((string)($input['version']??'')!==(string)$row['version'])throw new DomainException('This step changed while you were editing it. Reload and try again.');
            $action=ProjectRepository::text($input,'action',20,true);$now=gmdate('Y-m-d\TH:i:s\Z');
            if(in_array($action,['signoff','reopen'],true)) {
                if(!Access::atLeast($user,'pm'))throw new AccessDenied('Only a PM or Admin can sign off or reopen a step.');
                if($action==='signoff'){
                    if(!$row['complete']||$row['signed_at'])throw new DomainException('Complete the step before signing it off.');
                    $q=$this->db->prepare('UPDATE subtasks SET signed_by=?,signed_name=?,signed_at=?,version=version+1 WHERE id=?');
                    $q->execute([$user['id'],$user['display_name'],$now,$id]);
                }else{
                    if(!$row['signed_at'])throw new DomainException('This step has not been signed off.');
                    $q=$this->db->prepare('UPDATE subtasks SET signed_by=NULL,signed_name=NULL,signed_at=NULL,version=version+1 WHERE id=?');$q->execute([$id]);
                }
                $details=json_encode(['note'=>$row['note'],'complete'=>(bool)$row['complete']],JSON_THROW_ON_ERROR);
            }elseif(in_array($action,['save','completion'],true)){
                if($row['signed_at'])throw new DomainException('This step is signed off. A PM or Admin must reopen it before changes.');
                $completionOnly=$action==='completion';
                $note=$completionOnly?$row['note']:ProjectRepository::text($input,'note',20000);$complete=isset($input['complete'])?1:0;
                $uploads=$completionOnly?[]:$this->validateUploads($files);
                if($uploads && !is_dir($this->uploadRoot) && !mkdir($this->uploadRoot,0700,true))throw new RuntimeException('Cannot create upload storage.');
                foreach($uploads as $upload){
                    $photoId=Schema::id();$key=$photoId.'.'.$upload['extension'];$destination=$this->uploadRoot.'/'.$key;
                    if(!move_uploaded_file($upload['tmp_name'],$destination))throw new RuntimeException('Photo upload failed.');
                    $moved[]=$destination;
                    $q=$this->db->prepare('INSERT INTO subtask_photos(id,subtask_id,uploaded_by,storage_key,original_name,mime_type,created_at) VALUES (?,?,?,?,?,?,?)');
                    $q->execute([$photoId,$id,$user['id'],$key,$upload['name'],$upload['mime'],$now]);
                }
                $completedBy=$complete?($row['completed_by']?:$user['id']):null;$completedAt=$complete?($row['completed_at']?:$now):null;
                $q=$this->db->prepare('UPDATE subtasks SET note=?,complete=?,completed_by=?,completed_at=?,version=version+1 WHERE id=?');
                $q->execute([$note,$complete,$completedBy,$completedAt,$id]);
                $details=json_encode(['note'=>$note,'complete'=>(bool)$complete,'photos_added'=>count($uploads),'completion_only'=>$completionOnly],JSON_THROW_ON_ERROR);
                $action='save';
            }else throw new DomainException('Invalid step action.');
            $q=$this->db->prepare('INSERT INTO task_audit(id,subtask_id,actor_id,actor_name,action,details,created_at) VALUES (?,?,?,?,?,?,?)');
            $q->execute([Schema::id(),$id,$user['id'],$user['display_name'],$action,$details,$now]);
            $q=$this->db->prepare('SELECT COUNT(*) AS total,SUM(complete) AS done FROM subtasks WHERE task_id=?');$q->execute([$row['task_id']]);$counts=$q->fetch();
            $status=(int)$counts['total']===(int)$counts['done']?'completed':((int)$counts['done']?'in_progress':'planned');
            $q=$this->db->prepare('UPDATE tasks SET status=?,completed_at=? WHERE id=?');$q->execute([$status,$status==='completed'?$now:null,$row['task_id']]);
            $this->db->commit();return $row['store_id'];
        }catch(Throwable $error){if($this->db->inTransaction())$this->db->rollBack();foreach($moved as $path)if(is_file($path))unlink($path);throw $error;}
    }
    private function validateUploads(array $files): array
    {
        if(!$files)return [];
        $names=$files['name']??[];
        if(!is_array($names))throw new DomainException('Invalid upload.');
        if(count($names)>6)throw new DomainException('Upload up to 6 photos at once.');
        $result=[];$allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        foreach($names as $index=>$name){
            $error=$files['error'][$index]??UPLOAD_ERR_NO_FILE;if($error===UPLOAD_ERR_NO_FILE)continue;
            if($error!==UPLOAD_ERR_OK)throw new DomainException('A photo could not be uploaded. Maximum size: 10 MB per photo.');
            $tmp=$files['tmp_name'][$index]??'';
            if(!is_uploaded_file($tmp)||filesize($tmp)>10*1024*1024)throw new DomainException('Invalid photo or file larger than 10 MB.');
            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);$dimensions=@getimagesize($tmp);
            if(!isset($allowed[$mime])||!$dimensions||$dimensions[0]*$dimensions[1]>60000000)throw new DomainException('Use a valid JPEG, PNG, or WebP photo (up to 60 megapixels).');
            $result[]=['tmp_name'=>$tmp,'mime'=>$mime,'extension'=>$allowed[$mime],'name'=>mb_substr(basename((string)$name),0,255)];
        }
        return $result;
    }
    public function photo(array $user,string $id): array
    {
        $q=$this->db->prepare('SELECT p.*,t.store_id FROM subtask_photos p JOIN subtasks s ON s.id=p.subtask_id JOIN tasks t ON t.id=s.task_id WHERE p.id=?');$q->execute([$id]);$photo=$q->fetch();
        if(!$photo)throw new DomainException('Photo not found.');
        Access::store($this->db,$user,$photo['store_id']);
        if(!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/D',$photo['storage_key']))throw new DomainException('Invalid photo reference.');
        $photo['path']=$this->uploadRoot.'/'.$photo['storage_key'];
        if(!is_file($photo['path']))throw new DomainException('Photo file is unavailable. Restore the private upload files alongside the database.');
        return $photo;
    }
}
