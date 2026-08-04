<?php

declare(strict_types=1);

namespace RepositoryCms\Core;

final class ContentManager
{
    public function __construct(private readonly Runtime $runtime)
    {
    }

    public function list(): array
    {
        return $this->runtime->git->listContent();
    }

    public function read(string $path): string
    {
        $this->runtime->serverSide->assertContentPath($path);
        return $this->runtime->git->readContent($path);
    }

    public function save(string $path, string $bytes): void
    {
        $this->runtime->serverSide->ensureUnlocked();
        $this->runtime->serverSide->validateContent($path, $bytes);
        $workPath = $this->runtime->serverSide->writeWorkData(basename($path), $bytes);
        $checksum = $this->runtime->serverSide->checksum($bytes);
        try {
            if (!$this->runtime->serverSide->verifyWorkData($workPath, $checksum)) {
                $this->runtime->serverSide->lock('保存作業データのチェックサムが一致しません。');
                throw new \RuntimeException('保存作業データの保全確認に失敗しました。');
            }

            $this->runtime->git->saveContent($path, $bytes, 'Repository CMS save: ' . $path);
            $fetched = $this->runtime->git->readContent($path);
            if (!hash_equals($checksum, $this->runtime->serverSide->checksum($fetched))) {
                $this->runtime->serverSide->lock('保存後の再取得チェックサムが一致しません。');
                throw new \RuntimeException('データ保全確認に失敗しました。');
            }

            $this->runtime->serverSide->cleanupWorkData();
        } catch (\Throwable $error) {
            if (!$this->runtime->serverSide->locked()) {
                $this->runtime->serverSide->lock('保存処理の保全確認に失敗しました。');
            }
            $this->runtime->serverSide->cleanupWorkData();
            throw $error;
        }
    }

    public function history(string $path): array
    {
        $this->runtime->serverSide->assertContentPath($path);
        return $this->runtime->git->history($path);
    }

    public function restore(string $path, string $ref): void
    {
        $this->runtime->serverSide->ensureUnlocked();
        $bytes = $this->runtime->git->readContentAt($path, $ref);
        $this->save($path, $bytes);
    }
}
