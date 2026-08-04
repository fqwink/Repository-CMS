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
        return (int) $this->generateReport()['succeeded'];
    }

    public function generateReport(): array
    {
        return $this->processOutputs(false);
    }

    public function publish(): int
    {
        return (int) $this->publishReport()['succeeded'];
    }

    public function publishReport(): array
    {
        if ($this->runtime->locks->locked()) {
            throw new \RuntimeException('CMSがロックされています。');
        }
        return $this->processOutputs(true);
    }

    private function processOutputs(bool $publish): array
    {
        if ($this->runtime->locks->locked()) {
            throw new \RuntimeException('CMSがロックされています。');
        }
        $report = [
            'total' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'items' => [],
        ];

        foreach ($this->runtime->git->listContent() as $item) {
            $sourcePath = (string) ($item['path'] ?? '');
            $report['total']++;
            try {
                $output = $this->buildOutput($sourcePath);
                $checksum = $this->runtime->workData->checksum($output['bytes']);
                $this->validateGeneratedOutput($output['path'], $output['bytes'], $checksum);
                $workPath = $this->runtime->workData->write(basename($output['path']), $output['bytes']);
                if (!$this->runtime->workData->verified($workPath, $checksum)) {
                    $this->runtime->locks->lock('静的生成作業データのチェックサムが一致しません。');
                    throw new \RuntimeException('静的生成作業データの保全確認に失敗しました。');
                }
                if ($publish) {
                    $this->runtime->git->savePublicContent($output['path'], $output['bytes'], 'Repository CMS publish: ' . $output['path']);
                    $fetched = $this->runtime->git->readPublicContent($output['path']);
                    if (!hash_equals($checksum, $this->runtime->workData->checksum($fetched))) {
                        $this->runtime->locks->lock('公開後の再取得チェックサムが一致しません。');
                        throw new \RuntimeException('公開成果物の保全確認に失敗しました。');
                    }
                }
                $report['succeeded']++;
                $report['items'][] = [
                    'source_path' => $sourcePath,
                    'output_path' => $output['path'],
                    'extension' => strtolower(pathinfo($output['path'], PATHINFO_EXTENSION)),
                    'checksum' => $checksum,
                    'status' => 'success',
                    'reason' => '',
                ];
            } catch (\Throwable $error) {
                $report['failed']++;
                $report['items'][] = [
                    'source_path' => $sourcePath,
                    'output_path' => '',
                    'extension' => strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION)),
                    'checksum' => '',
                    'status' => 'failed',
                    'reason' => $error->getMessage(),
                ];
            }
        }

        try {
            $this->runtime->workData->cleanupAfterVerified();
        } catch (\Throwable $error) {
            $this->runtime->locks->lock('静的生成作業データの削除に失敗しました。');
            throw $error;
        }

        return $report;
    }

    private function buildOutput(string $path): array
    {
        if (!Security::validContentPath($path)) {
            throw new \InvalidArgumentException('コンテンツパスが不正です。');
        }
        $bytes = $this->runtime->git->readContent($path);
        Security::validateContent($path, $bytes);
        $extension = Security::allowedExtension($path);
        if ($extension === 'md') {
            $target = preg_replace('/\.md$/', '.html', $path);
            if (!is_string($target) || $target === '') {
                throw new \RuntimeException('静的生成パスを作成できません。');
            }
            return [
                'path' => $target,
                'bytes' => '<!doctype html><meta charset="utf-8">' . $this->renderer->markdown($bytes),
            ];
        }
        if (in_array($extension, ['json', 'png', 'svg'], true)) {
            return ['path' => $path, 'bytes' => $bytes];
        }
        throw new \RuntimeException('静的生成対象外です。');
    }

    private function validateGeneratedOutput(string $path, string $bytes, string $checksum): void
    {
        if (!Security::validPublicPath($path)) {
            $this->runtime->locks->lock('静的生成出力パスが不正です。');
            throw new \RuntimeException('静的生成出力パスが不正です。');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $checksum)) {
            $this->runtime->locks->lock('静的生成チェックサムが不正です。');
            throw new \RuntimeException('静的生成チェックサムが不正です。');
        }
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === 'html') {
            if (!str_starts_with($bytes, '<!doctype html>')) {
                $this->runtime->locks->lock('HTML生成結果が不正です。');
                throw new \RuntimeException('HTML生成結果が不正です。');
            }
            return;
        }
        if ($extension === 'json') {
            json_decode($bytes, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->runtime->locks->lock('JSON生成結果が不正です。');
                throw new \RuntimeException('JSON生成結果が不正です。');
            }
            return;
        }
        if ($extension === 'png') {
            if (!str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
                $this->runtime->locks->lock('PNG生成結果が不正です。');
                throw new \RuntimeException('PNG生成結果が不正です。');
            }
            return;
        }
        if ($extension === 'svg') {
            if (!preg_match('/<svg[\s>]/i', $bytes)) {
                $this->runtime->locks->lock('SVG生成結果が不正です。');
                throw new \RuntimeException('SVG生成結果が不正です。');
            }
            return;
        }
        $this->runtime->locks->lock('静的生成拡張子が不正です。');
        throw new \RuntimeException('静的生成拡張子が不正です。');
    }
}
