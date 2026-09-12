<?php
declare(strict_types=1);

final class Auth
{
    public function __construct(private PDO $db, private UserRepository $users) {}

    public function user(): ?array
    {
        $user = isset($_SESSION['user_id']) ? $this->users->find($_SESSION['user_id']) : null;
        if (!$user || !(int) $user['active'] || (int) $user['session_version'] !== ($_SESSION['user_version'] ?? 0)) {
            unset($_SESSION['user_id'], $_SESSION['user_version']);
            return null;
        }
        return $user;
    }

    public function login(string $username, string $password, string $ip): bool
    {
        $key = hash('sha256', $ip);
        $cutoff = time() - 900;
        $this->db->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')->execute([$cutoff]);
        $query = $this->db->prepare('SELECT COUNT(*) FROM login_attempts WHERE attempt_key=?');
        $query->execute([$key]);
        if ((int) $query->fetchColumn() >= 10) {
            throw new DomainException('Too many sign-in attempts. Please try again in 15 minutes.');
        }
        $user = $this->users->byUsername($username);
        $valid = password_verify($password, $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        if (!$user || !$valid || !(int) $user['active']) {
            $this->db->prepare('INSERT INTO login_attempts (id, attempt_key, attempted_at) VALUES (?, ?, ?)')->execute([Schema::id(), $key, time()]);
            return false;
        }
        $this->db->prepare('DELETE FROM login_attempts WHERE attempt_key=?')->execute([$key]);
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_version'] = (int) $user['session_version'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return true;
    }

    public function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }
}
