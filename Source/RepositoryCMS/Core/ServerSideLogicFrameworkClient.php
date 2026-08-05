<?php

declare(strict_types=1);

namespace ServerSideLogicFramework;

use RepositoryCms\Core\Runtime;

final class ServerSideLogicFrameworkClient
{
    public const VERSION = ServerSideLogicFramework::VERSION;

    public function __construct(private readonly ServerSideLogicFramework $framework)
    {
    }

    public static function fromStorage(
        string $authPath,
        string $loginStatePath,
        string $initialAdminStatePath,
        string $lockPath,
        string $workRoot,
    ): self {
        $locks = new LockManager($lockPath);
        $workData = new WorkData($workRoot, $locks);
        $auth = new Auth($authPath, $loginStatePath, $initialAdminStatePath);

        return new self(new ServerSideLogicFramework($auth, $locks, $workData));
    }

    public function boot(string $maintenanceReleaseWaitReason): void
    {
        $this->framework->boot($maintenanceReleaseWaitReason);
    }

    public function requireLogin(): void
    {
        $this->framework->requireLogin();
    }

    public function user(): ?string
    {
        return $this->framework->user();
    }

    public function role(): ?string
    {
        return $this->framework->role();
    }

    public function logout(): void
    {
        $this->framework->logout();
    }

    public function login(string $username, string $password): bool
    {
        return $this->framework->login($username, $password);
    }

    public function loginLocked(): bool
    {
        return $this->framework->loginLocked();
    }

    public function loginLockedUntil(): int
    {
        return $this->framework->loginLockedUntil();
    }

    public function initialAdminChangeRequired(): bool
    {
        return $this->framework->initialAdminChangeRequired();
    }

    public function initialAdminCompleted(): bool
    {
        return $this->framework->initialAdminCompleted();
    }

    public function initialAdminState(): array
    {
        return $this->framework->initialAdminState();
    }

    public function recordInitialAdminAccess(): void
    {
        $this->framework->recordInitialAdminAccess();
    }

    public function completeInitialAdminChange(string $username, string $password): void
    {
        $this->framework->completeInitialAdminChange($username, $password);
    }

    public function users(): array
    {
        return $this->framework->users();
    }

    public function createUser(string $username, string $password, string $role): void
    {
        $this->framework->createUser($username, $password, $role);
    }

    public function changePassword(string $username, string $password): void
    {
        $this->framework->changePassword($username, $password);
    }

    public function authorize(string $action): void
    {
        $this->framework->authorize($action);
    }

    public function requestMethod(): string
    {
        return $this->framework->requestMethod();
    }

    public function csrfToken(): string
    {
        return $this->framework->csrfToken();
    }

    public function requireCsrf(): void
    {
        $this->framework->requireCsrf();
    }

    public function locked(): bool
    {
        return $this->framework->locked();
    }

    public function lock(string $reason): void
    {
        $this->framework->lock($reason);
    }

    public function clearLockIfReason(string $reason): void
    {
        $this->framework->clearLockIfReason($reason);
    }

    public function lockState(): array
    {
        return $this->framework->lockState();
    }

    public function ensureUnlocked(): void
    {
        $this->framework->ensureUnlocked();
    }

    public function validContentPath(string $path): bool
    {
        return $this->framework->validContentPath($path);
    }

    public function assertContentPath(string $path): void
    {
        $this->framework->assertContentPath($path);
    }

    public function validateContent(string $path, string $bytes): void
    {
        $this->framework->validateContent($path, $bytes);
    }

    public function allowedExtension(string $path): string
    {
        return $this->framework->allowedExtension($path);
    }

    public function validPublicPath(string $path): bool
    {
        return $this->framework->validPublicPath($path);
    }

    public function assertPublicPath(string $path): void
    {
        $this->framework->assertPublicPath($path);
    }

    public function validRepositoryPath(string $path): bool
    {
        return ServerSideLogicFramework::validRepositoryPathStatic($path);
    }

    public function checksum(string $bytes): string
    {
        return $this->framework->checksum($bytes);
    }

    public function writeWorkData(string $name, string $bytes): string
    {
        return $this->framework->writeWorkData($name, $bytes);
    }

    public function verifyWorkData(string $path, string $checksum): bool
    {
        return $this->framework->verifyWorkData($path, $checksum);
    }

    public function cleanupWorkData(): void
    {
        $this->framework->cleanupWorkData();
    }

    public function validateUpdate(Runtime $runtime, array $release): array
    {
        return $this->framework->validateUpdate($runtime, $release);
    }

    public function applyUpdate(Runtime $runtime, array $release, string $releaseWaitReason): array
    {
        return $this->framework->applyUpdate($runtime, $release, $releaseWaitReason);
    }
}
