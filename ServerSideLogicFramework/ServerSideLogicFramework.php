<?php

declare(strict_types=1);

namespace ServerSideLogicFramework;

use RepositoryCms\Core\Config;
use RepositoryCms\Core\Response;
use RepositoryCms\Core\Runtime;

final class ServerSideLogicFramework
{
    public const VERSION = 'v.0.6';

    public function __construct(
        private readonly Auth $auth,
        private readonly LockManager $locks,
        private readonly ?WorkData $workData = null,
    ) {
    }

    public function boot(string $maintenanceReleaseWaitReason): void
    {
        $this->releaseMaintenanceIfReady($maintenanceReleaseWaitReason);
        $this->auth->ensureInitialAdmin();
    }

    public function requireLogin(): void
    {
        $this->auth->requireLogin();
    }

    public function user(): ?string
    {
        return $this->auth->user();
    }

    public function role(): ?string
    {
        return $this->auth->role();
    }

    public function logout(): void
    {
        $this->auth->logout();
    }

    public function login(string $username, string $password): bool
    {
        return $this->auth->login($username, $password);
    }

    public function loginLocked(): bool
    {
        return $this->auth->loginLocked();
    }

    public function loginLockedUntil(): int
    {
        return $this->auth->loginLockedUntil();
    }

    public function initialAdminChangeRequired(): bool
    {
        return $this->auth->initialAdminChangeRequired();
    }

    public function initialAdminCompleted(): bool
    {
        return $this->auth->initialAdminCompleted();
    }

    public function initialAdminState(): array
    {
        return $this->auth->initialAdminState();
    }

    public function recordInitialAdminAccess(): void
    {
        $this->auth->recordInitialAdminAccess();
    }

    public function completeInitialAdminChange(string $username, string $password): void
    {
        $this->auth->completeInitialAdminChange($username, $password);
    }

    public function users(): array
    {
        return $this->auth->users();
    }

    public function createUser(string $username, string $password, string $role): void
    {
        $this->auth->createUser($username, $password, $role);
    }

    public function changePassword(string $username, string $password): void
    {
        $this->auth->changePassword($username, $password);
    }

    public function authorize(string $action): void
    {
        if (!$this->knownOperation($action)) {
            return;
        }
        if (!$this->auth->configured()) {
            if ($action === 'index' || $action === 'login') {
                return;
            }
            throw new \RuntimeException('認可されていない操作です。');
        }
        if ($action !== 'login' && $this->auth->user() === null) {
            if ($action === 'index') {
                return;
            }
            throw new \RuntimeException('認証が必要です。');
        }
        if (!$this->roleAllowed($action)) {
            throw new \RuntimeException('この操作を行う権限がありません。');
        }
        if (!$this->locks->locked()) {
            return;
        }
        if (in_array($action, ['index', 'updates', 'logout'], true)) {
            return;
        }
        throw new \RuntimeException('CMSがロックされているため、この操作は実行できません。');
    }

    public function requestMethod(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public function csrfToken(): string
    {
        return Security::csrfToken();
    }

    public function requireCsrf(): void
    {
        Security::requireCsrf();
    }

    public function locked(): bool
    {
        return $this->locks->locked();
    }

    public function lock(string $reason): void
    {
        $this->locks->lock($reason);
    }

    public function lockState(): array
    {
        return $this->locks->state();
    }

    public function ensureUnlocked(): void
    {
        if ($this->locked()) {
            throw new \RuntimeException('CMSがロックされています。');
        }
    }

    public function validContentPath(string $path): bool
    {
        return Security::validContentPath($path);
    }

    public function assertContentPath(string $path): void
    {
        if (!$this->validContentPath($path)) {
            throw new \InvalidArgumentException('コンテンツパスが不正です。');
        }
    }

    public function validateContent(string $path, string $bytes): void
    {
        Security::validateContent($path, $bytes);
    }

    public function allowedExtension(string $path): string
    {
        return Security::allowedExtension($path);
    }

    public function validPublicPath(string $path): bool
    {
        return Security::validPublicPath($path);
    }

    public function assertPublicPath(string $path): void
    {
        if (!$this->validPublicPath($path)) {
            throw new \InvalidArgumentException('公開パスが不正です。');
        }
    }

    public function checksum(string $bytes): string
    {
        return $this->requireWorkData()->checksum($bytes);
    }

    public function writeWorkData(string $name, string $bytes): string
    {
        return $this->requireWorkData()->write($name, $bytes);
    }

    public function verifyWorkData(string $path, string $checksum): bool
    {
        return $this->requireWorkData()->verified($path, $checksum);
    }

    public function cleanupWorkData(): void
    {
        $this->requireWorkData()->cleanupAfterVerified();
    }

    public function validateUpdate(Runtime $runtime, array $release): array
    {
        return (new UpdateValidator($runtime))->validate($release);
    }

    public function applyUpdate(Runtime $runtime, array $release, string $releaseWaitReason): array
    {
        return (new UpdateApplier($runtime, $releaseWaitReason))->apply($release);
    }

    public static function validContentPathStatic(string $path): bool
    {
        return Security::validContentPath($path);
    }

    public static function validPublicPathStatic(string $path): bool
    {
        return Security::validPublicPath($path);
    }

    public static function validRepositoryPathStatic(string $path): bool
    {
        return Security::validRepositoryPath($path);
    }

    private function requireWorkData(): WorkData
    {
        if ($this->workData === null) {
            throw new \RuntimeException('作業データ保全機能を利用できません。');
        }
        return $this->workData;
    }

    private function releaseMaintenanceIfReady(string $reason): void
    {
        $state = $this->locks->state();
        if ($state['locked'] !== true || $state['reason'] !== $reason) {
            return;
        }
        $created = strtotime((string) ($state['created_at'] ?? ''));
        if ($created !== false && time() - $created >= 300) {
            $this->locks->clear();
        }
    }

    private function knownOperation(string $action): bool
    {
        return in_array($action, [
            'index',
            'login',
            'initial_admin',
            'logout',
            'new',
            'edit',
            'save',
            'history',
            'restore',
            'preview',
            'generate',
            'publish',
            'themes',
            'theme_save',
            'updates',
            'update_validate',
            'update_apply',
            'users',
            'user_create',
            'user_password',
        ], true);
    }

    private function roleAllowed(string $action): bool
    {
        $role = $this->auth->role();
        if ($role === 'admin') {
            return true;
        }
        if ($role === 'editor') {
            return in_array($action, ['index', 'logout', 'new', 'edit', 'save', 'history', 'restore', 'preview', 'generate'], true);
        }
        return in_array($action, ['index', 'login'], true);
    }
}

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

final class LockManager
{
    private string $lockFile;

    public function __construct(string $lockFile)
    {
        $this->lockFile = $lockFile;
    }

    public function lock(string $reason): void
    {
        $payload = [
            'locked' => true,
            'reason' => $reason,
            'created_at' => gmdate(DATE_ATOM),
        ];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($this->lockFile, $json, LOCK_EX) === false) {
            throw new \RuntimeException('CMSロック状態を書き込めません。');
        }
        if (!chmod($this->lockFile, 0600)) {
            throw new \RuntimeException('CMSロック状態の権限を設定できません。');
        }
    }

    public function clear(): void
    {
        if (is_file($this->lockFile) && !unlink($this->lockFile)) {
            throw new \RuntimeException('CMSロック状態を解除できません。');
        }
    }

    public function clearIfReason(string $reason): void
    {
        $state = $this->state();
        if ($state['locked'] === true && $state['reason'] === $reason) {
            $this->clear();
        }
    }

    public function state(): array
    {
        if (!is_file($this->lockFile)) {
            return ['locked' => false, 'reason' => ''];
        }

        $bytes = file_get_contents($this->lockFile);
        if ($bytes === false) {
            return ['locked' => true, 'reason' => 'ロック状態を読み取れません。'];
        }
        $payload = json_decode($bytes, true);
        if (!is_array($payload)) {
            return ['locked' => true, 'reason' => 'ロック状態を読み取れません。'];
        }

        return [
            'locked' => (bool) ($payload['locked'] ?? true),
            'reason' => (string) ($payload['reason'] ?? ''),
            'created_at' => (string) ($payload['created_at'] ?? ''),
        ];
    }

    public function locked(): bool
    {
        return $this->state()['locked'] === true;
    }
}

final class Security
{
    private const EXTENSIONS = ['md', 'json', 'png', 'svg'];
    private const PUBLIC_EXTENSIONS = ['html', 'json', 'png', 'svg', 'css', 'js'];

    public static function validContentPath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..')) {
            return false;
        }
        if (!preg_match('/^[A-Za-z0-9_\/.-]+$/', $path)) {
            return false;
        }
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($extension, self::EXTENSIONS, true);
    }

    public static function allowedExtension(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, self::EXTENSIONS, true)) {
            throw new \InvalidArgumentException('許可されていない拡張子です。');
        }
        return $extension;
    }

    public static function validateContent(string $path, string $bytes): void
    {
        $extension = self::allowedExtension($path);
        if ($extension === 'json') {
            json_decode($bytes, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException('JSONの形式が不正です。');
            }
            return;
        }
        if ($extension === 'png' && !str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            throw new \InvalidArgumentException('PNGの形式が不正です。');
        }
        if ($extension === 'svg') {
            if (!preg_match('/<svg[\s>]/i', $bytes)) {
                throw new \InvalidArgumentException('SVGの形式が不正です。');
            }
            if (preg_match('/<script[\s>]|on[a-z]+\s*=/i', $bytes)) {
                throw new \InvalidArgumentException('SVGに許可されていないスクリプト要素があります。');
            }
        }
    }

    public static function validPublicPath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..')) {
            return false;
        }
        if (!preg_match('/^[A-Za-z0-9_\/.-]+$/', $path)) {
            return false;
        }
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($extension, self::PUBLIC_EXTENSIONS, true);
    }

    public static function validRepositoryPath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..')) {
            return false;
        }
        return preg_match('/^[A-Za-z0-9_\/.-]+$/', $path) === 1;
    }

    public static function csrfToken(): string
    {
        if (!isset($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['csrf'];
    }

    public static function requireCsrf(): void
    {
        $token = (string) ($_POST['csrf'] ?? '');
        if ($token === '' || !hash_equals(self::csrfToken(), $token)) {
            throw new \RuntimeException('CSRF検証に失敗しました。');
        }
    }
}

final class UpdateValidator
{
    public function __construct(private readonly Runtime $runtime)
    {
    }

    public function validate(array $release): array
    {
        $checks = [];
        $version = (string) ($release['version'] ?? '');
        $targetVersion = (string) ($release['target_version'] ?? '');
        $files = $release['files'] ?? null;

        $this->addCheck($checks, '現在バージョン', Config::VERSION !== '', Config::VERSION);
        $this->addCheck($checks, '更新先バージョン', $this->newerThanCurrent($version), $version === '' ? 'バージョンが不正です。' : $version);
        $this->addCheck($checks, '対象バージョン', $targetVersion === Config::VERSION, $targetVersion === '' ? '対象バージョンが不正です。' : $targetVersion);
        $this->addCheck($checks, '必須PHPバージョン', $this->phpAllowed((string) ($release['php'] ?? '')), PHP_VERSION);
        $this->addCheck($checks, 'ファイル一覧', is_array($files) && count($files) > 0, is_array($files) ? count($files) . ' files' : 'ファイル一覧が不正です。');
        $this->addCheck($checks, 'Work保全状態', $this->workClean(), 'Work/ は作業データなし');

        if (is_array($files)) {
            $this->validateFiles($files, $checks);
        }

        $failed = count(array_filter($checks, static fn (array $check): bool => $check['ok'] !== true));
        return [
            'version' => $version,
            'target_version' => $targetVersion,
            'valid' => $failed === 0,
            'passed' => count($checks) - $failed,
            'failed' => $failed,
            'checks' => $checks,
        ];
    }

    private function validateFiles(array $files, array &$checks): void
    {
        $phpTargets = $this->currentCoreAppPhpFiles();
        foreach ($files as $index => $file) {
            if (!is_array($file)) {
                $this->addCheck($checks, '更新ファイル #' . ($index + 1), false, 'ファイル定義が不正です。');
                continue;
            }
            $path = (string) ($file['path'] ?? '');
            $source = (string) ($file['source'] ?? '');
            $checksum = (string) ($file['checksum'] ?? '');

            $this->addCheck($checks, '対象パス ' . $path, $this->allowedCoreUpdatePath($path), $path === '' ? '対象パスが空です。' : $path);
            $this->addCheck($checks, '取得元 ' . $path, Security::validRepositoryPath($source), $source === '' ? '取得元が空です。' : $source);
            $this->addCheck($checks, 'チェックサム形式 ' . $path, preg_match('/^[a-f0-9]{64}$/', $checksum) === 1, $checksum === '' ? 'チェックサムが空です。' : $checksum);

            if ($this->allowedCoreUpdatePath($path) && str_starts_with($path, 'Core/App/') && str_ends_with($path, '.php') && substr_count($path, '/') === 2) {
                $phpTargets[$path] = true;
            }

            if ($source === '' || !Security::validRepositoryPath($source) || preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1) {
                continue;
            }
            try {
                $bytes = $this->runtime->git->readUpdateFile($source);
                $actual = hash('sha256', $bytes);
                $this->addCheck($checks, 'ファイルチェックサム ' . $path, hash_equals($checksum, $actual), $path);
            } catch (\Throwable $error) {
                $this->addCheck($checks, 'ファイル取得 ' . $path, false, $error->getMessage());
            }
        }
        $this->addCheck($checks, 'Core/App PHPファイル数', count($phpTargets) <= 23, count($phpTargets) . ' files');
    }

    private function allowedCoreUpdatePath(string $path): bool
    {
        if (!Security::validRepositoryPath($path)) {
            return false;
        }
        if ($path === 'Core/app.php' || $path === 'Core/.htaccess') {
            return true;
        }
        if (!str_starts_with($path, 'Core/App/')) {
            return false;
        }
        if ($path === 'Core/App/' || str_ends_with($path, '/')) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if (in_array(strtolower($segment), ['work', 'cache', 'tmp', 'temp'], true)) {
                return false;
            }
        }
        return !str_starts_with($path, 'Core/App/../')
            && !str_starts_with($path, 'Core/Config/')
            && !str_starts_with($path, 'Core/Data/');
    }

    private function currentCoreAppPhpFiles(): array
    {
        $files = [];
        foreach (glob($this->runtime->appRoot . '/*.php') ?: [] as $file) {
            $files['Core/App/' . basename($file)] = true;
        }
        return $files;
    }

    private function workClean(): bool
    {
        $entries = array_values(array_diff(scandir($this->runtime->workRoot) ?: [], ['.', '..']));
        return $entries === ['.gitignore'];
    }

    private function phpAllowed(string $required): bool
    {
        if ($required === '') {
            return false;
        }
        return version_compare(PHP_VERSION, $required, '>=');
    }

    private function newerThanCurrent(string $version): bool
    {
        if ($version === '') {
            return false;
        }
        return version_compare($this->versionNumber($version), $this->versionNumber(Config::VERSION), '>');
    }

    private function versionNumber(string $version): string
    {
        return ltrim($version, 'v.');
    }

    private function addCheck(array &$checks, string $name, bool $ok, string $message): void
    {
        $checks[] = [
            'name' => $name,
            'ok' => $ok,
            'message' => $message,
        ];
    }
}

final class UpdateApplier
{
    public function __construct(private readonly Runtime $runtime, private readonly string $releaseWaitReason)
    {
    }

    public function apply(array $release): array
    {
        $validator = new UpdateValidator($this->runtime);
        $report = $validator->validate($release);
        if ($report['valid'] !== true) {
            return $report;
        }

        try {
            $this->runtime->serverSide->lock('メンテナンスモード中です。');
            foreach (($release['files'] ?? []) as $file) {
                $this->applyFile($file);
            }
            $this->runtime->serverSide->cleanupWorkData();
            $this->runtime->serverSide->lock($this->releaseWaitReason);
            $report['checks'][] = ['name' => 'アップデート適用', 'ok' => true, 'message' => 'Core更新ファイルを適用しました。'];
            $report['passed']++;
            return $report;
        } catch (\Throwable $error) {
            $this->runtime->serverSide->lock('アップデート適用に失敗しました: ' . $error->getMessage());
            try {
                $this->runtime->serverSide->cleanupWorkData();
            } catch (\Throwable) {
                $this->runtime->serverSide->lock('アップデート失敗後の作業データ削除に失敗しました。');
            }
            throw $error;
        }
    }

    private function applyFile(array $file): void
    {
        $path = (string) ($file['path'] ?? '');
        $source = (string) ($file['source'] ?? '');
        $checksum = (string) ($file['checksum'] ?? '');
        $bytes = $this->runtime->git->readUpdateFile($source);
        if (!hash_equals($checksum, hash('sha256', $bytes))) {
            throw new \RuntimeException('アップデートファイルのチェックサムが一致しません: ' . $path);
        }
        $workPath = $this->runtime->serverSide->writeWorkData(basename($path), $bytes);
        if (!$this->runtime->serverSide->verifyWorkData($workPath, $checksum)) {
            throw new \RuntimeException('アップデート作業データの保全確認に失敗しました: ' . $path);
        }
        $target = $this->runtime->coreRoot . '/' . preg_replace('/^Core\//', '', $path);
        if (!is_string($target) || !str_starts_with($target, $this->runtime->coreRoot . '/')) {
            throw new \RuntimeException('アップデート対象パスが不正です: ' . $path);
        }
        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException('アップデート対象ディレクトリを作成できません: ' . $path);
        }
        if (is_dir($target)) {
            throw new \RuntimeException('アップデート対象がファイルではありません: ' . $path);
        }
        if (file_put_contents($target, $bytes, LOCK_EX) === false) {
            throw new \RuntimeException('アップデート対象を書き込めません: ' . $path);
        }
        $readBack = file_get_contents($target);
        if ($readBack === false || !hash_equals($checksum, hash('sha256', $readBack))) {
            throw new \RuntimeException('アップデート後の整合性確認に失敗しました: ' . $path);
        }
    }
}

final class WorkData
{
    public function __construct(private readonly string $workRoot, private readonly LockManager $locks)
    {
    }

    public function write(string $name, string $bytes): string
    {
        $path = $this->workRoot . '/' . bin2hex(random_bytes(8)) . '-' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $name);
        if (file_put_contents($path, $bytes, LOCK_EX) === false) {
            $this->locks->lock('作業データの作成に失敗しました。');
            throw new \RuntimeException('作業データの作成に失敗しました。');
        }
        return $path;
    }

    public function checksum(string $bytes): string
    {
        return hash('sha256', $bytes);
    }

    public function verified(string $path, string $checksum): bool
    {
        if (!is_file($path)) {
            return false;
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            return false;
        }
        return hash_equals($checksum, $this->checksum($bytes));
    }

    public function cleanupAfterVerified(): void
    {
        foreach (new \FilesystemIterator($this->workRoot, \FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item->getFilename() === '.gitignore') {
                continue;
            }
            if (!$this->deletePath($item->getPathname())) {
                $this->locks->lock('作業データの削除に失敗しました。');
                throw new \RuntimeException('作業データの削除に失敗しました。');
            }
        }
    }

    private function deletePath(string $path): bool
    {
        if (is_file($path) || is_link($path)) {
            return unlink($path);
        }
        if (is_dir($path)) {
            foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $child) {
                if (!$this->deletePath($child->getPathname())) {
                    return false;
                }
            }
            return rmdir($path);
        }
        return true;
    }
}
