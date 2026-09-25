<?php
declare(strict_types=1);

final class UserRepository
{
    public function __construct(private PDO $db) {}

    public function find(string $id): ?array
    {
        $query = $this->db->prepare('SELECT u.*, EXISTS(SELECT 1 FROM user_mfa m WHERE m.user_id=u.id) AS mfa_enabled FROM users u WHERE id = ?');
        $query->execute([$id]);
        return $query->fetch() ?: null;
    }

    public function byUsername(string $username): ?array
    {
        $query = $this->db->prepare('SELECT u.*, EXISTS(SELECT 1 FROM user_mfa m WHERE m.user_id=u.id) AS mfa_enabled FROM users u WHERE username = ?');
        $query->execute([strtolower(trim($username))]);
        return $query->fetch() ?: null;
    }

    public function all(?array $actor=null): array
    {
        return $this->db->query("SELECT u.id,u.username,u.display_name,u.email,u.role,u.active,u.company_id,u.created_at,EXISTS(SELECT 1 FROM user_mfa m WHERE m.user_id=u.id) AS mfa_enabled FROM users u".($actor&&$actor['role']!=='admin'?" WHERE u.role IN ('contractor','contractor_admin')":"")." ORDER BY u.display_name,u.username")->fetchAll();
    }

    public static function canManage(array $actor,?array $target=null): bool
    {
        return $actor['role']==='admin'||($actor['role']==='pm'&&(!$target||in_array($target['role'],['contractor','contractor_admin'],true)));
    }
    public function saveManaged(array $actor,array $input,?string $id): void
    {
        $this->db->beginTransaction();
        try{
            $ids=array_values(array_unique(array_filter([$actor['id'],$id])));sort($ids);
            $lock=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $q=$this->db->prepare('SELECT * FROM users WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY id'.$lock);$q->execute($ids);
            $rows=array_column($q->fetchAll(),null,'id');$fresh=$rows[$actor['id']]??null;$target=$id?($rows[$id]??null):null;
            if(!$fresh||!$fresh['active']||(int)$fresh['session_version']!==(int)$actor['session_version']||($id&&!$target)||!self::canManage($fresh,$target)||!self::canManage($fresh,['role'=>$input['role']??'']))throw new AccessDenied('You cannot manage this user or assign this role.');
            $this->save($input,$id,$actor['id']);
            $this->db->commit();
        }catch(Throwable $error){$this->db->rollBack();throw $error;}
    }

    public function save(array $input, ?string $id, string $actorId): void
    {
        $username = strtolower(trim((string) ($input['username'] ?? '')));
        $name = trim((string) ($input['display_name'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $role = (string) ($input['role'] ?? '');
        $active = isset($input['active']) ? 1 : 0;
        $password = (string) ($input['password'] ?? '');
        if (!preg_match('/^[a-z0-9._-]{3,80}$/D', $username)) {
            throw new DomainException('Use 3–80 letters, numbers, dots, underscores or hyphens for the username.');
        }
        if ($name === '' || mb_strlen($name) > 120) {
            throw new DomainException('Enter a display name of up to 120 characters.');
        }
        if ($email !== '' && (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            throw new DomainException('Enter a valid email address or leave it empty.');
        }
        if (!in_array($role, ['admin', 'pm', 'contractor_admin', 'contractor'], true)) {
            throw new DomainException('Choose a valid role.');
        }
        if (($id === null || $password !== '') && (strlen($password) < 8 || strlen($password) > 72)) {
            throw new DomainException('Passwords must contain 8–72 bytes.');
        }
        if ($id === $actorId && ($role !== 'admin' || !$active)) {
            throw new DomainException('You cannot remove your own administrator access or deactivate yourself.');
        }
        $company=trim((string)($input['company_id']??''))?:null;
        if (in_array($role,['pm','admin'],true)) $company=null;
        if ($company) {
            $q=$this->db->prepare('SELECT id FROM companies WHERE id=? AND active=1');$q->execute([$company]);
            if (!$q->fetchColumn()) throw new DomainException('Choose an active contracting company.');
        }
        $existing = $id ? $this->find($id) : null;
        if ($id && !$existing) {
            throw new DomainException('This user no longer exists.');
        }
        $duplicate = $this->byUsername($username);
        if ($duplicate && $duplicate['id'] !== $id) {
            throw new DomainException('This username is already in use.');
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $hash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : $existing['password_hash'];
        if ($id) {
            $query = $this->db->prepare('UPDATE users SET username=?, display_name=?, email=?, role=?, active=?, password_hash=?, session_version=session_version+1, updated_at=?, company_id=? WHERE id=?');
            $query->execute([$username, $name, $email ?: null, $role, $active, $hash, $now, $company, $id]);
        } else {
            $query = $this->db->prepare('INSERT INTO users (id, username, display_name, email, role, active, password_hash, created_at, updated_at, company_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $query->execute([Schema::id(), $username, $name, $email ?: null, $role, $active, $hash, $now, $now, $company]);
        }
    }
}
