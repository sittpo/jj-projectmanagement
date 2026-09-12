<?php
declare(strict_types=1);

// Shared live report model for the dashboard and CSV export.
final class DashboardReport
{
    public static function live(ProjectRepository $project,array $actor): array
    {
        if(!Access::atLeast($actor,'pm'))throw new AccessDenied('Only a PM or Admin can view rollout reports.');
        $today=(new DateTimeImmutable('now',new DateTimeZone('Europe/Copenhagen')))->format('Y-m-d');
        $stores=$project->storeListing($actor,true);
        $total=count($stores);$complete=count(array_filter($stores,fn($s)=>(bool)$s['finished']));
        $upcoming=array_values(array_filter($stores,fn($s)=>!$s['finished']&&$s['target_date']&&$s['target_date']>=$today));
        $overdue=array_values(array_filter($stores,fn($s)=>!$s['finished']&&$s['target_date']&&$s['target_date']<$today));
        $attention=[];$prereqs=new PrerequisiteRepository($project);$definitions=$prereqs->definitions(true);
        foreach($stores as $store){
            $reasons=[];
            if(!$store['target_date'])$reasons[]='Installation date missing';
            elseif(!$store['finished']&&$store['target_date']<$today)$reasons[]='Overdue · '.$store['target_date'];
            foreach($prereqs->forStore($store,$definitions) as $item){$issue=PrerequisiteRepository::issue($store,$item,$today);if($issue)$reasons[]=$issue;}
            if($reasons)$attention[]=$store+['reason'=>implode(' · ',$reasons)];
        }
        $streams=$project->rows("SELECT t.category,COUNT(*) AS total,SUM(CASE WHEN EXISTS(SELECT 1 FROM subtasks st WHERE st.task_id=t.id)
            THEN NOT EXISTS(SELECT 1 FROM subtasks st WHERE st.task_id=t.id AND (st.complete=0 OR st.signed_at IS NULL))
            ELSE t.status='completed' END) AS complete FROM tasks t GROUP BY t.category");
        $workstreams=[];$done=0;$taskTotal=0;
        foreach(ProjectRepository::CATEGORIES as $category=>$label){
            $row=array_values(array_filter($streams,fn($s)=>$s['category']===$category))[0]??['total'=>0,'complete'=>0];
            $workstreams[]=['label'=>$label,'icon'=>$category==='dvr'?'camera':$category,'complete'=>(int)$row['complete'],'total'=>(int)$row['total']];
            $done+=(int)$row['complete'];$taskTotal+=(int)$row['total'];
        }
        $activity=self::activity($project,$actor,1,5)['rows'];
        return [
            'metrics'=>[
                ['label'=>'Stores live','value'=>$complete,'unit'=>'/ '.$total,'trend'=>($total?round($complete/$total*100):0).'% of stores complete','icon'=>'store'],
                ['label'=>'Upcoming visits','value'=>count($upcoming),'unit'=>'','trend'=>'Today and future installations','icon'=>'calendar'],
                ['label'=>'Overdue stores','value'=>count($overdue),'unit'=>'','trend'=>'Unfinished past the installation date','icon'=>'clock'],
                ['label'=>'Installations complete','value'=>$done,'unit'=>'/ '.$taskTotal,'trend'=>'Completed workstreams','icon'=>'check']
            ],
            'total'=>$total,'workstreams'=>$workstreams,'done'=>$done,'task_total'=>$taskTotal,
            'attention'=>$attention,'visits'=>array_slice($upcoming,0,6),'activity'=>$activity,'today'=>$today
        ];
    }

    private static function activityQuery(): string
    {
        return "SELECT a.id,s.id AS store_id,s.name AS store_name,s.code,a.actor_name,a.created_at,a.action,st.title
            FROM task_audit a JOIN subtasks st ON st.id=a.subtask_id JOIN tasks t ON t.id=st.task_id JOIN stores s ON s.id=t.store_id
            UNION ALL SELECT n.id,s.id,s.name,s.code,n.author_name,n.created_at,'pm_note','Project Manager note added'
            FROM store_pm_notes n JOIN stores s ON s.id=n.store_id
            UNION ALL SELECT s.id,s.id,s.name,s.code,'',s.created_at,'store_created','Store created' FROM stores s";
    }
    public static function activity(ProjectRepository $project,array $actor,int $page=1,int $perPage=10): array
    {
        if(!Access::atLeast($actor,'pm'))throw new AccessDenied('Only a PM or Admin can view rollout activity.');
        $perPage=in_array($perPage,[5,10,50,100],true)?$perPage:10;
        $query=self::activityQuery();
        $total=(int)$project->rows('SELECT COUNT(*) AS total FROM ('.$query.') events')[0]['total'];
        $pages=max(1,(int)ceil($total/$perPage));$page=max(1,min($page,$pages));
        $offset=($page-1)*$perPage;
        $rows=$project->rows('SELECT * FROM ('.$query.') events ORDER BY created_at DESC,id DESC,action DESC LIMIT '.$perPage.' OFFSET '.$offset);
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages,'per_page'=>$perPage];
    }
}
