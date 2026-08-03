<?php

declare(strict_types=1);

namespace RepositoryCms\Core;

final class WorkData
{
    public function __construct(private readonly string $workRoot, private readonly LockManager $locks)
    {
    }

    public function write(string $name, string $bytes): string
    {
        $path = $this->workRoot . '/' . bin2hex(random_bytes(8)) . '-' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $name);
        file_put_contents($path, $bytes, LOCK_EX);
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
        return hash_equals($checksum, $this->checksum((string) file_get_contents($path)));
    }

    public function cleanupAfterVerified(): void
    {
        foreach (glob($this->workRoot . '/*') ?: [] as $path) {
            if (is_file($path) && !unlink($path)) {
                $this->locks->lock('作業データの削除に失敗しました。');
                return;
            }
        }
    }
}
