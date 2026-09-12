<?php
declare(strict_types=1);
final class Migration7
{
    public static function run(PDO $db): void
    {
        $suffix=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $db->exec("CREATE TABLE IF NOT EXISTS prerequisites(id VARCHAR(36) PRIMARY KEY,name VARCHAR(160) NOT NULL,sort_order INTEGER NOT NULL,active INTEGER NOT NULL DEFAULT 1)".$suffix);
        $db->exec("CREATE TABLE IF NOT EXISTS prerequisite_statuses(id VARCHAR(36) PRIMARY KEY,prerequisite_id VARCHAR(36) NOT NULL,name VARCHAR(120) NOT NULL,sort_order INTEGER NOT NULL,is_default INTEGER NOT NULL DEFAULT 0,attention_days INTEGER NULL,UNIQUE(prerequisite_id,id),FOREIGN KEY(prerequisite_id) REFERENCES prerequisites(id))".$suffix);
        $db->exec("CREATE TABLE IF NOT EXISTS store_prerequisites(store_id VARCHAR(36) NOT NULL,prerequisite_id VARCHAR(36) NOT NULL,status_id VARCHAR(36) NOT NULL,PRIMARY KEY(store_id,prerequisite_id),FOREIGN KEY(store_id) REFERENCES stores(id),FOREIGN KEY(prerequisite_id,status_id) REFERENCES prerequisite_statuses(prerequisite_id,id))".$suffix);
        $db->beginTransaction();
        try{
            if(!$db->query("SELECT id FROM prerequisites WHERE id='unifi'")->fetchColumn()){
                $db->exec("INSERT INTO prerequisites(id,name,sort_order,active) VALUES('unifi','UniFi order',1,1)");
                $q=$db->prepare('INSERT INTO prerequisite_statuses(id,prerequisite_id,name,sort_order,is_default,attention_days) VALUES(?,?,?,?,?,?)');
                foreach([['not_ordered','Not ordered',7],['ordered','Ordered',3],['shipped','Shipped',3],['delivered','Delivered',null]] as $i=>$status)$q->execute([$status[0],'unifi',$status[1],$i+1,$i===0?1:0,$status[2]]);
                $db->exec("INSERT INTO store_prerequisites(store_id,prerequisite_id,status_id) SELECT id,'unifi',unifi_order FROM stores");
            }
            $db->exec('INSERT INTO schema_versions(version) VALUES(7)');$db->commit();
        }catch(Throwable $error){$db->rollBack();throw $error;}
    }
}
