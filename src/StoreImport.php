<?php
declare(strict_types=1);
final class StoreImport
{
    public const FIELDS=['code','name','post_code','city','address','target_date','company','contractors','owner_name','owner_phone','owner_email','contact_name','contact_phone','contact_email','reminder_date','reminders_enabled'];
    public function __construct(private ProjectRepository $project) {}
    private function admin(array $actor): void {if(!Access::atLeast($actor,'admin'))throw new AccessDenied('Only Admins can import stores.');}
    private function snapshot(bool $lock=false): string
    {
        $suffix=$lock&&$this->project->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $data=[];
        foreach(['stores'=>'*','store_assignments'=>'*','companies'=>'id,name,active','users'=>'id,username,company_id,role,active','task_templates'=>'*'] as $table=>$columns){
            $order=$table==='store_assignments'?'store_id,user_id':'id';
            $data[$table]=$this->project->rows("SELECT $columns FROM $table ORDER BY $order".$suffix);
        }
        return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));
    }
    private function current(array $store): array
    {
        $row=[];
        foreach(self::FIELDS as $field)$row[$field]=(string)($store[$field]??'');
        $row['company']=$store['company_id']?($this->project->rows('SELECT name FROM companies WHERE id=?',[$store['company_id']])[0]['name']??''):'';
        $names=array_column($this->project->rows('SELECT u.username FROM store_assignments a JOIN users u ON u.id=a.user_id WHERE a.store_id=? ORDER BY u.username',[$store['id']]),'username');
        $row['contractors']=implode('|',$names);
        return $row;
    }
    private function input(array $row): array
    {
        $input=$row;$input['company_id']='';$input['contractors']=[];
        if($row['company']!==''){
            $companies=$this->project->rows('SELECT id FROM companies WHERE name=? AND active=1',[$row['company']]);
            if(count($companies)!==1)throw new DomainException('Company must match one active contracting company.');
            $input['company_id']=$companies[0]['id'];
        }
        if($row['contractors']!==''){
            $names=array_unique(array_map('trim',explode('|',$row['contractors'])));
            foreach($names as $name){
                $users=$this->project->rows("SELECT id FROM users WHERE username=? AND active=1 AND role IN ('contractor','contractor_admin') AND company_id=?",[$name,$input['company_id']]);
                if(count($users)!==1)throw new DomainException('Contractors must be active usernames belonging to the selected company.');
                $input['contractors'][]=$users[0]['id'];
            }
        }
        if(!in_array($row['reminders_enabled'],['0','1'],true))throw new DomainException('reminders_enabled must be 0 or 1.');
        unset($input['reminders_enabled']);if($row['reminders_enabled']==='1')$input['reminders_enabled']='1';
        return $input;
    }
    public function preview(array $actor,string $csv): array
    {
        $this->admin($actor);
        if(strlen($csv)>2*1024*1024||!mb_check_encoding($csv,'UTF-8'))throw new DomainException('Use a UTF-8 CSV file up to 2 MB.');
        $stream=fopen('php://temp','r+');fwrite($stream,preg_replace('/^\\xEF\\xBB\\xBF/','',$csv));rewind($stream);
        $header=null;$delimiter=',';
        foreach([',',';', "\t"] as $candidate){
            rewind($stream);$columns=fgetcsv($stream,0,$candidate,'"','');
            if(!$columns)continue;
            $columns=array_map(fn($value)=>trim((string)$value),$columns);
            if(count(array_unique($columns))===count($columns)&&!array_diff($columns,self::FIELDS)&&!array_diff(['code','name','post_code','city'],$columns)){
                $header=$columns;$delimiter=$candidate;break;
            }
        }
        if(!$header){fclose($stream);throw new DomainException('Use CSV headers code, name, post_code and city. Comma, semicolon, and tab separators are supported.');}
        $draft=['changes'=>[],'ignored'=>0,'created'=>time(),'token'=>bin2hex(random_bytes(24))];
        $db=$this->project->db;$db->beginTransaction();
        try{
            $draft['snapshot']=$this->snapshot();$seen=[];$number=1;
            while(($values=fgetcsv($stream,0,$delimiter,'"',''))!==false){
                $number++;if($number>1001)throw new DomainException('Import up to 1,000 stores at a time.');
                if($values===[null])continue;
                if(count($values)!==count($header))throw new DomainException("Row $number: column count does not match the header.");
                $supplied=array_combine($header,array_map(fn($v)=>trim((string)$v),$values));
                foreach(['code','name','post_code','city'] as $required)if(($supplied[$required]??'')==='')throw new DomainException("Row $number: $required is required.");
                $existing=$this->project->rows('SELECT * FROM stores WHERE code=?',[$supplied['code']])[0]??null;
                $key=$existing?'id:'.$existing['id']:'code:'.mb_strtolower($supplied['code']);
                if(isset($seen[$key]))throw new DomainException("Row $number: duplicate store code in CSV.");$seen[$key]=true;
                $before=$existing?$this->current($existing):array_fill_keys(self::FIELDS,'');
                $after=$before;if(!$existing)$after['reminders_enabled']='1';
                foreach($supplied as $field=>$value)if($value!=='')$after[$field]=$value;
                if($existing)$after['code']=$existing['code'];
                if($after['contractors']!==''){$names=array_unique(array_map('trim',explode('|',$after['contractors'])));sort($names);$after['contractors']=implode('|',$names);}
                $diff=[];foreach(self::FIELDS as $field)if($before[$field]!==$after[$field])$diff[$field]=['current'=>$before[$field],'new'=>$after[$field]];
                if(!$diff&&$existing){$draft['ignored']++;continue;}
                try{$input=$this->input($after);$validatedId=$this->project->storeSave($input,$existing['id']??null);$seen['id:'.$validatedId]=true;}
                catch(Throwable $error){throw new DomainException("Row $number: ".($error instanceof DomainException?$error->getMessage():'Invalid or duplicate store data.'),0,$error);}
                $draft['changes'][]=['code'=>$after['code'],'id'=>$existing['id']??null,'input'=>$input,'diff'=>$diff];
            }
            $db->rollBack();fclose($stream);return $draft;
        }catch(Throwable $error){if($db->inTransaction())$db->rollBack();fclose($stream);throw $error;}
    }
    public function apply(array $actor,array $draft,string $token): int
    {
        $this->admin($actor);
        if(!isset($draft['token'])||!hash_equals($draft['token'],$token)||time()-$draft['created']>1800)throw new DomainException('Import preview expired. Upload the CSV again.');
        $db=$this->project->db;$db->beginTransaction();
        try{
            if(!hash_equals($draft['snapshot'],$this->snapshot(true)))throw new DomainException('Store, company, user, or template data changed since preview. Upload again to review the current differences.');
            foreach($draft['changes'] as $change)$this->project->storeSave($change['input'],$change['id']);
            $db->commit();return count($draft['changes']);
        }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
    }
}

