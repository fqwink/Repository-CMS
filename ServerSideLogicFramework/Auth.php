<?php

declare(strict_types=1);

namespace ServerSideLogicFramework;

use RepositoryCms\Core\Response;

final class Auth
{
    public const INITIAL_ADMIN_USERNAME = 'admin';
    public const INITIAL_ADMIN_PASSWORD = 'admin';

    private const PASSWORD_MIN_LENGTH = 12;
    private const MAX_LOGIN_FAILURES = 5;
    private const LOGIN_LOCK_SECONDS = 900;
    private const SESSION_LIFETIME_SECONDS = 1800;

    public function __construct(
        private readonly string $authFile,
        private readonly string $stateFile,
        private readonly string $initialAdminStateFile,
    ) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            session_name('repository_cms');
            session_set_cookie_params([
                'lifetime' => self::SESSION_LIFETIME_SECONDS,
                'httponly' => true,
                'samesite' => 'Strict',
                'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            ]);
            session_start();
        }
    }

    public function configured(): bool
    {
        return $this->users() !== [];
    }

    public function ensureInitialAdmin(): void
    {
        if ($this->configured()) {
            return;
        }
        $passwordHash = password_hash(self::INITIAL_ADMIN_PASSWORD, PASSWORD_DEFAULT);
        if ($passwordHash === false) {
            throw new \RuntimeException('初期管理者パスワードをハッシュ化できません。');
        }
        $this->writeUsers([[
            'username' => self::INITIAL_ADMIN_USERNAME,
            'password_hash' => $passwordHash,
            'role' => 'admin',
            'created_at' => gmdate(DATE_ATOM),
        ]]);
        $this->writeInitialAdminState([
            'completed' => false,
            'access_count' => 0,
            'deadline_reached' => false,
            'last_access_at' => '',
            'updated_at' => gmdate(DATE_ATOM),
        ]);
    }

    public function initialAdminChangeRequired(): bool
    {
        $this->ensureInitialAdmin();
        return !$this->initialAdminCompleted();
    }

    public function initialAdminCompleted(): bool
    {
        $state = $this->readInitialAdminState();
        return ($state['completed'] ?? false) === true;
    }

    public function recordInitialAdminAccess(): void
    {
        if ($this->initialAdminCompleted()) {
            return;
        }
        $state = $this->readInitialAdminState();
        $accessCount = (int) ($state['access_count'] ?? 0) + 1;
        $this->writeInitialAdminState([
            'completed' => false,
            'access_count' => $accessCount,
            'deadline_reached' => $accessCount >= 5,
            'last_access_at' => gmdate(DATE_ATOM),
            'updated_at' => gmdate(DATE_ATOM),
        ]);
    }

    public function initialAdminState(): array
    {
        $state = $this->readInitialAdminState();
        return [
            'completed' => ($state['completed'] ?? false) === true,
            'access_count' => (int) ($state['access_count'] ?? 0),
            'deadline_reached' => ($state['deadline_reached'] ?? false) === true,
            'last_access_at' => (string) ($state['last_access_at'] ?? ''),
        ];
    }

    public function completeInitialAdminChange(string $username, string $password): void
    {
        if ($this->initialAdminCompleted()) {
            throw new \RuntimeException('初期管理者変更は完了済みです。');
        }
        $username = trim($username);
        if ($username === '' || $username === self::INITIAL_ADMIN_USERNAME) {
            throw new \InvalidArgumentException('初期ユーザー名から変更してください。');
        }
        if ($password === self::INITIAL_ADMIN_PASSWORD) {
            throw new \InvalidArgumentException('初期パスワードから変更してください。');
        }
        $this->assertPasswordAllowed($password);
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if ($passwordHash === false) {
            throw new \RuntimeException('パスワードをハッシュ化できません。');
        }
        $this->writeUsers([[
            'username' => $username,
            'password_hash' => $passwordHash,
            'role' => 'admin',
            'created_at' => gmdate(DATE_ATOM),
        ]]);
        $this->writeInitialAdminState([
            'completed' => true,
            'access_count' => (int) ($this->readInitialAdminState()['access_count'] ?? 0),
            'deadline_reached' => false,
            'last_access_at' => (string) ($this->readInitialAdminState()['last_access_at'] ?? ''),
            'updated_at' => gmdate(DATE_ATOM),
        ]);
        $_SESSION['admin'] = $username;
        $_SESSION['role'] = 'admin';
        $_SESSION['authenticated_at'] = time();
        $_SESSION['last_seen_at'] = time();
    }

    public function createUser(string $username, string $password, string $role): void
    {
        if (!$this->initialAdminCompleted()) {
            throw new \RuntimeException('初期管理者変更が完了するまでユーザーを設定できません。');
        }
        $username = trim($username);
        $role = strtolower(trim($role));
        if (!in_array($role, ['admin', 'editor'], true)) {
            throw new \InvalidArgumentException('ロールが不正です。');
        }
        $users = $this->usersWithHashes();
        foreach ($users as $user) {
            if (hash_equals((string) $user['username'], $username)) {
                throw new \RuntimeException('同じユーザー名は使用できません。');
            }
        }
        $adminCount = count(array_filter($users, static fn (array $user): bool => ($user['role'] ?? '') === 'admin'));
        $editorCount = count(array_filter($users, static fn (array $user): bool => ($user['role'] ?? '') === 'editor'));
        if ($role === 'admin' && $adminCount >= 1) {
            throw new \RuntimeException('管理者は1人までです。');
        }
        if ($role === 'editor' && $editorCount >= 2) {
            throw new \RuntimeException('編集担当は2人までです。');
        }
        $this->assertPasswordAllowed($password);
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if ($passwordHash === false) {
            throw new \RuntimeException('パスワードをハッシュ化できません。');
        }
        $users[] = [
            'username' => $username,
            'password_hash' => $passwordHash,
            'role' => $role,
            'created_at' => gmdate(DATE_ATOM),
        ];
        $this->writeUsers($users);
    }

    public function users(): array
    {
        return array_map(static fn (array $user): array => [
            'username' => (string) $user['username'],
            'role' => (string) $user['role'],
            'created_at' => (string) ($user['created_at'] ?? ''),
        ], $this->usersWithHashes());
    }

    public function changePassword(string $username, string $password): void
    {
        if (!$this->initialAdminCompleted()) {
            throw new \RuntimeException('初期管理者変更が完了するまでパスワードを変更できません。');
        }
        $username = trim($username);
        $this->assertPasswordAllowed($password);
        $users = $this->usersWithHashes();
        $changed = false;
        foreach ($users as &$user) {
            if (hash_equals((string) $user['username'], $username)) {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                if ($passwordHash === false) {
                    throw new \RuntimeException('パスワードをハッシュ化できません。');
                }
                $user['password_hash'] = $passwordHash;
                $changed = true;
                break;
            }
        }
        unset($user);
        if (!$changed) {
            throw new \RuntimeException('対象ユーザーを確認できません。');
        }
        $this->writeUsers($users);
    }

    public function login(string $username, string $password): bool
    {
        if ($this->loginLocked()) {
            throw new \RuntimeException('ログイン失敗が多いため一時ロックされています。');
        }
        $data = $this->findUser($username);
        if ($data === null) {
            $this->recordLoginFailure();
            return false;
        }
        if (!password_verify($password, $data['password_hash'])) {
            $this->recordLoginFailure();
            return false;
        }
        $this->clearLoginState();
        $_SESSION['admin'] = $data['username'];
        $_SESSION['role'] = $data['role'];
        $_SESSION['authenticated_at'] = time();
        $_SESSION['last_seen_at'] = time();
        if (!session_regenerate_id(true)) {
            unset($_SESSION['admin']);
            unset($_SESSION['role']);
            unset($_SESSION['authenticated_at'], $_SESSION['last_seen_at']);
            throw new \RuntimeException('セッションIDを再生成できません。');
        }
        return true;
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Strict',
            ]);
        }
        if (!session_destroy()) {
            throw new \RuntimeException('セッションを破棄できません。');
        }
    }

    public function user(): ?string
    {
        if (isset($_SESSION['admin']) && $this->sessionExpired()) {
            $this->logout();
            return null;
        }
        if (isset($_SESSION['admin'])) {
            $_SESSION['last_seen_at'] = time();
        }
        return isset($_SESSION['admin']) ? (string) $_SESSION['admin'] : null;
    }

    public function role(): ?string
    {
        if ($this->user() === null) {
            return null;
        }
        $role = (string) ($_SESSION['role'] ?? '');
        if ($role !== '') {
            return $role;
        }
        $user = $this->findUser((string) $_SESSION['admin']);
        if ($user === null) {
            return null;
        }
        $_SESSION['role'] = $user['role'];
        return $user['role'];
    }

    public function loginLocked(): bool
    {
        $state = $this->readLoginState();
        $lockedUntil = (int) ($state['locked_until'] ?? 0);
        if ($lockedUntil === 0) {
            return false;
        }
        if ($lockedUntil <= time()) {
            $this->clearLoginState();
            return false;
        }
        return true;
    }

    public function loginLockedUntil(): int
    {
        if (!$this->loginLocked()) {
            return 0;
        }
        $state = $this->readLoginState();
        return (int) ($state['locked_until'] ?? 0);
    }

    public function requireLogin(): void
    {
        if ($this->user() === null) {
            Response::redirect('?action=login');
        }
    }

    private function findUser(string $username): ?array
    {
        foreach ($this->usersWithHashes() as $user) {
            if (hash_equals((string) $user['username'], $username)) {
                return $user;
            }
        }
        return null;
    }

    private function usersWithHashes(): array
    {
        if (!is_file($this->authFile)) {
            return [];
        }
        $bytes = file_get_contents($this->authFile);
        if ($bytes === false) {
            return [];
        }
        $data = json_decode($bytes, true);
        if (!is_array($data)) {
            return [];
        }
        if (isset($data['username'], $data['password_hash']) && is_string($data['username']) && is_string($data['password_hash'])) {
            return [[
                'username' => (string) $data['username'],
                'password_hash' => (string) $data['password_hash'],
                'role' => 'admin',
                'created_at' => (string) ($data['created_at'] ?? ''),
            ]];
        }
        $users = $data['users'] ?? null;
        if (!is_array($users)) {
            return [];
        }
        $normalized = [];
        foreach ($users as $user) {
            if (!is_array($user)) {
                continue;
            }
            $username = (string) ($user['username'] ?? '');
            $hash = (string) ($user['password_hash'] ?? '');
            $role = (string) ($user['role'] ?? '');
            if ($username === '' || $hash === '' || !in_array($role, ['admin', 'editor'], true)) {
                continue;
            }
            $normalized[] = [
                'username' => $username,
                'password_hash' => $hash,
                'role' => $role,
                'created_at' => (string) ($user['created_at'] ?? ''),
            ];
        }
        return $normalized;
    }

    private function writeUsers(array $users): void
    {
        foreach ($users as $user) {
            if ((string) ($user['username'] ?? '') === '' || strlen((string) ($user['password_hash'] ?? '')) < 20 || !in_array((string) ($user['role'] ?? ''), ['admin', 'editor'], true)) {
                throw new \InvalidArgumentException('ユーザー情報が不正です。');
            }
        }
        $payload = json_encode([
            'users' => array_values($users),
            'updated_at' => gmdate(DATE_ATOM),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($payload === false || file_put_contents($this->authFile, $payload, LOCK_EX) === false) {
            throw new \RuntimeException('認証情報を書き込めません。');
        }
        if (!chmod($this->authFile, 0600)) {
            throw new \RuntimeException('認証情報の権限を設定できません。');
        }
    }

    private function assertPasswordAllowed(string $password): void
    {
        if (strlen($password) < self::PASSWORD_MIN_LENGTH) {
            throw new \InvalidArgumentException('パスワードは12文字以上にしてください。');
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            throw new \InvalidArgumentException('パスワードには英字と数字を含めてください。');
        }
    }

    private function sessionExpired(): bool
    {
        $lastSeenAt = (int) ($_SESSION['last_seen_at'] ?? 0);
        if ($lastSeenAt === 0) {
            return true;
        }
        return time() - $lastSeenAt > self::SESSION_LIFETIME_SECONDS;
    }

    private function readLoginState(): array
    {
        if (!is_file($this->stateFile)) {
            return ['failures' => 0, 'locked_until' => 0];
        }
        $bytes = file_get_contents($this->stateFile);
        if ($bytes === false) {
            return ['failures' => 0, 'locked_until' => 0];
        }
        $data = json_decode($bytes, true);
        if (!is_array($data)) {
            return ['failures' => 0, 'locked_until' => 0];
        }
        return [
            'failures' => (int) ($data['failures'] ?? 0),
            'locked_until' => (int) ($data['locked_until'] ?? 0),
        ];
    }

    private function recordLoginFailure(): void
    {
        $state = $this->readLoginState();
        $failures = (int) $state['failures'] + 1;
        $lockedUntil = $failures >= self::MAX_LOGIN_FAILURES ? time() + self::LOGIN_LOCK_SECONDS : 0;
        $this->writeLoginState([
            'failures' => $failures,
            'locked_until' => $lockedUntil,
            'updated_at' => gmdate(DATE_ATOM),
        ]);
    }

    private function clearLoginState(): void
    {
        if (is_file($this->stateFile) && !unlink($this->stateFile)) {
            throw new \RuntimeException('ログイン失敗状態を解除できません。');
        }
    }

    private function writeLoginState(array $state): void
    {
        $payload = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($payload === false || file_put_contents($this->stateFile, $payload, LOCK_EX) === false) {
            throw new \RuntimeException('ログイン失敗状態を書き込めません。');
        }
        if (!chmod($this->stateFile, 0600)) {
            throw new \RuntimeException('ログイン失敗状態の権限を設定できません。');
        }
    }

    private function readInitialAdminState(): array
    {
        if (!is_file($this->initialAdminStateFile)) {
            return $this->defaultInitialAdminState();
        }
        $bytes = file_get_contents($this->initialAdminStateFile);
        if ($bytes === false) {
            return $this->defaultInitialAdminState();
        }
        $data = json_decode($bytes, true);
        if (!is_array($data)) {
            return $this->defaultInitialAdminState();
        }
        return $data;
    }

    private function defaultInitialAdminState(): array
    {
        $users = $this->usersWithHashes();
        if ($users === []) {
            return ['completed' => false, 'access_count' => 0, 'deadline_reached' => false, 'last_access_at' => ''];
        }
        $admin = null;
        foreach ($users as $user) {
            if (($user['role'] ?? '') === 'admin') {
                $admin = $user;
                break;
            }
        }
        $usesInitialCredentials = $admin !== null
            && ($admin['username'] ?? '') === self::INITIAL_ADMIN_USERNAME
            && password_verify(self::INITIAL_ADMIN_PASSWORD, (string) ($admin['password_hash'] ?? ''));
        return ['completed' => !$usesInitialCredentials, 'access_count' => 0, 'deadline_reached' => false, 'last_access_at' => ''];
    }

    private function writeInitialAdminState(array $state): void
    {
        $payload = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($payload === false || file_put_contents($this->initialAdminStateFile, $payload, LOCK_EX) === false) {
            throw new \RuntimeException('初期管理者状態を書き込めません。');
        }
        if (!chmod($this->initialAdminStateFile, 0600)) {
            throw new \RuntimeException('初期管理者状態の権限を設定できません。');
        }
    }
}
