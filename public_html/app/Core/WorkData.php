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
        foreach (glob($this->workRoot . '/*') ?: [] as $path) {
            if (!$this->deletePath($path)) {
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
            foreach (glob($path . '/*') ?: [] as $child) {
                if (!$this->deletePath($child)) {
                    return false;
                }
            }
            return rmdir($path);
        }
        return true;
    }
}
