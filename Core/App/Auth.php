<?php

declare(strict_types=1);

namespace RepositoryCms\Core;

final class Auth
{
    private const PASSWORD_MIN_LENGTH = 12;
    private const MAX_LOGIN_FAILURES = 5;
    private const LOGIN_LOCK_SECONDS = 900;
    private const SESSION_LIFETIME_SECONDS = 1800;

    public function __construct(private readonly string $authFile, private readonly string $stateFile)
    {
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
        return is_file($this->authFile);
    }

    public function setup(string $username, string $password): void
    {
        if ($this->configured()) {
            throw new \RuntimeException('管理者は設定済みです。');
        }
        $this->assertPasswordAllowed($password);
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if ($passwordHash === false) {
            throw new \RuntimeException('パスワードをハッシュ化できません。');
        }
        $this->writeAuth($username, $passwordHash);
    }

    public function login(string $username, string $password): bool
    {
        if ($this->loginLocked()) {
            throw new \RuntimeException('ログイン失敗が多いため一時ロックされています。');
        }
        $data = $this->readAuth();
        if ($data === null || !hash_equals($data['username'], $username)) {
            $this->recordLoginFailure();
            return false;
        }
        if (!password_verify($password, $data['password_hash'])) {
            $this->recordLoginFailure();
            return false;
        }
        $this->clearLoginState();
        $_SESSION['admin'] = $data['username'];
        $_SESSION['authenticated_at'] = time();
        $_SESSION['last_seen_at'] = time();
        if (!session_regenerate_id(true)) {
            unset($_SESSION['admin']);
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

    private function readAuth(): ?array
    {
        if (!is_file($this->authFile)) {
            return null;
        }
        $bytes = file_get_contents($this->authFile);
        if ($bytes === false) {
            return null;
        }
        $data = json_decode($bytes, true);
        if (!is_array($data) || !isset($data['username'], $data['password_hash'])) {
            return null;
        }
        if (!is_string($data['username']) || !is_string($data['password_hash'])) {
            return null;
        }
        return ['username' => (string) $data['username'], 'password_hash' => (string) $data['password_hash']];
    }

    private function writeAuth(string $username, string $passwordHash): void
    {
        $username = trim($username);
        if ($username === '' || strlen($passwordHash) < 20) {
            throw new \InvalidArgumentException('管理者情報が不正です。');
        }
        $payload = json_encode([
            'username' => $username,
            'password_hash' => $passwordHash,
            'created_at' => gmdate(DATE_ATOM),
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
}
