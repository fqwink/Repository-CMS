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
        file_put_contents($this->lockFile, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function clear(): void
    {
        if (is_file($this->lockFile)) {
            unlink($this->lockFile);
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

        $payload = json_decode((string) file_get_contents($this->lockFile), true);
        if (!is_array($payload)) {
            return ['locked' => true, 'reason' => 'ロック状態を読み取れません。'];
        }

        return [
            'locked' => (bool) ($payload['locked'] ?? true),
            'reason' => (string) ($payload['reason'] ?? ''),
        ];
    }

    public function locked(): bool
    {
        return $this->state()['locked'] === true;
    }
}
