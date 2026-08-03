<?php

declare(strict_types=1);

namespace RepositoryCms\Core;

final class App
{
    private ContentManager $content;
    private Renderer $renderer;

    public function __construct(private readonly Runtime $runtime)
    {
        $this->content = new ContentManager($runtime);
        $this->renderer = new Renderer();
    }

    public function handle(): void
    {
        try {
            $action = (string) ($_GET['action'] ?? 'index');
            if (!$this->runtime->auth->configured()) {
                $this->setup();
                return;
            }
            if ($action === 'login') {
                $this->login();
                return;
            }
            if ($action === 'logout') {
                $user = $this->runtime->auth->user();
                $this->audit('auth.logout', ['user' => $user]);
                $this->runtime->auth->logout();
                Response::redirect('?action=login');
            }

            $this->runtime->auth->requireLogin();
            match ($action) {
                'new' => $this->edit(null),
                'edit' => $this->edit((string) ($_GET['path'] ?? '')),
                'save' => $this->save(),
                'preview' => $this->preview(),
                'history' => $this->history((string) ($_GET['path'] ?? '')),
                'restore' => $this->restore(),
                'generate' => $this->generate(),
                'publish' => $this->publish(),
                default => $this->index(),
            };
        } catch (\Throwable $error) {
            Response::html('エラー', '<section class="panel"><h2>エラー</h2><p>' . Response::escape($error->getMessage()) . '</p></section>', $this->runtime, 500);
        }
    }

    private function setup(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Security::requireCsrf();
            if ($this->runtime->locks->locked()) {
                throw new \RuntimeException('CMSがロックされています。');
            }
            $username = (string) $_POST['username'];
            $this->runtime->auth->setup((string) $_POST['username'], (string) $_POST['password']);
            $this->audit('auth.setup', ['user' => $username]);
            Response::redirect('?');
        }
        $body = '<section class="panel"><h2>管理者設定</h2><form method="post"><input type="hidden" name="csrf" value="' . Security::csrfToken() . '"><label>ユーザー名</label><input name="username" required><label>パスワード</label><input name="password" type="password" required><p><button>作成</button></p></form></section>';
        Response::html('管理者設定', $body, $this->runtime);
    }

    private function login(): void
    {
        $message = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Security::requireCsrf();
            $username = (string) $_POST['username'];
            if ($this->runtime->auth->login($username, (string) $_POST['password'])) {
                $this->audit('auth.login_success', ['user' => $username]);
                Response::redirect('?');
            }
            $this->audit('auth.login_failure', ['user' => $username]);
            $message = '<div class="alert">ログインに失敗しました。</div>';
        }
        $body = $message . '<section class="panel"><h2>ログイン</h2><form method="post"><input type="hidden" name="csrf" value="' . Security::csrfToken() . '"><label>ユーザー名</label><input name="username" required><label>パスワード</label><input name="password" type="password" required><p><button>ログイン</button></p></form></section>';
        Response::html('ログイン', $body, $this->runtime);
    }

    private function index(): void
    {
        $rows = '';
        foreach ($this->content->list() as $item) {
            $path = (string) $item['path'];
            $rows .= '<tr><td>' . Response::escape($path) . '</td><td>' . (int) $item['size'] . '</td><td><a href="?action=edit&path=' . rawurlencode($path) . '">編集</a> / <a href="?action=history&path=' . rawurlencode($path) . '">履歴</a></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="3" class="muted">コンテンツはありません。またはGitプロバイダーが未設定です。</td></tr>';
        }
        $body = '<section class="panel"><h2>コンテンツ</h2><table class="list"><tr><th>パス</th><th>サイズ</th><th></th></tr>' . $rows . '</table></section>';
        Response::html('コンテンツ一覧', $body, $this->runtime);
    }

    private function edit(?string $path): void
    {
        $value = '';
        if ($path !== null && $path !== '') {
            $extension = Security::allowedExtension($path);
            if ($extension !== 'png') {
                $value = $this->content->read($path);
            }
        }
        $body = '<section class="panel"><h2>編集</h2><form method="post" enctype="multipart/form-data" action="?action=save"><input type="hidden" name="csrf" value="' . Security::csrfToken() . '"><label>パス</label><input name="path" value="' . Response::escape($path ?? '') . '" placeholder="pages/index.md" required><label>内容</label><textarea name="body">' . Response::escape($value) . '</textarea><label>ファイル</label><input name="content_file" type="file" accept=".md,.json,.png,.svg"><p class="row"><button>保存</button><button class="button secondary" formaction="?action=preview" formmethod="post">プレビュー</button></p></form></section>';
        Response::html('編集', $body, $this->runtime);
    }

    private function save(): void
    {
        Security::requireCsrf();
        $path = (string) $_POST['path'];
        $body = $this->submittedBytes();
        $this->content->save($path, $body);
        $this->audit('content.save', ['path' => $path, 'user' => $this->runtime->auth->user()]);
        Response::redirect('?action=edit&path=' . rawurlencode($path));
    }

    private function preview(): void
    {
        Security::requireCsrf();
        $path = (string) $_POST['path'];
        $body = $this->submittedBytes();
        $preview = $this->renderer->preview($path, $body);
        Response::html('プレビュー', '<section class="panel"><h2>' . Response::escape($path) . '</h2><div class="preview">' . $preview . '</div></section>', $this->runtime);
    }

    private function submittedBytes(): string
    {
        $file = $_FILES['content_file'] ?? null;
        if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
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
        return (string) $_POST['body'];
    }

    private function history(string $path): void
    {
        $rows = '';
        foreach ($this->content->history($path) as $item) {
            $sha = (string) $item['sha'];
            $rows .= '<tr><td>' . Response::escape(substr($sha, 0, 12)) . '</td><td>' . Response::escape((string) $item['date']) . '</td><td>' . Response::escape((string) $item['message']) . '</td><td><form method="post" action="?action=restore"><input type="hidden" name="csrf" value="' . Security::csrfToken() . '"><input type="hidden" name="path" value="' . Response::escape($path) . '"><input type="hidden" name="ref" value="' . Response::escape($sha) . '"><button>復元</button></form></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4" class="muted">履歴はありません。</td></tr>';
        }
        Response::html('履歴', '<section class="panel"><h2>' . Response::escape($path) . '</h2><table class="list"><tr><th>SHA</th><th>日時</th><th>メッセージ</th><th></th></tr>' . $rows . '</table></section>', $this->runtime);
    }

    private function restore(): void
    {
        Security::requireCsrf();
        $path = (string) $_POST['path'];
        $ref = (string) $_POST['ref'];
        $this->content->restore($path, $ref);
        $this->audit('content.restore', ['path' => $path, 'ref' => $ref, 'user' => $this->runtime->auth->user()]);
        Response::redirect('?action=edit&path=' . rawurlencode($path));
    }

    private function generate(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::html('静的生成', '<section class="panel"><h2>静的生成</h2><form method="post" action="?action=generate"><input type="hidden" name="csrf" value="' . Security::csrfToken() . '"><p>コンテンツから静的生成を実行します。</p><button>実行</button></form></section>', $this->runtime);
            return;
        }
        Security::requireCsrf();
        $count = (new StaticGenerator($this->runtime, $this->renderer))->generate();
        $this->audit('static.generate', ['count' => $count, 'user' => $this->runtime->auth->user()]);
        Response::html('静的生成', '<section class="panel"><h2>静的生成</h2><p>' . $count . ' 件を生成しました。</p></section>', $this->runtime);
    }

    private function publish(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::html('公開', '<section class="panel"><h2>公開</h2><form method="post" action="?action=publish"><input type="hidden" name="csrf" value="' . Security::csrfToken() . '"><p>静的生成物を公開リポジトリへ保存します。</p><button>公開</button></form></section>', $this->runtime);
            return;
        }
        Security::requireCsrf();
        $count = (new StaticGenerator($this->runtime, $this->renderer))->publish();
        $this->audit('static.publish', ['count' => $count, 'user' => $this->runtime->auth->user()]);
        Response::html('公開', '<section class="panel"><h2>公開</h2><p>' . $count . ' 件を公開しました。</p></section>', $this->runtime);
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
            $this->runtime->locks->lock('運用履歴の記録に失敗しました。');
            throw $error;
        }
    }
}
