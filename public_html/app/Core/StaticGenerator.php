<?php

declare(strict_types=1);

namespace RepositoryCms\Core;

final class StaticGenerator
{
    public function __construct(private readonly Runtime $runtime, private readonly Renderer $renderer)
    {
    }

    public function generate(): int
    {
        $outputs = $this->buildOutputs();
        foreach ($outputs as $output) {
            $workPath = $this->runtime->workData->write(basename($output['path']), $output['bytes']);
            if (!$this->runtime->workData->verified($workPath, $this->runtime->workData->checksum($output['bytes']))) {
                $this->runtime->locks->lock('静的生成作業データのチェックサムが一致しません。');
                throw new \RuntimeException('静的生成作業データの保全確認に失敗しました。');
            }
        }
        $this->runtime->workData->cleanupAfterVerified();
        return count($outputs);
    }

    public function publish(): int
    {
        if ($this->runtime->locks->locked()) {
            throw new \RuntimeException('CMSがロックされています。');
        }
        $outputs = $this->buildOutputs();
        foreach ($outputs as $output) {
            $workPath = $this->runtime->workData->write(basename($output['path']), $output['bytes']);
            $checksum = $this->runtime->workData->checksum($output['bytes']);
            if (!$this->runtime->workData->verified($workPath, $checksum)) {
                $this->runtime->locks->lock('公開作業データのチェックサムが一致しません。');
                throw new \RuntimeException('公開作業データの保全確認に失敗しました。');
            }
            $this->runtime->git->savePublicContent($output['path'], $output['bytes'], 'Repository CMS publish: ' . $output['path']);
            $fetched = $this->runtime->git->readPublicContent($output['path']);
            if (!hash_equals($checksum, $this->runtime->workData->checksum($fetched))) {
                $this->runtime->locks->lock('公開後の再取得チェックサムが一致しません。');
                throw new \RuntimeException('公開成果物の保全確認に失敗しました。');
            }
        }
        $this->runtime->workData->cleanupAfterVerified();
        return count($outputs);
    }

    private function buildOutputs(): array
    {
        if ($this->runtime->locks->locked()) {
            throw new \RuntimeException('CMSがロックされています。');
        }
        $outputs = [];
        foreach ($this->runtime->git->listContent() as $item) {
            $path = (string) $item['path'];
            $bytes = $this->runtime->git->readContent($path);
            $extension = Security::allowedExtension($path);
            if ($extension === 'md') {
                $outputs[] = [
                    'path' => preg_replace('/\.md$/', '.html', $path),
                    'bytes' => '<!doctype html><meta charset="utf-8">' . $this->renderer->markdown($bytes),
                ];
            } elseif (in_array($extension, ['json', 'png', 'svg'], true)) {
                $outputs[] = ['path' => $path, 'bytes' => $bytes];
            }
        }
        return $outputs;
    }
}
