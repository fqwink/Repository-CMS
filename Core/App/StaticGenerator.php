<?php

declare(strict_types=1);

namespace RepositoryCms\Core;

final class StaticGenerator
{
    private const THEMES = [
        'standard' => [
            'name' => 'standard',
            'label' => 'Standard',
            'description' => '汎用サイト向け',
            'primary' => '#00a968',
            'secondary' => '#3498db',
            'accent' => '#40AAEF',
        ],
        'blog' => [
            'name' => 'blog',
            'label' => 'Blog',
            'description' => '記事中心サイト向け',
            'primary' => '#3498db',
            'secondary' => '#00a968',
            'accent' => '#40AAEF',
        ],
        'media' => [
            'name' => 'media',
            'label' => 'Media',
            'description' => '情報発信・広告枠想定サイト向け',
            'primary' => '#40AAEF',
            'secondary' => '#00a968',
            'accent' => '#58BE89',
        ],
    ];

    public function __construct(private readonly Runtime $runtime, private readonly Renderer $renderer)
    {
    }

    public static function themes(): array
    {
        return self::THEMES;
    }

    public static function validTheme(string $theme): bool
    {
        return isset(self::THEMES[$theme]);
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
            'theme' => $this->activeThemeName(),
            'items' => [],
        ];
        $theme = $this->activeTheme();

        foreach ($this->runtime->git->listContent() as $item) {
            $sourcePath = (string) ($item['path'] ?? '');
            $report['total']++;
            try {
                $output = $this->buildOutput($sourcePath, $theme);
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

    private function buildOutput(string $path, array $theme): array
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
                'bytes' => $this->wrapHtml($path, $this->renderer->markdown($bytes), $theme),
            ];
        }
        if (in_array($extension, ['json', 'png', 'svg'], true)) {
            return ['path' => $path, 'bytes' => $bytes];
        }
        throw new \RuntimeException('静的生成対象外です。');
    }

    private function activeThemeName(): string
    {
        $path = $this->runtime->dataRoot . '/theme/active.json';
        if (!is_file($path)) {
            return 'standard';
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            $this->runtime->locks->lock('テーマ設定を読み取れません。');
            throw new \RuntimeException('テーマ設定を読み取れません。');
        }
        $data = json_decode($bytes, true);
        $theme = is_array($data) ? (string) ($data['active_theme'] ?? '') : '';
        if (!self::validTheme($theme)) {
            $this->runtime->locks->lock('有効テーマが不正です。');
            throw new \RuntimeException('有効テーマが不正です。');
        }
        return $theme;
    }

    private function activeTheme(): array
    {
        $theme = self::THEMES[$this->activeThemeName()] ?? null;
        if (!is_array($theme) || !$this->validThemeDefinition($theme)) {
            $this->runtime->locks->lock('テーマを検証できません。');
            throw new \RuntimeException('テーマを検証できません。');
        }
        return $theme;
    }

    private function validThemeDefinition(array $theme): bool
    {
        foreach (['name', 'label', 'description', 'primary', 'secondary', 'accent'] as $key) {
            if (!isset($theme[$key]) || !is_string($theme[$key]) || $theme[$key] === '') {
                return false;
            }
        }
        foreach (['primary', 'secondary', 'accent'] as $key) {
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $theme[$key])) {
                return false;
            }
        }
        return self::validTheme($theme['name']);
    }

    private function wrapHtml(string $sourcePath, string $content, array $theme): string
    {
        $title = htmlspecialchars($sourcePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $primary = htmlspecialchars($theme['primary'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $secondary = htmlspecialchars($theme['secondary'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $accent = htmlspecialchars($theme['accent'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $themeName = htmlspecialchars($theme['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $title . '</title><style>:root{--primary:' . $primary . ';--secondary:' . $secondary . ';--accent:' . $accent . ';--ink:#17202a;--surface:#ecf0f1}body{margin:0;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--ink);background:#fff}header{border-bottom:4px solid var(--primary);padding:24px;background:var(--surface)}main{max-width:880px;margin:32px auto;padding:0 20px}a{color:var(--secondary)}.theme-mark{color:var(--accent);font-size:13px}</style></head><body data-theme="' . $themeName . '"><header><strong>Repository CMS</strong><div class="theme-mark">' . $themeName . '</div></header><main>' . $content . '</main></body></html>';
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
            if (!str_starts_with($bytes, '<!doctype html>') || !str_contains($bytes, 'data-theme=')) {
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
