<?php

declare(strict_types=1);

namespace RepositoryCms\Core;

use ServerSideLogicFramework\ServerSideLogicFrameworkClient;

spl_autoload_register(static function (string $class): void {
    $prefix = 'ServerSideLogicFramework\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $framework = dirname(__DIR__, 2) . '/ServerSideLogicFramework/ServerSideLogicFramework.php';
    if (is_file($framework)) {
        require_once $framework;
    }

    if ($class !== 'ServerSideLogicFramework\\ServerSideLogicFrameworkClient') {
        return;
    }

    $source = dirname(__DIR__, 2) . '/ServerSideLogicFramework/ServerSideLogicFrameworkClient.php';
    $copy = __DIR__ . '/ServerSideLogicFrameworkClient.php';
    if (!is_file($copy)) {
        throw new \RuntimeException('ServerSideLogicFrameworkクライアントツールがCoreへ組み込まれていません。');
    }
    if (!is_file($source)) {
        throw new \RuntimeException('ServerSideLogicFrameworkクライアントツール正本を確認できません。');
    }
    if (!hash_equals(hash_file('sha256', $source) ?: '', hash_file('sha256', $copy) ?: '')) {
        throw new \RuntimeException('ServerSideLogicFrameworkクライアントツールが正本と一致しません。');
    }
    require_once $copy;
});

final class Bootstrap
{
    public static function run(string $coreRoot): void
    {
        $runtime = Runtime::create($coreRoot);
        (new App($runtime))->handle();
    }
}

final class Config
{
    public const VERSION = 'v.0.14';

    public function __construct(
        public readonly string $provider,
        public readonly string $githubToken,
        public readonly string $githubOwner,
        public readonly string $contentRepository,
        public readonly string $publicRepository,
        public readonly string $opsRepository,
        public readonly string $updateRepository,
        public readonly string $updateBranch,
        public readonly string $updateManifestPath,
        public readonly string $branch,
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(
            strtolower((string) getenv('REPOSITORY_CMS_GIT_PROVIDER')),
            (string) getenv('REPOSITORY_CMS_GITHUB_TOKEN'),
            (string) getenv('REPOSITORY_CMS_GITHUB_OWNER'),
            (string) getenv('REPOSITORY_CMS_CONTENT_REPO'),
            (string) getenv('REPOSITORY_CMS_PUBLIC_REPO'),
            (string) getenv('REPOSITORY_CMS_OPS_REPO'),
            (string) getenv('REPOSITORY_CMS_UPDATE_REPO'),
            (string) (getenv('REPOSITORY_CMS_UPDATE_BRANCH') ?: 'main'),
            (string) (getenv('REPOSITORY_CMS_UPDATE_MANIFEST') ?: 'updates/releases.json'),
            (string) (getenv('REPOSITORY_CMS_BRANCH') ?: 'main'),
        );
    }

    public function gitConfigured(): bool
    {
        return $this->provider === 'github'
            && $this->githubToken !== ''
            && $this->githubOwner !== ''
            && $this->contentRepository !== ''
            && $this->publicRepository !== ''
            && $this->opsRepository !== '';
    }

    public function updateConfigured(): bool
    {
        return $this->gitConfigured()
            && $this->updateRepository !== ''
            && $this->updateBranch !== ''
            && $this->updateManifestPath !== '';
    }
}

interface GitProvider
{
    public function configured(): bool;
    public function listContent(): array;
    public function readContent(string $path): string;
    public function readContentAt(string $path, string $ref): string;
    public function saveContent(string $path, string $bytes, string $message): void;
    public function history(string $path): array;
    public function readPublicContent(string $path): string;
    public function savePublicContent(string $path, string $bytes, string $message): void;
    public function saveOperationLog(array $event): void;
    public function listUpdateReleases(): array;
    public function readUpdateFile(string $path): string;
}

final class NullGitProvider implements GitProvider
{
    public function configured(): bool
    {
        return false;
    }

    public function listContent(): array
    {
        return [];
    }

    public function readContent(string $path): string
    {
        throw new \RuntimeException('Gitプロバイダーが未設定です。');
    }

    public function readContentAt(string $path, string $ref): string
    {
        throw new \RuntimeException('Gitプロバイダーが未設定です。');
    }

    public function saveContent(string $path, string $bytes, string $message): void
    {
        throw new \RuntimeException('Gitプロバイダーが未設定です。');
    }

    public function history(string $path): array
    {
        return [];
    }

    public function readPublicContent(string $path): string
    {
        throw new \RuntimeException('Gitプロバイダーが未設定です。');
    }

    public function savePublicContent(string $path, string $bytes, string $message): void
    {
        throw new \RuntimeException('Gitプロバイダーが未設定です。');
    }

    public function saveOperationLog(array $event): void
    {
        throw new \RuntimeException('Gitプロバイダーが未設定です。');
    }

    public function listUpdateReleases(): array
    {
        return [];
    }

    public function readUpdateFile(string $path): string
    {
        throw new \RuntimeException('Gitプロバイダーが未設定です。');
    }
}

final class Runtime
{
    public function __construct(
        public readonly string $coreRoot,
        public readonly string $configRoot,
        public readonly string $workRoot,
        public readonly Config $config,
        public readonly GitProvider $git,
        public readonly ServerSideLogicFrameworkClient $serverSideClient,
    ) {
    }

    public static function create(string $coreRoot): self
    {
        $root = dirname($coreRoot);
        $configRoot = $coreRoot . '/Config';
        $workRoot = $root . '/Work';
        self::ensureDirectory($configRoot);
        self::ensureDirectory($coreRoot . '/Lang');
        self::ensureDirectory($coreRoot . '/Themes');
        self::ensureDirectory($workRoot);

        $config = Config::fromEnvironment();
        $serverSideClient = ServerSideLogicFrameworkClient::fromStorage(
            $configRoot . '/auth.json',
            $configRoot . '/login_state.json',
            $configRoot . '/admin_initial_state.json',
            $configRoot . '/cms_lock.json',
            $workRoot,
        );
        $git = GitHubProvider::fromConfig($config, $serverSideClient);

        return new self(
            $coreRoot,
            $configRoot,
            $workRoot,
            $config,
            $git,
            $serverSideClient,
        );
    }

    private static function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new \RuntimeException('Directory creation failed: ' . $path);
        }
    }
}

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
        $this->runtime->serverSideClient->assertContentPath($path);
        return $this->runtime->git->readContent($path);
    }

    public function save(string $path, string $bytes): void
    {
        $this->runtime->serverSideClient->ensureUnlocked();
        $this->runtime->serverSideClient->validateContent($path, $bytes);
        $workPath = $this->runtime->serverSideClient->writeWorkData(basename($path), $bytes);
        $checksum = $this->runtime->serverSideClient->checksum($bytes);
        try {
            if (!$this->runtime->serverSideClient->verifyWorkData($workPath, $checksum)) {
                $this->runtime->serverSideClient->lock('保存作業データのチェックサムが一致しません。');
                throw new \RuntimeException('保存作業データの保全確認に失敗しました。');
            }

            $this->runtime->git->saveContent($path, $bytes, 'Repository CMS save: ' . $path);
            $fetched = $this->runtime->git->readContent($path);
            if (!hash_equals($checksum, $this->runtime->serverSideClient->checksum($fetched))) {
                $this->runtime->serverSideClient->lock('保存後の再取得チェックサムが一致しません。');
                throw new \RuntimeException('データ保全確認に失敗しました。');
            }

            $this->runtime->serverSideClient->cleanupWorkData();
        } catch (\Throwable $error) {
            if (!$this->runtime->serverSideClient->locked()) {
                $this->runtime->serverSideClient->lock('保存処理の保全確認に失敗しました。');
            }
            $this->runtime->serverSideClient->cleanupWorkData();
            throw $error;
        }
    }

    public function history(string $path): array
    {
        $this->runtime->serverSideClient->assertContentPath($path);
        return $this->runtime->git->history($path);
    }

    public function restore(string $path, string $ref): void
    {
        $this->runtime->serverSideClient->ensureUnlocked();
        $bytes = $this->runtime->git->readContentAt($path, $ref);
        $this->save($path, $bytes);
    }
}

final class GitHubProvider implements GitProvider
{
    private const API = 'https://api.github.com';

    public function __construct(private readonly Config $config, private readonly ServerSideLogicFrameworkClient $serverSideClient)
    {
    }

    public static function fromConfig(Config $config, ServerSideLogicFrameworkClient $serverSideClient): GitProvider
    {
        if (!$config->gitConfigured()) {
            $serverSideClient->lock('Gitプロバイダーが未設定です。');
            return new NullGitProvider();
        }
        $serverSideClient->clearLockIfReason('Gitプロバイダーが未設定です。');
        return new self($config, $serverSideClient);
    }

    public function configured(): bool
    {
        return true;
    }

    public function listContent(): array
    {
        $tree = $this->request('GET', '/repos/' . $this->repo($this->config->contentRepository) . '/git/trees/' . rawurlencode($this->config->branch) . '?recursive=1');
        $items = [];
        foreach (($tree['tree'] ?? []) as $node) {
            $path = (string) ($node['path'] ?? '');
            if (($node['type'] ?? '') === 'blob' && $this->serverSideClient->validContentPath($path)) {
                $items[] = ['path' => $path, 'size' => (int) ($node['size'] ?? 0)];
            }
        }
        usort($items, static fn (array $a, array $b): int => $a['path'] <=> $b['path']);
        return $items;
    }

    public function readContent(string $path): string
    {
        return $this->readFromRepository($this->config->contentRepository, $path);
    }

    public function readContentAt(string $path, string $ref): string
    {
        $this->assertContentPath($path);
        if (!preg_match('/^[A-Fa-f0-9]{7,40}$/', $ref)) {
            throw new \InvalidArgumentException('履歴参照が不正です。');
        }
        $data = $this->request('GET', '/repos/' . $this->repo($this->config->contentRepository) . '/contents/' . $this->encodePath($path) . '?ref=' . rawurlencode($ref));
        return $this->decodeContentResponse($data);
    }

    public function saveContent(string $path, string $bytes, string $message): void
    {
        $this->saveToRepository($this->config->contentRepository, $path, $bytes, $message);
    }

    public function history(string $path): array
    {
        $this->assertContentPath($path);
        $commits = $this->request('GET', '/repos/' . $this->repo($this->config->contentRepository) . '/commits?path=' . rawurlencode($path) . '&sha=' . rawurlencode($this->config->branch));
        $items = [];
        foreach ($commits as $commit) {
            $items[] = [
                'sha' => (string) ($commit['sha'] ?? ''),
                'date' => (string) ($commit['commit']['committer']['date'] ?? ''),
                'message' => (string) ($commit['commit']['message'] ?? ''),
            ];
        }
        return $items;
    }

    public function readPublicContent(string $path): string
    {
        if ($this->config->publicRepository === '') {
            throw new \RuntimeException('公開リポジトリが未設定です。');
        }
        $this->assertPublicPath($path);
        $data = $this->request('GET', '/repos/' . $this->repo($this->config->publicRepository) . '/contents/' . $this->encodePath($path) . '?ref=' . rawurlencode($this->config->branch));
        return $this->decodeContentResponse($data);
    }

    public function savePublicContent(string $path, string $bytes, string $message): void
    {
        $this->assertPublicPath($path);
        $this->saveToRepository($this->config->publicRepository, $path, $bytes, $message, true);
    }

    public function saveOperationLog(array $event): void
    {
        $event['recorded_at'] = gmdate(DATE_ATOM);
        $event['version'] = Config::VERSION;
        $bytes = json_encode($event, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($bytes === false) {
            throw new \RuntimeException('運用履歴をJSON化できません。');
        }
        $path = 'operations/' . gmdate('Y-m-d') . '/' . gmdate('His') . '-' . bin2hex(random_bytes(4)) . '.json';
        $this->saveToRepository($this->config->opsRepository, $path, $bytes, 'Repository CMS operation log: ' . ($event['type'] ?? 'event'));
    }

    public function listUpdateReleases(): array
    {
        if (!$this->config->updateConfigured()) {
            return [];
        }
        $bytes = $this->readUpdateFile($this->config->updateManifestPath);
        $manifest = json_decode($bytes, true);
        if (!is_array($manifest) || !isset($manifest['releases']) || !is_array($manifest['releases'])) {
            throw new \RuntimeException('アップデートリリース一覧が不正です。');
        }
        return $manifest['releases'];
    }

    public function readUpdateFile(string $path): string
    {
        if (!$this->config->updateConfigured()) {
            throw new \RuntimeException('開発元アップデートリポジトリが未設定です。');
        }
        if (!$this->serverSideClient->validRepositoryPath($path)) {
            throw new \InvalidArgumentException('アップデートファイルパスが不正です。');
        }
        $data = $this->request('GET', '/repos/' . $this->repo($this->config->updateRepository) . '/contents/' . $this->encodePath($path) . '?ref=' . rawurlencode($this->config->updateBranch));
        return $this->decodeContentResponse($data);
    }

    private function readFromRepository(string $repository, string $path): string
    {
        $this->assertContentPath($path);
        $data = $this->request('GET', '/repos/' . $this->repo($repository) . '/contents/' . $this->encodePath($path) . '?ref=' . rawurlencode($this->config->branch));
        return $this->decodeContentResponse($data);
    }

    private function saveToRepository(string $repository, string $path, string $bytes, string $message, bool $publicPath = false): void
    {
        if ($publicPath) {
            $this->assertPublicPath($path);
        } else {
            $this->assertContentPath($path);
        }
        $sha = null;
        try {
            $existing = $this->request('GET', '/repos/' . $this->repo($repository) . '/contents/' . $this->encodePath($path) . '?ref=' . rawurlencode($this->config->branch));
            if (($existing['type'] ?? '') !== 'file') {
                throw new \RuntimeException('GitHub path already exists and is not a file.');
            }
            $sha = (string) ($existing['sha'] ?? '');
        } catch (\RuntimeException $error) {
            if (!str_contains($error->getMessage(), ' 404 ')) {
                throw $error;
            }
        }

        $payload = [
            'message' => $message,
            'content' => base64_encode($bytes),
            'branch' => $this->config->branch,
        ];
        if ($sha !== null && $sha !== '') {
            $payload['sha'] = $sha;
        }

        $this->request('PUT', '/repos/' . $this->repo($repository) . '/contents/' . $this->encodePath($path), $payload);
    }

    private function request(string $method, string $path, ?array $payload = null): array
    {
        $headers = [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $this->config->githubToken,
            'User-Agent: Repository-CMS',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        $context = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true,
            ],
        ];
        if ($payload !== null) {
            $content = json_encode($payload, JSON_UNESCAPED_SLASHES);
            if ($content === false) {
                throw new \RuntimeException('GitHub API request payload is invalid.');
            }
            $context['http']['header'] .= "\r\nContent-Type: application/json";
            $context['http']['content'] = $content;
        }

        $body = file_get_contents(self::API . $path, false, stream_context_create($context));
        $status = $http_response_header[0] ?? '';
        if ($body === false || !preg_match('/\s(2\d\d)\s/', $status)) {
            throw new \RuntimeException('GitHub API request failed: ' . $status);
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('GitHub API response is invalid.');
        }
        return $decoded;
    }

    private function decodeContentResponse(array $data): string
    {
        if (($data['type'] ?? '') !== 'file' || !isset($data['content']) || !is_string($data['content'])) {
            throw new \RuntimeException('GitHub API response is not a file content.');
        }
        $content = preg_replace('/\s+/', '', $data['content']);
        if (!is_string($content)) {
            throw new \RuntimeException('GitHub API content cannot be normalized.');
        }
        $bytes = base64_decode($content, true);
        if ($bytes === false) {
            throw new \RuntimeException('GitHub API content cannot be decoded.');
        }
        return $bytes;
    }

    private function repo(string $repository): string
    {
        return rawurlencode($this->config->githubOwner) . '/' . rawurlencode($repository);
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private function assertContentPath(string $path): void
    {
        if (!$this->serverSideClient->validContentPath($path)) {
            throw new \InvalidArgumentException('コンテンツパスが不正です。');
        }
    }

    private function assertPublicPath(string $path): void
    {
        if (!$this->serverSideClient->validPublicPath($path)) {
            throw new \InvalidArgumentException('公開パスが不正です。');
        }
    }
}

final class Renderer
{
    public function __construct(private readonly ServerSideLogicFrameworkClient $serverSide)
    {
    }

    public function preview(string $path, string $bytes): string
    {
        $this->serverSide->validateContent($path, $bytes);
        return match ($this->serverSide->allowedExtension($path)) {
            'md' => $this->markdown($bytes),
            'json' => '<pre>' . Response::escape($this->json($bytes)) . '</pre>',
            'svg' => '<pre>' . Response::escape($bytes) . '</pre>',
            'png' => '<img alt="" style="max-width:100%;height:auto" src="data:image/png;base64,' . base64_encode($bytes) . '">',
            default => '',
        };
    }

    public function markdown(string $markdown): string
    {
        $html = [];
        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            $line = rtrim($line);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '# ')) {
                $html[] = '<h1>' . Response::escape(substr($line, 2)) . '</h1>';
            } elseif (str_starts_with($line, '## ')) {
                $html[] = '<h2>' . Response::escape(substr($line, 3)) . '</h2>';
            } elseif (str_starts_with($line, '- ')) {
                $html[] = '<p>&bull; ' . Response::escape(substr($line, 2)) . '</p>';
            } else {
                $html[] = '<p>' . Response::escape($line) . '</p>';
            }
        }
        return implode("\n", $html);
    }

    private function json(string $json): string
    {
        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $json;
        }
        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $json;
    }
}

final class Response
{
    public static function html(string $title, string $body, Runtime $runtime, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        $lock = $runtime->serverSideClient->lockState();
        $user = $runtime->serverSideClient->user();
        $lockHtml = $lock['locked']
            ? '<div class="alert">CMS LOCKED: ' . self::escape($lock['reason']) . '</div>'
            : '';
        $nav = '';
        if ($user !== null) {
            $admin = $runtime->serverSideClient->role() === 'admin';
            $nav = '<nav><a href="?">ダッシュボード</a><a href="?action=new">作成</a><a href="?action=generate">静的生成</a>';
            if ($admin) {
                $nav .= '<a href="?action=publish">公開</a><a href="?action=themes">テーマ</a><a href="?action=updates">アップデート</a><a href="?action=users">ユーザー</a>';
            }
            $nav .= '<a href="?action=logout">ログアウト</a></nav>';
        }

        echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . self::escape($title) . ' - Repository CMS</title>';
        echo '<style>' . self::style() . '</style></head><body>';
        echo '<header><h1>Repository CMS <span class="muted">' . self::escape(Config::VERSION) . '</span></h1>' . $nav . '</header><main>' . $lockHtml . $body . '</main></body></html>';
    }

    public static function redirect(string $to): never
    {
        header('Location: ' . $to, true, 302);
        exit;
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function style(): string
    {
        return ':root{--primary:#00a968;--secondary:#3498db;--accent:#40AAEF;--surface:#ecf0f1;--border:#ECEEF1;--support:#58BE89;--ink:#17202a;--muted:#697586;--danger:#b42318;--warning:#ad6800}body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0;background:var(--surface);color:var(--ink)}header{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px 24px;background:#fff;border-bottom:1px solid var(--border)}h1{font-size:20px;margin:0}h2{margin:0 0 12px}main{max-width:1120px;margin:24px auto;padding:0 16px}nav{display:flex;gap:8px;flex-wrap:wrap}nav a{border:1px solid var(--border);border-radius:6px;padding:8px 10px;background:#fff}a{color:var(--secondary);text-decoration:none}.page-head{margin-bottom:18px}.page-head h2{font-size:24px}.page-head p{color:var(--muted);margin:6px 0 0}.panel{background:#fff;border:1px solid var(--border);border-radius:8px;padding:18px;margin-bottom:16px}.alert{background:#fff3cd;border:1px solid #ffe08a;border-radius:8px;padding:12px;margin-bottom:16px}.notice{background:#eef9ff;border:1px solid var(--accent);border-radius:8px;padding:12px;margin-bottom:16px}.dashboard-grid,.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:16px}.status-card,.summary-grid div{background:#fff;border:1px solid var(--border);border-left:4px solid var(--secondary);border-radius:8px;padding:14px}.status-card span,.summary-grid span{display:block;color:var(--muted);font-size:13px;margin-bottom:8px}.status-card strong,.summary-grid strong{font-size:18px}.tone-ok{border-left-color:var(--primary)}.tone-info{border-left-color:var(--secondary)}.tone-warn{border-left-color:var(--warning)}.tone-danger{border-left-color:var(--danger)}.section-title{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}.section-title h2{margin:0}.badge{display:inline-block;background:#e9f8f1;color:#067647;border:1px solid #b7e4cf;border-radius:999px;padding:4px 8px;font-size:12px}.action-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}.action-tile{display:block;border:1px solid var(--border);border-radius:8px;padding:14px;background:#fff}.action-tile strong{display:block;color:var(--ink);margin-bottom:6px}.action-tile span{display:block;color:var(--muted);font-size:14px}.action-tile:hover{border-color:var(--primary)}.action-tile.disabled{background:#f7f8fa;opacity:.65}.theme-option{display:grid;grid-template-columns:auto 1fr 24px 24px 24px;align-items:center;gap:12px;border:1px solid var(--border);border-radius:8px;padding:12px;margin:12px 0}.theme-option em{display:block;color:var(--muted);font-style:normal;font-size:13px}.theme-option i{display:block;width:24px;height:24px;border:1px solid var(--border);border-radius:999px}label:not(.theme-option){display:block;margin:12px 0 6px;font-weight:600}input,textarea,select{width:100%;box-sizing:border-box;border:1px solid #c8d0dc;border-radius:6px;padding:10px;font:inherit}.theme-option input{width:auto}textarea{min-height:360px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}button,.button{display:inline-block;background:var(--primary);color:#fff;border:0;border-radius:6px;padding:10px 14px;font:inherit;cursor:pointer}.button.secondary{background:#eef1f5;color:var(--ink)}.row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.list{width:100%;border-collapse:collapse}.list th,.list td{text-align:left;border-bottom:1px solid var(--border);padding:10px}.table-actions{display:flex;gap:10px;flex-wrap:wrap}.muted{color:var(--muted)}code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}.preview{background:#fff;border:1px solid var(--border);border-radius:8px;padding:18px}@media(max-width:720px){header{align-items:flex-start;flex-direction:column}nav a{font-size:14px}.section-title{align-items:flex-start;flex-direction:column}.list{display:block;overflow-x:auto}.theme-option{grid-template-columns:auto 1fr}}';
    }
}

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
        if ($this->runtime->serverSideClient->locked()) {
            throw new \RuntimeException('CMSがロックされています。');
        }
        return $this->processOutputs(true);
    }

    private function processOutputs(bool $publish): array
    {
        if ($this->runtime->serverSideClient->locked()) {
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
                $checksum = $this->runtime->serverSideClient->checksum($output['bytes']);
                $this->validateGeneratedOutput($output['path'], $output['bytes'], $checksum);
                $workPath = $this->runtime->serverSideClient->writeWorkData(basename($output['path']), $output['bytes']);
                if (!$this->runtime->serverSideClient->verifyWorkData($workPath, $checksum)) {
                    $this->runtime->serverSideClient->lock('静的生成作業データのチェックサムが一致しません。');
                    throw new \RuntimeException('静的生成作業データの保全確認に失敗しました。');
                }
                if ($publish) {
                    $this->runtime->git->savePublicContent($output['path'], $output['bytes'], 'Repository CMS publish: ' . $output['path']);
                    $fetched = $this->runtime->git->readPublicContent($output['path']);
                    if (!hash_equals($checksum, $this->runtime->serverSideClient->checksum($fetched))) {
                        $this->runtime->serverSideClient->lock('公開後の再取得チェックサムが一致しません。');
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
            $this->runtime->serverSideClient->cleanupWorkData();
        } catch (\Throwable $error) {
            $this->runtime->serverSideClient->lock('静的生成作業データの削除に失敗しました。');
            throw $error;
        }

        return $report;
    }

    private function buildOutput(string $path, array $theme): array
    {
        if (!$this->runtime->serverSideClient->validContentPath($path)) {
            throw new \InvalidArgumentException('コンテンツパスが不正です。');
        }
        $bytes = $this->runtime->git->readContent($path);
        $this->runtime->serverSideClient->validateContent($path, $bytes);
        $extension = $this->runtime->serverSideClient->allowedExtension($path);
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
            $this->runtime->serverSideClient->lock('テーマ設定を読み取れません。');
            throw new \RuntimeException('テーマ設定を読み取れません。');
        }
        $data = json_decode($bytes, true);
        $theme = is_array($data) ? (string) ($data['active_theme'] ?? '') : '';
        if (!self::validTheme($theme)) {
            $this->runtime->serverSideClient->lock('有効テーマが不正です。');
            throw new \RuntimeException('有効テーマが不正です。');
        }
        return $theme;
    }

    private function activeTheme(): array
    {
        $theme = self::themes()[$this->activeThemeName()] ?? null;
        if (!is_array($theme) || !$this->validThemeDefinition($theme)) {
            $this->runtime->serverSideClient->lock('テーマを検証できません。');
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
        if (!$this->runtime->serverSideClient->validPublicPath($path)) {
            $this->runtime->serverSideClient->lock('静的生成出力パスが不正です。');
            throw new \RuntimeException('静的生成出力パスが不正です。');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $checksum)) {
            $this->runtime->serverSideClient->lock('静的生成チェックサムが不正です。');
            throw new \RuntimeException('静的生成チェックサムが不正です。');
        }
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === 'html') {
            if (!str_starts_with($bytes, '<!doctype html>') || !str_contains($bytes, 'data-theme=')) {
                $this->runtime->serverSideClient->lock('HTML生成結果が不正です。');
                throw new \RuntimeException('HTML生成結果が不正です。');
            }
            return;
        }
        if ($extension === 'json') {
            json_decode($bytes, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->runtime->serverSideClient->lock('JSON生成結果が不正です。');
                throw new \RuntimeException('JSON生成結果が不正です。');
            }
            return;
        }
        if ($extension === 'png') {
            if (!str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
                $this->runtime->serverSideClient->lock('PNG生成結果が不正です。');
                throw new \RuntimeException('PNG生成結果が不正です。');
            }
            return;
        }
        if ($extension === 'svg') {
            if (!preg_match('/<svg[\s>]/i', $bytes)) {
                $this->runtime->serverSideClient->lock('SVG生成結果が不正です。');
                throw new \RuntimeException('SVG生成結果が不正です。');
            }
            return;
        }
        $this->runtime->serverSideClient->lock('静的生成拡張子が不正です。');
        throw new \RuntimeException('静的生成拡張子が不正です。');
    }
}

final class App
{
    private const MAINTENANCE_RELEASE_WAIT_REASON = 'メンテナンス解除待機中です。';

    private ContentManager $content;
    private Renderer $renderer;

    public function __construct(private readonly Runtime $runtime)
    {
        $this->content = new ContentManager($runtime);
        $this->renderer = new Renderer($runtime->serverSideClient);
    }

    public function handle(): void
    {
        try {
            $this->runtime->serverSideClient->boot(self::MAINTENANCE_RELEASE_WAIT_REASON);
            $action = (string) ($_GET['action'] ?? 'index');
            if ($action === 'login') {
                $this->runtime->serverSideClient->authorize($action);
                $this->login();
                return;
            }
            if ($action === 'logout') {
                $this->runtime->serverSideClient->requireLogin();
                $this->runtime->serverSideClient->authorize($action);
                $user = $this->runtime->serverSideClient->user();
                $this->audit('auth.logout', ['user' => $user]);
                $this->runtime->serverSideClient->logout();
                Response::redirect('?action=login');
            }

            $this->runtime->serverSideClient->requireLogin();
            $this->redirectInitialAdminChange($action);
            $this->runtime->serverSideClient->authorize($action);
            match ($action) {
                'initial_admin' => $this->initialAdmin(),
                'new' => $this->edit(null),
                'edit' => $this->edit((string) ($_GET['path'] ?? '')),
                'save' => $this->save(),
                'preview' => $this->preview(),
                'history' => $this->history((string) ($_GET['path'] ?? '')),
                'restore' => $this->restore(),
                'generate' => $this->generate(),
                'publish' => $this->publish(),
                'themes' => $this->themes(),
                'theme_save' => $this->saveTheme(),
                'updates' => $this->updates(),
                'update_validate' => $this->validateUpdate(),
                'update_apply' => $this->applyUpdate(),
                'users' => $this->users(),
                'user_create' => $this->createUser(),
                'user_password' => $this->changeUserPassword(),
                default => $this->index(),
            };
        } catch (\Throwable $error) {
            Response::html('エラー', '<section class="panel"><h2>エラー</h2><p>' . Response::escape($error->getMessage()) . '</p></section>', $this->runtime, 500);
        }
    }

    private function redirectInitialAdminChange(string $action): void
    {
        if (!$this->runtime->serverSideClient->initialAdminChangeRequired()) {
            return;
        }
        if (in_array($action, ['initial_admin', 'logout'], true)) {
            return;
        }
        Response::redirect('?action=initial_admin');
    }

    private function initialAdmin(): void
    {
        if (!$this->runtime->serverSideClient->initialAdminChangeRequired()) {
            Response::redirect('?');
        }
        if ($this->requestMethod() === 'POST') {
            $this->runtime->serverSideClient->requireCsrf();
            $this->runtime->serverSideClient->ensureUnlocked();
            $username = (string) $_POST['username'];
            $this->runtime->serverSideClient->completeInitialAdminChange($username, (string) $_POST['password']);
            $this->audit('auth.initial_admin_complete', ['user' => $username]);
            Response::redirect('?');
        }
        $state = $this->runtime->serverSideClient->initialAdminState();
        $remaining = max(0, 5 - (int) $state['access_count']);
        $message = $state['deadline_reached']
            ? '<div class="alert">初期管理者変更期限に到達しています。変更完了まで他の操作はできません。</div>'
            : '<div class="notice">初期ユーザー名と初期パスワードを変更してください。残りアクセス回数: ' . $remaining . '</div>';
        $body = $message . '<section class="panel"><h2>初期管理者変更</h2><p class="muted">初期ユーザー名 admin と初期パスワード admin は継続利用できません。ユーザー名は一度設定すると変更できません。</p><form method="post"><input type="hidden" name="csrf" value="' . $this->runtime->serverSideClient->csrfToken() . '"><label>新しいユーザー名</label><input name="username" required><label>新しいパスワード</label><input name="password" type="password" required><p><button>変更</button></p></form></section>';
        Response::html('初期管理者変更', $body, $this->runtime);
    }

    private function login(): void
    {
        $message = '';
        if ($this->runtime->serverSideClient->loginLocked()) {
            $lockedUntil = gmdate(DATE_ATOM, $this->runtime->serverSideClient->loginLockedUntil());
            $message = '<div class="alert">ログインは一時ロックされています。解除予定: ' . Response::escape($lockedUntil) . '</div>';
        }
        if ($this->requestMethod() === 'POST' && !$this->runtime->serverSideClient->loginLocked()) {
            $this->runtime->serverSideClient->requireCsrf();
            $username = (string) $_POST['username'];
            if ($this->runtime->serverSideClient->login($username, (string) $_POST['password'])) {
                if ($this->runtime->serverSideClient->initialAdminChangeRequired()) {
                    $this->runtime->serverSideClient->recordInitialAdminAccess();
                }
                $this->audit('auth.login_success', ['user' => $username]);
                Response::redirect('?');
            }
            $this->audit('auth.login_failure', ['user' => $username]);
            $message = '<div class="alert">ログインに失敗しました。</div>';
        }
        $body = $message . '<section class="panel"><h2>ログイン</h2><form method="post"><input type="hidden" name="csrf" value="' . $this->runtime->serverSideClient->csrfToken() . '"><label>ユーザー名</label><input name="username" required><label>パスワード</label><input name="password" type="password" required><p><button>ログイン</button></p></form></section>';
        Response::html('ログイン', $body, $this->runtime);
    }

    private function index(): void
    {
        if ($this->runtime->serverSideClient->locked()) {
            $body = '<section class="page-head"><h2>状態確認</h2><p>CMSは現在ロック中です。許可された操作のみ実行できます。</p></section>'
                . $this->statusDashboard()
                . $this->operationPanel(true);
            Response::html('状態確認', $body, $this->runtime);
            return;
        }
        $notice = $this->updateNotice();
        $body = '<section class="page-head"><h2>ダッシュボード</h2><p>CMS状態、コンテンツ操作、静的生成、公開操作を確認できます。</p></section>'
            . $notice
            . $this->statusDashboard()
            . $this->operationPanel(false)
            . $this->contentListPanel();
        Response::html('ダッシュボード', $body, $this->runtime);
    }

    private function statusDashboard(): string
    {
        $lock = $this->runtime->serverSideClient->lockState();
        $locked = $lock['locked'] === true;
        $reason = (string) ($lock['reason'] ?? '');
        $maintenance = $locked && str_contains($reason, 'メンテナンス');
        $gitConfigured = $this->runtime->git->configured();
        $updateConfigured = $this->runtime->config->updateConfigured();
        $authState = $this->runtime->serverSideClient->user() === null ? '未認証' : '認証済み';
        $role = $this->runtime->serverSideClient->role() ?? '-';
        $initialAdminState = $this->runtime->serverSideClient->initialAdminState();

        $cards = [
            ['CMS状態', $locked ? 'ロック中' : '通常稼働', $locked ? 'danger' : 'ok'],
            ['現在バージョン', Config::VERSION, 'info'],
            ['メンテナンス状態', $maintenance ? 'メンテナンス中' : '通常', $maintenance ? 'warn' : 'ok'],
            ['Gitプロバイダー', $gitConfigured ? '設定済み' : '未設定', $gitConfigured ? 'ok' : 'danger'],
            ['アップデート設定', $updateConfigured ? '設定済み' : '未設定', $updateConfigured ? 'ok' : 'warn'],
            ['認証状態', $authState . ' / ' . $role, $this->runtime->serverSideClient->user() === null ? 'warn' : 'ok'],
            ['初期管理者', $initialAdminState['completed'] ? '変更済み' : '変更必須', $initialAdminState['completed'] ? 'ok' : 'warn'],
        ];

        $html = '<section class="dashboard-grid" aria-label="CMS状態">';
        foreach ($cards as [$label, $value, $tone]) {
            $html .= '<article class="status-card tone-' . Response::escape($tone) . '"><span>' . Response::escape($label) . '</span><strong>' . Response::escape($value) . '</strong></article>';
        }
        $html .= '</section>';
        if ($locked) {
            $html .= '<section class="panel"><h2>ロック情報</h2><table class="list"><tr><th>理由</th><td>' . Response::escape($reason) . '</td></tr><tr><th>日時</th><td>' . Response::escape((string) ($lock['created_at'] ?? '')) . '</td></tr></table></section>';
        }
        return $html;
    }

    private function operationPanel(bool $locked): string
    {
        $disabledNote = $locked ? '<p class="muted">CMSロック中は、状態確認、ログアウト、アップデート状態確認以外の操作は制限されます。</p>' : '';
        $admin = $this->runtime->serverSideClient->role() === 'admin';
        $initialDone = $this->runtime->serverSideClient->initialAdminCompleted();
        $links = [
            ['作成', '?action=new', '新しいコンテンツを作成します。', !$locked],
            ['静的生成', '?action=generate', 'コンテンツから公開成果物を生成します。', !$locked],
            ['公開', '?action=publish', '生成成果物を公開リポジトリへ保存します。', !$locked && $admin],
            ['テーマ', '?action=themes', '静的生成で使用するテーマを選択します。', !$locked && $admin],
            ['アップデート', '?action=updates', '開発元リリースを確認します。', $admin],
            ['ユーザー', '?action=users', '管理者と編集担当を管理します。', !$locked && $admin && $initialDone],
        ];
        $html = '<section class="panel"><div class="section-title"><h2>操作</h2><span class="badge">運用</span></div>' . $disabledNote . '<div class="action-grid">';
        foreach ($links as [$label, $href, $description, $enabled]) {
            if ($enabled) {
                $html .= '<a class="action-tile" href="' . Response::escape($href) . '"><strong>' . Response::escape($label) . '</strong><span>' . Response::escape($description) . '</span></a>';
            } else {
                $html .= '<div class="action-tile disabled"><strong>' . Response::escape($label) . '</strong><span>' . Response::escape($description) . '</span></div>';
            }
        }
        return $html . '</div></section>';
    }

    private function contentListPanel(): string
    {
        $rows = '';
        foreach ($this->content->list() as $item) {
            $path = (string) $item['path'];
            $rows .= '<tr><td><code>' . Response::escape($path) . '</code></td><td>' . (int) $item['size'] . '</td><td class="table-actions"><a href="?action=edit&path=' . rawurlencode($path) . '">編集</a><a href="?action=history&path=' . rawurlencode($path) . '">履歴</a></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="3" class="muted">コンテンツはありません。またはGitプロバイダーが未設定です。</td></tr>';
        }
        return '<section class="panel"><div class="section-title"><h2>コンテンツ</h2><a class="button secondary" href="?action=new">作成</a></div><table class="list"><tr><th>パス</th><th>サイズ</th><th>操作</th></tr>' . $rows . '</table></section>';
    }

    private function edit(?string $path): void
    {
        $value = '';
        if ($path !== null && $path !== '') {
            $extension = $this->runtime->serverSideClient->allowedExtension($path);
            if ($extension !== 'png') {
                $value = $this->content->read($path);
            }
        }
        $body = '<section class="panel"><h2>編集</h2><form method="post" enctype="multipart/form-data" action="?action=save"><input type="hidden" name="csrf" value="' . $this->runtime->serverSideClient->csrfToken() . '"><label>パス</label><input name="path" value="' . Response::escape($path ?? '') . '" placeholder="pages/index.md" required><label>内容</label><textarea name="body">' . Response::escape($value) . '</textarea><label>ファイル</label><input name="content_file" type="file" accept=".md,.json,.png,.svg"><p class="row"><button>保存</button><button class="button secondary" formaction="?action=preview" formmethod="post">プレビュー</button></p></form></section>';
        Response::html('編集', $body, $this->runtime);
    }

    private function save(): void
    {
        $this->runtime->serverSideClient->requireCsrf();
        $path = (string) $_POST['path'];
        $body = $this->submittedBytes();
        $this->content->save($path, $body);
        $this->audit('content.save', ['path' => $path, 'user' => $this->runtime->serverSideClient->user()]);
        Response::redirect('?action=edit&path=' . rawurlencode($path));
    }

    private function preview(): void
    {
        $this->runtime->serverSideClient->requireCsrf();
        $path = (string) $_POST['path'];
        $body = $this->submittedBytes();
        $preview = $this->renderer->preview($path, $body);
        Response::html('プレビュー', '<section class="panel"><h2>' . Response::escape($path) . '</h2><div class="preview">' . $preview . '</div></section>', $this->runtime);
    }

    private function submittedBytes(): string
    {
        $file = $_FILES['content_file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return (string) ($_POST['body'] ?? '');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $tmpName = (string) ($file['tmp_name'] ?? '');
            if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                throw new \RuntimeException('アップロードファイルを確認できません。');
            }
            $bytes = file_get_contents($tmpName);
            if ($bytes === false) {
                throw new \RuntimeException('アップロードファイルを読み取れません。');
            }
            return $bytes;
        }
        throw new \RuntimeException('アップロードに失敗しました。');
    }

    private function history(string $path): void
    {
        $rows = '';
        foreach ($this->content->history($path) as $item) {
            $sha = (string) $item['sha'];
            $rows .= '<tr><td>' . Response::escape(substr($sha, 0, 12)) . '</td><td>' . Response::escape((string) $item['date']) . '</td><td>' . Response::escape((string) $item['message']) . '</td><td><form method="post" action="?action=restore"><input type="hidden" name="csrf" value="' . $this->runtime->serverSideClient->csrfToken() . '"><input type="hidden" name="path" value="' . Response::escape($path) . '"><input type="hidden" name="ref" value="' . Response::escape($sha) . '"><button>復元</button></form></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4" class="muted">履歴はありません。</td></tr>';
        }
        Response::html('履歴', '<section class="panel"><h2>' . Response::escape($path) . '</h2><table class="list"><tr><th>SHA</th><th>日時</th><th>メッセージ</th><th></th></tr>' . $rows . '</table></section>', $this->runtime);
    }

    private function restore(): void
    {
        $this->runtime->serverSideClient->requireCsrf();
        $path = (string) $_POST['path'];
        $ref = (string) $_POST['ref'];
        $this->content->restore($path, $ref);
        $this->audit('content.restore', ['path' => $path, 'ref' => $ref, 'user' => $this->runtime->serverSideClient->user()]);
        Response::redirect('?action=edit&path=' . rawurlencode($path));
    }

    private function generate(): void
    {
        if ($this->requestMethod() !== 'POST') {
            Response::html('静的生成', '<section class="panel"><h2>静的生成</h2><form method="post" action="?action=generate"><input type="hidden" name="csrf" value="' . $this->runtime->serverSideClient->csrfToken() . '"><p>コンテンツから静的生成を実行します。</p><button>実行</button></form></section>', $this->runtime);
            return;
        }
        $this->runtime->serverSideClient->requireCsrf();
        $report = (new StaticGenerator($this->runtime, $this->renderer))->generateReport();
        $this->audit('static.generate', ['count' => $report['succeeded'], 'failed' => $report['failed'], 'user' => $this->runtime->serverSideClient->user()]);
        Response::html('静的生成', $this->generationReportHtml('静的生成', $report), $this->runtime);
    }

    private function publish(): void
    {
        if ($this->requestMethod() !== 'POST') {
            Response::html('公開', '<section class="panel"><h2>公開</h2><form method="post" action="?action=publish"><input type="hidden" name="csrf" value="' . $this->runtime->serverSideClient->csrfToken() . '"><p>静的生成物を公開リポジトリへ保存します。</p><button>公開</button></form></section>', $this->runtime);
            return;
        }
        $this->runtime->serverSideClient->requireCsrf();
        $report = (new StaticGenerator($this->runtime, $this->renderer))->publishReport();
        $this->audit('static.publish', ['count' => $report['succeeded'], 'failed' => $report['failed'], 'user' => $this->runtime->serverSideClient->user()]);
        Response::html('公開', $this->generationReportHtml('公開', $report), $this->runtime);
    }

    private function generationReportHtml(string $title, array $report): string
    {
        $rows = '';
        foreach (($report['items'] ?? []) as $item) {
            $status = (string) ($item['status'] ?? '');
            $statusLabel = $status === 'success' ? '成功' : '失敗';
            $reason = (string) ($item['reason'] ?? '');
            $checksum = (string) ($item['checksum'] ?? '');
            $rows .= '<tr><td>' . Response::escape((string) ($item['source_path'] ?? '')) . '</td><td>' . Response::escape((string) ($item['output_path'] ?? '')) . '</td><td>' . Response::escape((string) ($item['extension'] ?? '')) . '</td><td><span class="badge">' . Response::escape($statusLabel) . '</span></td><td><code>' . Response::escape($checksum === '' ? '-' : substr($checksum, 0, 16)) . '</code></td><td>' . Response::escape($reason === '' ? '-' : $reason) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6" class="muted">生成対象はありません。</td></tr>';
        }
        return '<section class="panel"><h2>' . Response::escape($title) . '</h2><div class="summary-grid"><div><span>有効テーマ</span><strong>' . Response::escape((string) ($report['theme'] ?? 'standard')) . '</strong></div><div><span>生成対象</span><strong>' . (int) ($report['total'] ?? 0) . '</strong></div><div><span>成功</span><strong>' . (int) ($report['succeeded'] ?? 0) . '</strong></div><div><span>失敗</span><strong>' . (int) ($report['failed'] ?? 0) . '</strong></div></div><table class="list"><tr><th>生成対象</th><th>出力</th><th>拡張子</th><th>状態</th><th>チェックサム</th><th>理由</th></tr>' . $rows . '</table></section>';
    }

    private function themes(): void
    {
        $active = $this->activeThemeName();
        $rows = '';
        foreach (StaticGenerator::themes() as $theme) {
            $name = (string) $theme['name'];
            $checked = $active === $name ? ' checked' : '';
            $rows .= '<label class="theme-option"><input type="radio" name="theme" value="' . Response::escape($name) . '"' . $checked . '><span><strong>' . Response::escape((string) $theme['label']) . '</strong><em>' . Response::escape((string) $theme['description']) . '</em></span><i style="background:' . Response::escape((string) $theme['primary']) . '"></i><i style="background:' . Response::escape((string) $theme['secondary']) . '"></i><i style="background:' . Response::escape((string) $theme['accent']) . '"></i></label>';
        }
        $body = '<section class="panel"><h2>テーマ管理</h2><p class="muted">静的生成時に使用する標準テーマを1個選択します。管理画面は公開テーマの影響を受けません。</p><form method="post" action="?action=theme_save"><input type="hidden" name="csrf" value="' . $this->runtime->serverSideClient->csrfToken() . '">' . $rows . '<p><button>保存</button></p></form></section>';
        Response::html('テーマ管理', $body, $this->runtime);
    }

    private function saveTheme(): void
    {
        $this->runtime->serverSideClient->requireCsrf();
        $theme = (string) ($_POST['theme'] ?? '');
        $this->writeActiveTheme($theme);
        $this->audit('theme.save', ['theme' => $theme, 'user' => $this->runtime->serverSideClient->user()]);
        Response::redirect('?action=themes');
    }

    private function activeThemeName(): string
    {
        $path = $this->themeSettingsPath();
        if (!is_file($path)) {
            return 'standard';
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            $this->runtime->serverSideClient->lock('テーマ設定を読み取れません。');
            throw new \RuntimeException('テーマ設定を読み取れません。');
        }
        $data = json_decode($bytes, true);
        $theme = is_array($data) ? (string) ($data['active_theme'] ?? '') : '';
        if (!StaticGenerator::validTheme($theme)) {
            $this->runtime->serverSideClient->lock('有効テーマが不正です。');
            throw new \RuntimeException('有効テーマが不正です。');
        }
        return $theme;
    }

    private function writeActiveTheme(string $theme): void
    {
        if (!StaticGenerator::validTheme($theme)) {
            throw new \InvalidArgumentException('テーマが不正です。');
        }
        $path = $this->themeSettingsPath();
        $payload = json_encode([
            'active_theme' => $theme,
            'updated_at' => gmdate(DATE_ATOM),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($payload === false || file_put_contents($path, $payload, LOCK_EX) === false) {
            $this->runtime->serverSideClient->lock('テーマ設定を保存できません。');
            throw new \RuntimeException('テーマ設定を保存できません。');
        }
        $readBack = file_get_contents($path);
        if ($readBack === false || !hash_equals(hash('sha256', $payload), hash('sha256', $readBack))) {
            $this->runtime->serverSideClient->lock('テーマ設定の保全確認に失敗しました。');
            throw new \RuntimeException('テーマ設定の保全確認に失敗しました。');
        }
        $data = json_decode($readBack, true);
        if (!is_array($data) || (string) ($data['active_theme'] ?? '') !== $theme) {
            $this->runtime->serverSideClient->lock('テーマ設定の整合性確認に失敗しました。');
            throw new \RuntimeException('テーマ設定の整合性確認に失敗しました。');
        }
    }

    private function themeSettingsPath(): string
    {
        return $this->runtime->configRoot . '/theme.json';
    }

    private function updates(): void
    {
        try {
            $rows = $this->updateRows($this->availableUpdateReleases(), true);
            $message = '';
        } catch (\Throwable $error) {
            $rows = '';
            $message = '<div class="alert">' . Response::escape($error->getMessage()) . '</div>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="7" class="muted">利用可能なアップデートはありません。</td></tr>';
        }
        $body = $message . '<section class="panel"><h2>アップデート</h2><p class="muted">任意リリースを選択し、事前検証後に適用します。適用時はメンテナンス状態に切り替わります。</p>' . $this->updateSummaryHtml() . '<table class="list"><tr><th>バージョン</th><th>対象</th><th>リリース日時</th><th>必須PHP</th><th>ファイル数</th><th>状態</th><th>操作</th></tr>' . $rows . '</table></section>';
        Response::html('アップデート', $body, $this->runtime);
    }

    private function validateUpdate(): void
    {
        if ($this->requestMethod() !== 'POST') {
            Response::redirect('?action=updates');
        }
        $this->runtime->serverSideClient->requireCsrf();
        $version = (string) ($_POST['version'] ?? '');
        $release = $this->findUpdateRelease($version);
        $report = $this->runtime->serverSideClient->validateUpdate($this->runtime, $release);
        $this->audit('update.validate', [
            'version' => $version,
            'valid' => $report['valid'],
            'failed' => $report['failed'],
            'user' => $this->runtime->serverSideClient->user(),
        ]);
        $body = '<section class="panel"><div class="section-title"><h2>アップデート事前検証</h2><a class="button secondary" href="?action=updates">一覧へ戻る</a></div>'
            . $this->updateValidationReportHtml($report)
            . '</section>';
        Response::html('アップデート事前検証', $body, $this->runtime);
    }

    private function applyUpdate(): void
    {
        if ($this->requestMethod() !== 'POST') {
            Response::redirect('?action=updates');
        }
        $this->runtime->serverSideClient->requireCsrf();
        $version = (string) ($_POST['version'] ?? '');
        $release = $this->findUpdateRelease($version);
        $report = $this->runtime->serverSideClient->applyUpdate($this->runtime, $release, self::MAINTENANCE_RELEASE_WAIT_REASON);
        $this->audit('update.apply', [
            'version' => $version,
            'valid' => $report['valid'],
            'failed' => $report['failed'],
            'user' => $this->runtime->serverSideClient->user(),
        ]);
        $body = '<section class="panel"><h2>アップデート適用</h2>'
            . $this->updateValidationReportHtml($report)
            . '<p class="muted">問題がない場合、5分後に公開モードへ復帰します。</p></section>';
        Response::html('アップデート適用', $body, $this->runtime);
    }

    private function users(): void
    {
        if (!$this->runtime->serverSideClient->initialAdminCompleted()) {
            throw new \RuntimeException('初期管理者変更が完了するまでユーザーを設定できません。');
        }
        $rows = '';
        foreach ($this->runtime->serverSideClient->users() as $user) {
            $username = (string) $user['username'];
            $rows .= '<tr><td>' . Response::escape($username) . '</td><td>' . Response::escape((string) $user['role']) . '</td><td>' . Response::escape((string) $user['created_at']) . '</td><td><form method="post" action="?action=user_password"><input type="hidden" name="csrf" value="' . $this->runtime->serverSideClient->csrfToken() . '"><input type="hidden" name="username" value="' . Response::escape($username) . '"><input name="password" type="password" required placeholder="新しいパスワード"><button>変更</button></form></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4" class="muted">ユーザーはありません。</td></tr>';
        }
        $body = '<section class="panel"><h2>ユーザー</h2><p class="muted">管理者1人、編集担当2人まで作成できます。ユーザー名は一度設定すると変更できません。</p><table class="list"><tr><th>ユーザー名</th><th>ロール</th><th>作成日時</th><th>パスワード変更</th></tr>' . $rows . '</table></section>'
            . '<section class="panel"><h2>ユーザー作成</h2><form method="post" action="?action=user_create"><input type="hidden" name="csrf" value="' . $this->runtime->serverSideClient->csrfToken() . '"><label>ユーザー名</label><input name="username" required><label>ロール</label><select name="role"><option value="editor">編集担当</option><option value="admin">管理者</option></select><label>パスワード</label><input name="password" type="password" required><p><button>作成</button></p></form></section>';
        Response::html('ユーザー', $body, $this->runtime);
    }

    private function createUser(): void
    {
        if ($this->requestMethod() !== 'POST') {
            Response::redirect('?action=users');
        }
        $this->runtime->serverSideClient->requireCsrf();
        $username = (string) ($_POST['username'] ?? '');
        $role = (string) ($_POST['role'] ?? '');
        $this->runtime->serverSideClient->createUser($username, (string) ($_POST['password'] ?? ''), $role);
        $this->audit('user.create', ['created_user' => $username, 'role' => $role, 'user' => $this->runtime->serverSideClient->user()]);
        Response::redirect('?action=users');
    }

    private function changeUserPassword(): void
    {
        if ($this->requestMethod() !== 'POST') {
            Response::redirect('?action=users');
        }
        $this->runtime->serverSideClient->requireCsrf();
        $username = (string) ($_POST['username'] ?? '');
        $this->runtime->serverSideClient->changePassword($username, (string) ($_POST['password'] ?? ''));
        $this->audit('user.password_change', ['target_user' => $username, 'user' => $this->runtime->serverSideClient->user()]);
        Response::redirect('?action=users');
    }

    private function audit(string $type, array $data = []): void
    {
        try {
            $this->runtime->git->saveOperationLog([
                'type' => $type,
                'data' => $data,
                'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
            ]);
        } catch (\Throwable $error) {
            $this->runtime->serverSideClient->lock('運用履歴の記録に失敗しました。');
            throw $error;
        }
    }

    private function requestMethod(): string
    {
        return $this->runtime->serverSideClient->requestMethod();
    }

    private function updateNotice(): string
    {
        if ($this->runtime->serverSideClient->role() !== 'admin') {
            return '';
        }
        try {
            $count = count($this->availableUpdateReleases());
        } catch (\Throwable) {
            return '';
        }
        if ($count === 0) {
            return '';
        }
        return '<div class="notice">利用可能なアップデートがあります。<a href="?action=updates">アップデート一覧</a></div>';
    }

    private function updateSummaryHtml(): string
    {
        return '<div class="summary-grid"><div><span>アップデート設定</span><strong>' . Response::escape($this->runtime->config->updateConfigured() ? '設定済み' : '未設定') . '</strong></div><div><span>リポジトリ</span><strong>' . Response::escape($this->runtime->config->updateRepository === '' ? '-' : $this->runtime->config->updateRepository) . '</strong></div><div><span>ブランチ</span><strong>' . Response::escape($this->runtime->config->updateBranch) . '</strong></div><div><span>マニフェスト</span><strong>' . Response::escape($this->runtime->config->updateManifestPath) . '</strong></div></div>';
    }

    private function updateRows(array $releases, bool $withForm): string
    {
        $rows = '';
        foreach ($releases as $release) {
            $version = (string) ($release['version'] ?? '');
            $releasedAt = (string) ($release['released_at'] ?? '');
            $php = (string) ($release['php'] ?? '');
            $targetVersion = (string) ($release['target_version'] ?? '');
            $fileCount = is_array($release['files'] ?? null) ? count($release['files']) : 0;
            $operation = '<span class="muted">-</span>';
            if ($withForm) {
                $operation = '<form method="post" action="?action=update_validate"><input type="hidden" name="csrf" value="' . $this->runtime->serverSideClient->csrfToken() . '"><input type="hidden" name="version" value="' . Response::escape($version) . '"><button>事前検証</button></form>';
            }
            $rows .= '<tr><td>' . Response::escape($version) . '</td><td>' . Response::escape($targetVersion === '' ? '-' : $targetVersion) . '</td><td>' . Response::escape($releasedAt) . '</td><td>' . Response::escape($php === '' ? '-' : $php) . '</td><td>' . $fileCount . '</td><td><span class="badge">検証可能</span></td><td>' . $operation . '</td></tr>';
        }
        return $rows;
    }

    private function findUpdateRelease(string $version): array
    {
        if ($version === '') {
            throw new \InvalidArgumentException('アップデートリリースが選択されていません。');
        }
        foreach ($this->availableUpdateReleases() as $release) {
            if ((string) ($release['version'] ?? '') === $version) {
                return $release;
            }
        }
        throw new \RuntimeException('選択されたアップデートリリースを確認できません。');
    }

    private function updateValidationReportHtml(array $report): string
    {
        $summary = '<div class="summary-grid"><div><span>検証対象</span><strong>' . Response::escape((string) ($report['version'] ?? '-')) . '</strong></div><div><span>対象バージョン</span><strong>' . Response::escape((string) ($report['target_version'] ?? '-')) . '</strong></div><div><span>検証成功</span><strong>' . (int) ($report['passed'] ?? 0) . '</strong></div><div><span>検証失敗</span><strong>' . (int) ($report['failed'] ?? 0) . '</strong></div></div>';
        $status = ($report['valid'] ?? false) === true
            ? '<div class="notice">検証は成功しました。</div>'
            : '<div class="alert">事前検証に失敗しました。適用は行いません。</div>';
        $rows = '';
        foreach (($report['checks'] ?? []) as $check) {
            $ok = ($check['ok'] ?? false) === true;
            $rows .= '<tr><td>' . Response::escape((string) ($check['name'] ?? '')) . '</td><td><span class="badge">' . Response::escape($ok ? 'OK' : 'NG') . '</span></td><td>' . Response::escape((string) ($check['message'] ?? '')) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="3" class="muted">検証項目はありません。</td></tr>';
        }
        $apply = '';
        if (($report['valid'] ?? false) === true && isset($report['version']) && $this->runtime->serverSideClient->role() === 'admin') {
            $apply = '<form method="post" action="?action=update_apply"><input type="hidden" name="csrf" value="' . $this->runtime->serverSideClient->csrfToken() . '"><input type="hidden" name="version" value="' . Response::escape((string) $report['version']) . '"><button>アップデート適用</button></form>';
        }
        return $status . $summary . '<table class="list"><tr><th>項目</th><th>状態</th><th>内容</th></tr>' . $rows . '</table>' . $apply;
    }

    private function availableUpdateReleases(): array
    {
        $items = [];
        foreach ($this->runtime->git->listUpdateReleases() as $release) {
            if (!is_array($release)) {
                continue;
            }
            if (!$this->validUpdateReleaseSummary($release)) {
                continue;
            }
            $version = (string) ($release['version'] ?? '');
            if ($version !== '' && $this->newerThanCurrent($version)) {
                $items[] = $release;
            }
        }
        usort($items, fn (array $a, array $b): int => version_compare($this->versionNumber((string) ($b['version'] ?? '')), $this->versionNumber((string) ($a['version'] ?? ''))));
        return $items;
    }

    private function validUpdateReleaseSummary(array $release): bool
    {
        $version = (string) ($release['version'] ?? '');
        $targetVersion = (string) ($release['target_version'] ?? '');
        $releasedAt = (string) ($release['released_at'] ?? '');
        $php = (string) ($release['php'] ?? '');
        $files = $release['files'] ?? null;
        if ($version === '' || $targetVersion === '' || $releasedAt === '' || $php === '' || !is_array($files)) {
            return false;
        }
        foreach ($files as $file) {
            if (!is_array($file)) {
                return false;
            }
            if ((string) ($file['path'] ?? '') === '' || (string) ($file['source'] ?? '') === '' || (string) ($file['checksum'] ?? '') === '') {
                return false;
            }
        }
        return true;
    }

    private function newerThanCurrent(string $version): bool
    {
        return version_compare($this->versionNumber($version), $this->versionNumber(Config::VERSION), '>');
    }

    private function versionNumber(string $version): string
    {
        return ltrim($version, 'v.');
    }

}

if (!defined('REPOSITORY_CMS_NO_RUN')) {
    Bootstrap::run(__DIR__);
}
