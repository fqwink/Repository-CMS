<?php

declare(strict_types=1);

namespace RepositoryCms\Core;

final class StaticGenerator
{
    private const THEME_NAMES = ['standard', 'blog', 'media'];
    private static ?array $themeCache = null;

    public function __construct(private readonly Runtime $runtime, private readonly Renderer $renderer)
    {
    }

    public static function themes(): array
    {
        if (self::$themeCache !== null) {
            return self::$themeCache;
        }

        $themes = [];
        foreach (self::THEME_NAMES as $name) {
            $path = __DIR__ . '/Themes/' . $name . '.json';
            if (!is_file($path)) {
                throw new \RuntimeException('標準テーマ定義が存在しません: ' . $name);
            }
            $bytes = file_get_contents($path);
            if ($bytes === false) {
                throw new \RuntimeException('標準テーマ定義を読み取れません: ' . $name);
            }
            $theme = json_decode($bytes, true);
            if (!is_array($theme) || !self::validThemeSource($name, $theme)) {
                throw new \RuntimeException('標準テーマ定義が不正です: ' . $name);
            }
            $themes[$name] = $theme;
        }

        self::$themeCache = $themes;
        return self::$themeCache;
    }

    public static function validTheme(string $theme): bool
    {
        return isset(self::themes()[$theme]);
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
        if ($this->runtime->serverSide->locked()) {
            throw new \RuntimeException('CMSがロックされています。');
        }
        return $this->processOutputs(true);
    }

    private function processOutputs(bool $publish): array
    {
        if ($this->runtime->serverSide->locked()) {
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
                $checksum = $this->runtime->serverSide->checksum($output['bytes']);
                $this->validateGeneratedOutput($output['path'], $output['bytes'], $checksum);
                $workPath = $this->runtime->serverSide->writeWorkData(basename($output['path']), $output['bytes']);
                if (!$this->runtime->serverSide->verifyWorkData($workPath, $checksum)) {
                    $this->runtime->serverSide->lock('静的生成作業データのチェックサムが一致しません。');
                    throw new \RuntimeException('静的生成作業データの保全確認に失敗しました。');
                }
                if ($publish) {
                    $this->runtime->git->savePublicContent($output['path'], $output['bytes'], 'Repository CMS publish: ' . $output['path']);
                    $fetched = $this->runtime->git->readPublicContent($output['path']);
                    if (!hash_equals($checksum, $this->runtime->serverSide->checksum($fetched))) {
                        $this->runtime->serverSide->lock('公開後の再取得チェックサムが一致しません。');
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
            $this->runtime->serverSide->cleanupWorkData();
        } catch (\Throwable $error) {
            $this->runtime->serverSide->lock('静的生成作業データの削除に失敗しました。');
            throw $error;
        }

        return $report;
    }

    private function buildOutput(string $path, array $theme): array
    {
        if (!$this->runtime->serverSide->validContentPath($path)) {
            throw new \InvalidArgumentException('コンテンツパスが不正です。');
        }
        $bytes = $this->runtime->git->readContent($path);
        $this->runtime->serverSide->validateContent($path, $bytes);
        $extension = $this->runtime->serverSide->allowedExtension($path);
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
        $path = $this->runtime->configRoot . '/theme.json';
        if (!is_file($path)) {
            return 'standard';
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            $this->runtime->serverSide->lock('テーマ設定を読み取れません。');
            throw new \RuntimeException('テーマ設定を読み取れません。');
        }
        $data = json_decode($bytes, true);
        $theme = is_array($data) ? (string) ($data['active_theme'] ?? '') : '';
        if (!self::validTheme($theme)) {
            $this->runtime->serverSide->lock('有効テーマが不正です。');
            throw new \RuntimeException('有効テーマが不正です。');
        }
        return $theme;
    }

    private function activeTheme(): array
    {
        $theme = self::themes()[$this->activeThemeName()] ?? null;
        if (!is_array($theme) || !$this->validThemeDefinition($theme)) {
            $this->runtime->serverSide->lock('テーマを検証できません。');
            throw new \RuntimeException('テーマを検証できません。');
        }
        return $theme;
    }

    private function validThemeDefinition(array $theme): bool
    {
        return self::validThemeSource((string) ($theme['name'] ?? ''), $theme);
    }

    private static function validThemeSource(string $name, array $theme): bool
    {
        if ($name === '' || !in_array($name, self::THEME_NAMES, true)) {
            return false;
        }
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
        return $theme['name'] === $name;
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
        if (!$this->runtime->serverSide->validPublicPath($path)) {
            $this->runtime->serverSide->lock('静的生成出力パスが不正です。');
            throw new \RuntimeException('静的生成出力パスが不正です。');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $checksum)) {
            $this->runtime->serverSide->lock('静的生成チェックサムが不正です。');
            throw new \RuntimeException('静的生成チェックサムが不正です。');
        }
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === 'html') {
            if (!str_starts_with($bytes, '<!doctype html>') || !str_contains($bytes, 'data-theme=')) {
                $this->runtime->serverSide->lock('HTML生成結果が不正です。');
                throw new \RuntimeException('HTML生成結果が不正です。');
            }
            return;
        }
        if ($extension === 'json') {
            json_decode($bytes, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->runtime->serverSide->lock('JSON生成結果が不正です。');
                throw new \RuntimeException('JSON生成結果が不正です。');
            }
            return;
        }
        if ($extension === 'png') {
            if (!str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
                $this->runtime->serverSide->lock('PNG生成結果が不正です。');
                throw new \RuntimeException('PNG生成結果が不正です。');
            }
            return;
        }
        if ($extension === 'svg') {
            if (!preg_match('/<svg[\s>]/i', $bytes)) {
                $this->runtime->serverSide->lock('SVG生成結果が不正です。');
                throw new \RuntimeException('SVG生成結果が不正です。');
            }
            return;
        }
        $this->runtime->serverSide->lock('静的生成拡張子が不正です。');
        throw new \RuntimeException('静的生成拡張子が不正です。');
    }
}
