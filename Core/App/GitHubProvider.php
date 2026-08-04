<?php

declare(strict_types=1);

namespace RepositoryCms\Core;

use ServerSideLogicFramework\LockManager;
use ServerSideLogicFramework\Security;

final class GitHubProvider implements GitProvider
{
    private const API = 'https://api.github.com';

    public function __construct(private readonly Config $config)
    {
    }

    public static function fromConfig(Config $config, LockManager $locks): GitProvider
    {
        if (!$config->gitConfigured()) {
            $locks->lock('Gitプロバイダーが未設定です。');
            return new NullGitProvider();
        }
        $locks->clearIfReason('Gitプロバイダーが未設定です。');
        return new self($config);
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
            if (($node['type'] ?? '') === 'blob' && Security::validContentPath($path)) {
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
        if (!Security::validRepositoryPath($path)) {
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
        if (!Security::validContentPath($path)) {
            throw new \InvalidArgumentException('コンテンツパスが不正です。');
        }
    }

    private function assertPublicPath(string $path): void
    {
        if (!Security::validPublicPath($path)) {
            throw new \InvalidArgumentException('公開パスが不正です。');
        }
    }
}
