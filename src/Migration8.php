<?php
declare(strict_types=1);
final class Migration8
{
    public static function run(PDO $db): void
    {
        $q=$db->query('SELECT * FROM stores WHERE 1=0');$columns=[];
        for($i=0;$i<$q->columnCount();$i++)$columns[]=$q->getColumnMeta($i)['name'];
        foreach(['address'=>255,'post_code'=>30] as $name=>$length)if(!in_array($name,$columns,true))$db->exec("ALTER TABLE stores ADD COLUMN $name VARCHAR($length) NOT NULL DEFAULT ''");
        $db->exec('INSERT INTO schema_versions(version) VALUES(8)');
    }
}
