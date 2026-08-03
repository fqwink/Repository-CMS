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
        $this->writeAuth($username, password_hash($password, PASSWORD_DEFAULT));
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
        session_regenerate_id(true);
        return true;
    }

    public function logout(): void
    {
        $_SESSION = [];
        session_destroy();
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
        $data = json_decode((string) file_get_contents($this->authFile), true);
        if (!is_array($data) || !isset($data['username'], $data['password_hash'])) {
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

        file_put_contents($this->authFile, $payload, LOCK_EX);
        chmod($this->authFile, 0600);
    }
}
