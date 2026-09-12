<?php
declare(strict_types=1);

final class UserRepository
{
    public function __construct(private PDO $db) {}

    public function find(string $id): ?array
    {
        $query = $this->db->prepare('SELECT * FROM users WHERE id = ?');
        $query->execute([$id]);
        return $query->fetch() ?: null;
    }

    public function byUsername(string $username): ?array
    {
        $query = $this->db->prepare('SELECT * FROM users WHERE username = ?');
        $query->execute([strtolower(trim($username))]);
        return $query->fetch() ?: null;
    }

    public function all(): array
    {
        return $this->db->query('SELECT id, username, display_name, email, role, active, created_at FROM users ORDER BY display_name, username')->fetchAll();
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
        if (!in_array($role, ['admin', 'pm', 'contractor'], true)) {
            throw new DomainException('Choose a valid role.');
        }
        if (($id === null || $password !== '') && (strlen($password) < 8 || strlen($password) > 72)) {
            throw new DomainException('Passwords must contain 8–72 bytes.');
        }
        if ($id === $actorId && ($role !== 'admin' || !$active)) {
            throw new DomainException('You cannot remove your own administrator access or deactivate yourself.');
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
            $query = $this->db->prepare('UPDATE users SET username=?, display_name=?, email=?, role=?, active=?, password_hash=?, session_version=session_version+1, updated_at=? WHERE id=?');
            $query->execute([$username, $name, $email ?: null, $role, $active, $hash, $now, $id]);
        } else {
            $query = $this->db->prepare('INSERT INTO users (id, username, display_name, email, role, active, password_hash, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $query->execute([Schema::id(), $username, $name, $email ?: null, $role, $active, $hash, $now, $now]);
        }
    }
}
