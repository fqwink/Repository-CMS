<?php

declare(strict_types=1);

namespace RepositoryCms\Core;

final class LockManager
{
    private string $lockFile;

    public function __construct(string $lockRoot)
    {
        $this->lockFile = $lockRoot . '/cms.lock.json';
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
