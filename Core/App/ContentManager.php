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
        if (!Security::validContentPath($path)) {
            throw new \InvalidArgumentException('コンテンツパスが不正です。');
        }
        return $this->runtime->git->readContent($path);
    }

    public function save(string $path, string $bytes): void
    {
        if ($this->runtime->locks->locked()) {
            throw new \RuntimeException('CMSがロックされています。');
        }
        Security::validateContent($path, $bytes);
        $workPath = $this->runtime->workData->write(basename($path), $bytes);
        $checksum = $this->runtime->workData->checksum($bytes);
        try {
            if (!$this->runtime->workData->verified($workPath, $checksum)) {
                $this->runtime->locks->lock('保存作業データのチェックサムが一致しません。');
                throw new \RuntimeException('保存作業データの保全確認に失敗しました。');
            }

            $this->runtime->git->saveContent($path, $bytes, 'Repository CMS save: ' . $path);
            $fetched = $this->runtime->git->readContent($path);
            if (!hash_equals($checksum, $this->runtime->workData->checksum($fetched))) {
                $this->runtime->locks->lock('保存後の再取得チェックサムが一致しません。');
                throw new \RuntimeException('データ保全確認に失敗しました。');
            }

            $this->runtime->workData->cleanupAfterVerified();
        } catch (\Throwable $error) {
            if (!$this->runtime->locks->locked()) {
                $this->runtime->locks->lock('保存処理の保全確認に失敗しました。');
            }
            $this->runtime->workData->cleanupAfterVerified();
            throw $error;
        }
    }

    public function history(string $path): array
    {
        if (!Security::validContentPath($path)) {
            throw new \InvalidArgumentException('コンテンツパスが不正です。');
        }
        return $this->runtime->git->history($path);
    }

    public function restore(string $path, string $ref): void
    {
        if ($this->runtime->locks->locked()) {
            throw new \RuntimeException('CMSがロックされています。');
        }
        $bytes = $this->runtime->git->readContentAt($path, $ref);
        $this->save($path, $bytes);
    }
}
