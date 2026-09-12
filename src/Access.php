<?php
declare(strict_types=1);
final class AccessDenied extends DomainException {}
final class Access
{
    public const ROLES=['contractor'=>0,'contractor_admin'=>1,'pm'=>2,'admin'=>3];
    public static function atLeast(array $user,string $role): bool { return (self::ROLES[$user['role']]??-1)>=self::ROLES[$role]; }
    public static function label(string $role): string { return ['contractor'=>'Contractor','contractor_admin'=>'Contractor admin','pm'=>'Project manager','admin'=>'Administrator'][$role]??'Unknown'; }
    public static function store(PDO $db,array $user,string $id): array
    {
        $q=$db->prepare('SELECT * FROM stores WHERE id=?');$q->execute([$id]);$store=$q->fetch();
        if (!$store) throw new DomainException('Store not found.');
        if (self::atLeast($user,'pm')) return $store;
        if (!$store['target_date']) throw new AccessDenied('You do not have access to this store.');
        if (!$user['company_id'] || $store['company_id']!==$user['company_id']) throw new AccessDenied('You do not have access to this store.');
        if ($user['role']==='contractor_admin') return $store;
        $q=$db->prepare('SELECT COUNT(*) FROM store_assignments WHERE store_id=? AND user_id=?');$q->execute([$id,$user['id']]);
        if (!(int)$q->fetchColumn()) throw new AccessDenied('You do not have access to this store.');
        return $store;
    }
}
