<?php

declare(strict_types=1);

namespace RepositoryCms\Core;

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
            $this->runtime->locks->lock('メンテナンスモード中です。');
            foreach (($release['files'] ?? []) as $file) {
                $this->applyFile($file);
            }
            $this->runtime->workData->cleanupAfterVerified();
            $this->runtime->locks->lock($this->releaseWaitReason);
            $report['checks'][] = ['name' => 'アップデート適用', 'ok' => true, 'message' => 'Core更新ファイルを適用しました。'];
            $report['passed']++;
            return $report;
        } catch (\Throwable $error) {
            $this->runtime->locks->lock('アップデート適用に失敗しました: ' . $error->getMessage());
            try {
                $this->runtime->workData->cleanupAfterVerified();
            } catch (\Throwable) {
                $this->runtime->locks->lock('アップデート失敗後の作業データ削除に失敗しました。');
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
        $workPath = $this->runtime->workData->write(basename($path), $bytes);
        if (!$this->runtime->workData->verified($workPath, $checksum)) {
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
