<?php

declare(strict_types=1);

namespace RepositoryCms\Core;

final class App
{
    private const MAINTENANCE_RELEASE_WAIT_REASON = 'メンテナンス解除待機中です。';

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
            $this->releaseMaintenanceIfReady();
            $action = (string) ($_GET['action'] ?? 'index');
            if (!$this->runtime->auth->configured()) {
                $this->authorize($action);
                $this->setup();
                return;
            }
            if ($action === 'login') {
                $this->authorize($action);
                $this->login();
                return;
            }
            if ($action === 'logout') {
                $this->runtime->auth->requireLogin();
                $this->authorize($action);
                $user = $this->runtime->auth->user();
                $this->audit('auth.logout', ['user' => $user]);
                $this->runtime->auth->logout();
                Response::redirect('?action=login');
            }

            $this->runtime->auth->requireLogin();
            $this->authorize($action);
            match ($action) {
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
                default => $this->index(),
            };
        } catch (\Throwable $error) {
            Response::html('エラー', '<section class="panel"><h2>エラー</h2><p>' . Response::escape($error->getMessage()) . '</p></section>', $this->runtime, 500);
        }
    }

    private function setup(): void
    {
        if ($this->requestMethod() === 'POST') {
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
        if ($this->runtime->auth->loginLocked()) {
            $lockedUntil = gmdate(DATE_ATOM, $this->runtime->auth->loginLockedUntil());
            $message = '<div class="alert">ログインは一時ロックされています。解除予定: ' . Response::escape($lockedUntil) . '</div>';
        }
        if ($this->requestMethod() === 'POST' && !$this->runtime->auth->loginLocked()) {
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
        if ($this->runtime->locks->locked()) {
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
        $lock = $this->runtime->locks->state();
        $locked = $lock['locked'] === true;
        $reason = (string) ($lock['reason'] ?? '');
        $maintenance = $locked && str_contains($reason, 'メンテナンス');
        $gitConfigured = $this->runtime->git->configured();
        $updateConfigured = $this->runtime->config->updateConfigured();
        $authState = $this->runtime->auth->user() === null ? '未認証' : '認証済み';
        $role = $this->runtime->auth->role() ?? '-';

        $cards = [
            ['CMS状態', $locked ? 'ロック中' : '通常稼働', $locked ? 'danger' : 'ok'],
            ['現在バージョン', Config::VERSION, 'info'],
            ['メンテナンス状態', $maintenance ? 'メンテナンス中' : '通常', $maintenance ? 'warn' : 'ok'],
            ['Gitプロバイダー', $gitConfigured ? '設定済み' : '未設定', $gitConfigured ? 'ok' : 'danger'],
            ['アップデート設定', $updateConfigured ? '設定済み' : '未設定', $updateConfigured ? 'ok' : 'warn'],
            ['認証状態', $authState . ' / ' . $role, $this->runtime->auth->user() === null ? 'warn' : 'ok'],
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
        $admin = $this->runtime->auth->role() === 'admin';
        $links = [
            ['作成', '?action=new', '新しいコンテンツを作成します。', !$locked],
            ['静的生成', '?action=generate', 'コンテンツから公開成果物を生成します。', !$locked],
            ['公開', '?action=publish', '生成成果物を公開リポジトリへ保存します。', !$locked && $admin],
            ['テーマ', '?action=themes', '静的生成で使用するテーマを選択します。', !$locked && $admin],
            ['アップデート', '?action=updates', '開発元リリースを確認します。', $admin],
            ['ユーザー', '?action=users', '管理者と編集担当を管理します。', !$locked && $admin],
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
        if ($this->requestMethod() !== 'POST') {
            Response::html('静的生成', '<section class="panel"><h2>静的生成</h2><form method="post" action="?action=generate"><input type="hidden" name="csrf" value="' . Security::csrfToken() . '"><p>コンテンツから静的生成を実行します。</p><button>実行</button></form></section>', $this->runtime);
            return;
        }
        Security::requireCsrf();
        $report = (new StaticGenerator($this->runtime, $this->renderer))->generateReport();
        $this->audit('static.generate', ['count' => $report['succeeded'], 'failed' => $report['failed'], 'user' => $this->runtime->auth->user()]);
        Response::html('静的生成', $this->generationReportHtml('静的生成', $report), $this->runtime);
    }

    private function publish(): void
    {
        if ($this->requestMethod() !== 'POST') {
            Response::html('公開', '<section class="panel"><h2>公開</h2><form method="post" action="?action=publish"><input type="hidden" name="csrf" value="' . Security::csrfToken() . '"><p>静的生成物を公開リポジトリへ保存します。</p><button>公開</button></form></section>', $this->runtime);
            return;
        }
        Security::requireCsrf();
        $report = (new StaticGenerator($this->runtime, $this->renderer))->publishReport();
        $this->audit('static.publish', ['count' => $report['succeeded'], 'failed' => $report['failed'], 'user' => $this->runtime->auth->user()]);
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
        $body = '<section class="panel"><h2>テーマ管理</h2><p class="muted">静的生成時に使用する標準テーマを1個選択します。管理画面は公開テーマの影響を受けません。</p><form method="post" action="?action=theme_save"><input type="hidden" name="csrf" value="' . Security::csrfToken() . '">' . $rows . '<p><button>保存</button></p></form></section>';
        Response::html('テーマ管理', $body, $this->runtime);
    }

    private function saveTheme(): void
    {
        Security::requireCsrf();
        $theme = (string) ($_POST['theme'] ?? '');
        $this->writeActiveTheme($theme);
        $this->audit('theme.save', ['theme' => $theme, 'user' => $this->runtime->auth->user()]);
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
            $this->runtime->locks->lock('テーマ設定を読み取れません。');
            throw new \RuntimeException('テーマ設定を読み取れません。');
        }
        $data = json_decode($bytes, true);
        $theme = is_array($data) ? (string) ($data['active_theme'] ?? '') : '';
        if (!StaticGenerator::validTheme($theme)) {
            $this->runtime->locks->lock('有効テーマが不正です。');
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
            $this->runtime->locks->lock('テーマ設定を保存できません。');
            throw new \RuntimeException('テーマ設定を保存できません。');
        }
        $readBack = file_get_contents($path);
        if ($readBack === false || !hash_equals(hash('sha256', $payload), hash('sha256', $readBack))) {
            $this->runtime->locks->lock('テーマ設定の保全確認に失敗しました。');
            throw new \RuntimeException('テーマ設定の保全確認に失敗しました。');
        }
        $data = json_decode($readBack, true);
        if (!is_array($data) || (string) ($data['active_theme'] ?? '') !== $theme) {
            $this->runtime->locks->lock('テーマ設定の整合性確認に失敗しました。');
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
        Security::requireCsrf();
        $version = (string) ($_POST['version'] ?? '');
        $release = $this->findUpdateRelease($version);
        $report = (new UpdateValidator($this->runtime))->validate($release);
        $this->audit('update.validate', [
            'version' => $version,
            'valid' => $report['valid'],
            'failed' => $report['failed'],
            'user' => $this->runtime->auth->user(),
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
        Security::requireCsrf();
        $version = (string) ($_POST['version'] ?? '');
        $release = $this->findUpdateRelease($version);
        $report = (new UpdateApplier($this->runtime, self::MAINTENANCE_RELEASE_WAIT_REASON))->apply($release);
        $this->audit('update.apply', [
            'version' => $version,
            'valid' => $report['valid'],
            'failed' => $report['failed'],
            'user' => $this->runtime->auth->user(),
        ]);
        $body = '<section class="panel"><h2>アップデート適用</h2>'
            . $this->updateValidationReportHtml($report)
            . '<p class="muted">問題がない場合、5分後に公開モードへ復帰します。</p></section>';
        Response::html('アップデート適用', $body, $this->runtime);
    }

    private function users(): void
    {
        $rows = '';
        foreach ($this->runtime->auth->users() as $user) {
            $rows .= '<tr><td>' . Response::escape((string) $user['username']) . '</td><td>' . Response::escape((string) $user['role']) . '</td><td>' . Response::escape((string) $user['created_at']) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="3" class="muted">ユーザーはありません。</td></tr>';
        }
        $body = '<section class="panel"><h2>ユーザー</h2><p class="muted">管理者1人、編集担当2人まで作成できます。</p><table class="list"><tr><th>ユーザー名</th><th>ロール</th><th>作成日時</th></tr>' . $rows . '</table></section>'
            . '<section class="panel"><h2>ユーザー作成</h2><form method="post" action="?action=user_create"><input type="hidden" name="csrf" value="' . Security::csrfToken() . '"><label>ユーザー名</label><input name="username" required><label>ロール</label><select name="role"><option value="editor">編集担当</option><option value="admin">管理者</option></select><label>パスワード</label><input name="password" type="password" required><p><button>作成</button></p></form></section>';
        Response::html('ユーザー', $body, $this->runtime);
    }

    private function createUser(): void
    {
        if ($this->requestMethod() !== 'POST') {
            Response::redirect('?action=users');
        }
        Security::requireCsrf();
        $username = (string) ($_POST['username'] ?? '');
        $role = (string) ($_POST['role'] ?? '');
        $this->runtime->auth->createUser($username, (string) ($_POST['password'] ?? ''), $role);
        $this->audit('user.create', ['created_user' => $username, 'role' => $role, 'user' => $this->runtime->auth->user()]);
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
            $this->runtime->locks->lock('運用履歴の記録に失敗しました。');
            throw $error;
        }
    }

    private function requestMethod(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    private function authorize(string $action): void
    {
        if (!$this->knownOperation($action)) {
            return;
        }
        if (!$this->runtime->auth->configured()) {
            if ($action === 'index' || $action === 'login') {
                return;
            }
            throw new \RuntimeException('認可されていない操作です。');
        }
        if ($action !== 'login' && $this->runtime->auth->user() === null) {
            if ($action === 'index') {
                return;
            }
            throw new \RuntimeException('認証が必要です。');
        }
        if (!$this->roleAllowed($action)) {
            throw new \RuntimeException('この操作を行う権限がありません。');
        }
        if (!$this->runtime->locks->locked()) {
            return;
        }
        if (in_array($action, ['index', 'updates', 'logout'], true)) {
            return;
        }
        throw new \RuntimeException('CMSがロックされているため、この操作は実行できません。');
    }

    private function knownOperation(string $action): bool
    {
        return in_array($action, [
            'index',
            'login',
            'logout',
            'new',
            'edit',
            'save',
            'history',
            'restore',
            'preview',
            'generate',
            'publish',
            'themes',
            'theme_save',
            'updates',
            'update_validate',
            'update_apply',
            'users',
            'user_create',
        ], true);
    }

    private function roleAllowed(string $action): bool
    {
        $role = $this->runtime->auth->role();
        if ($role === 'admin') {
            return true;
        }
        if ($role === 'editor') {
            return in_array($action, ['index', 'logout', 'new', 'edit', 'save', 'history', 'restore', 'preview', 'generate'], true);
        }
        return in_array($action, ['index', 'login'], true);
    }

    private function updateNotice(): string
    {
        if ($this->runtime->auth->role() !== 'admin') {
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
                $operation = '<form method="post" action="?action=update_validate"><input type="hidden" name="csrf" value="' . Security::csrfToken() . '"><input type="hidden" name="version" value="' . Response::escape($version) . '"><button>事前検証</button></form>';
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
        if (($report['valid'] ?? false) === true && isset($report['version']) && $this->runtime->auth->role() === 'admin') {
            $apply = '<form method="post" action="?action=update_apply"><input type="hidden" name="csrf" value="' . Security::csrfToken() . '"><input type="hidden" name="version" value="' . Response::escape((string) $report['version']) . '"><button>アップデート適用</button></form>';
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

    private function releaseMaintenanceIfReady(): void
    {
        $state = $this->runtime->locks->state();
        if ($state['locked'] !== true || $state['reason'] !== self::MAINTENANCE_RELEASE_WAIT_REASON) {
            return;
        }
        $created = strtotime((string) ($state['created_at'] ?? ''));
        if ($created !== false && time() - $created >= 300) {
            $this->runtime->locks->clear();
        }
    }
}
