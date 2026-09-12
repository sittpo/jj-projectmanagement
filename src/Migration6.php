<?php
declare(strict_types=1);
final class Migration6
{
    public static function run(PDO $db): void
    {
        $query=$db->query('SELECT * FROM stores WHERE 1=0');$columns=[];
        for($i=0;$i<$query->columnCount();$i++)$columns[]=$query->getColumnMeta($i)['name'];
        if(!in_array('unifi_order',$columns,true))$db->exec("ALTER TABLE stores ADD COLUMN unifi_order VARCHAR(20) NOT NULL DEFAULT 'not_ordered' CHECK(unifi_order IN ('not_ordered','ordered','shipped','delivered'))");
        $db->exec('INSERT INTO schema_versions(version) VALUES(6)');
    }
}
