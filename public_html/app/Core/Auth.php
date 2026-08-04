<?php

declare(strict_types=1);

namespace RepositoryCms\Core;

final class Auth
{
    public function __construct(private readonly string $authFile)
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('repository_cms');
            session_set_cookie_params([
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
        if (trim($password) === '') {
            throw new \InvalidArgumentException('パスワードが空です。');
        }
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if ($passwordHash === false) {
            throw new \RuntimeException('パスワードをハッシュ化できません。');
        }
        $this->writeAuth($username, $passwordHash);
    }

    public function login(string $username, string $password): bool
    {
        $data = $this->readAuth();
        if ($data === null || !hash_equals($data['username'], $username)) {
            return false;
        }
        if (!password_verify($password, $data['password_hash'])) {
            return false;
        }
        $_SESSION['admin'] = $data['username'];
        if (!session_regenerate_id(true)) {
            unset($_SESSION['admin']);
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
        return isset($_SESSION['admin']) ? (string) $_SESSION['admin'] : null;
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
}
