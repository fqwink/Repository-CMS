<?php

declare(strict_types=1);

namespace ServerSideLogicFramework;

final class ServerSideLogicFramework
{
    public const VERSION = 'v.0.5';

    public function __construct(
        private readonly Auth $auth,
        private readonly LockManager $locks,
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
