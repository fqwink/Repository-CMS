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
                'updates' => $this->updates(),
                'update_apply' => $this->applyUpdate(),
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

        $cards = [
            ['CMS状態', $locked ? 'ロック中' : '通常稼働', $locked ? 'danger' : 'ok'],
            ['現在バージョン', Config::VERSION, 'info'],
            ['メンテナンス状態', $maintenance ? 'メンテナンス中' : '通常', $maintenance ? 'warn' : 'ok'],
            ['Gitプロバイダー', $gitConfigured ? '設定済み' : '未設定', $gitConfigured ? 'ok' : 'danger'],
            ['アップデート設定', $updateConfigured ? '設定済み' : '未設定', $updateConfigured ? 'ok' : 'warn'],
            ['認証状態', $authState, $this->runtime->auth->user() === null ? 'warn' : 'ok'],
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
        $links = [
            ['作成', '?action=new', '新しいコンテンツを作成します。', !$locked],
            ['静的生成', '?action=generate', 'コンテンツから公開成果物を生成します。', !$locked],
            ['公開', '?action=publish', '生成成果物を公開リポジトリへ保存します。', !$locked],
            ['アップデート', '?action=updates', '開発元リリースを確認します。', true],
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
        return '<section class="panel"><h2>' . Response::escape($title) . '</h2><div class="summary-grid"><div><span>生成対象</span><strong>' . (int) ($report['total'] ?? 0) . '</strong></div><div><span>成功</span><strong>' . (int) ($report['succeeded'] ?? 0) . '</strong></div><div><span>失敗</span><strong>' . (int) ($report['failed'] ?? 0) . '</strong></div></div><table class="list"><tr><th>生成対象</th><th>出力</th><th>拡張子</th><th>状態</th><th>チェックサム</th><th>理由</th></tr>' . $rows . '</table></section>';
    }

    private function updates(): void
    {
        $rows = '';
        $message = '';
        try {
            foreach ($this->availableUpdateReleases() as $release) {
                $version = (string) ($release['version'] ?? '');
                $releasedAt = (string) ($release['released_at'] ?? '');
                $php = (string) ($release['php'] ?? '');
                $rows .= '<tr><td>' . Response::escape($version) . '</td><td>' . Response::escape($releasedAt) . '</td><td>' . Response::escape($php) . '</td><td><form method="post" action="?action=update_apply"><input type="hidden" name="csrf" value="' . Security::csrfToken() . '"><input type="hidden" name="version" value="' . Response::escape($version) . '"><button>選択して開始</button></form></td></tr>';
            }
        } catch (\Throwable $error) {
            $message = '<div class="alert">' . Response::escape($error->getMessage()) . '</div>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4" class="muted">選択可能なアップデートはありません。</td></tr>';
        }
        $body = $message . '<section class="panel"><h2>アップデート</h2><table class="list"><tr><th>バージョン</th><th>リリース日時</th><th>必須PHP</th><th></th></tr>' . $rows . '</table></section>';
        Response::html('アップデート', $body, $this->runtime);
    }

    private function applyUpdate(): void
    {
        Security::requireCsrf();
        if ($this->runtime->locks->locked()) {
            throw new \RuntimeException('CMSがロックされているため、アップデートを開始できません。');
        }
        $version = (string) ($_POST['version'] ?? '');
        $release = $this->findUpdateRelease($version);
        $this->runtime->locks->lock('メンテナンスモード: アップデート中です。');
        try {
            $this->validateUpdateRelease($release);
            foreach ($release['files'] as $file) {
                $targetPath = (string) ($file['path'] ?? '');
                $sourcePath = (string) ($file['source'] ?? $targetPath);
                $checksum = (string) ($file['checksum'] ?? '');
                $bytes = $this->runtime->git->readUpdateFile($sourcePath);
                if ($checksum === '' || !hash_equals($checksum, $this->runtime->workData->checksum($bytes))) {
                    throw new \RuntimeException('アップデートファイルのチェックサムが一致しません: ' . $targetPath);
                }
                $workPath = $this->runtime->workData->write(basename($targetPath), $bytes);
                if (!$this->runtime->workData->verified($workPath, $checksum)) {
                    throw new \RuntimeException('アップデート作業データの保全確認に失敗しました: ' . $targetPath);
                }
                $this->writeUpdateTarget($targetPath, $bytes);
            }
            $this->runtime->workData->cleanupAfterVerified();
            $this->runtime->locks->lock(self::MAINTENANCE_RELEASE_WAIT_REASON);
            Response::html('アップデート', '<section class="panel"><h2>アップデート完了</h2><p>5分後に公開モードへ切り替えます。</p></section>', $this->runtime);
        } catch (\Throwable $error) {
            $this->runtime->locks->lock('アップデート失敗: ' . $error->getMessage());
            throw $error;
        }
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
            'updates',
            'update_apply',
        ], true);
    }

    private function updateNotice(): string
    {
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

    private function availableUpdateReleases(): array
    {
        $items = [];
        foreach ($this->runtime->git->listUpdateReleases() as $release) {
            if (!is_array($release)) {
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

    private function findUpdateRelease(string $version): array
    {
        foreach ($this->availableUpdateReleases() as $release) {
            if ((string) ($release['version'] ?? '') === $version) {
                return $release;
            }
        }
        throw new \RuntimeException('選択されたアップデートは利用できません。');
    }

    private function validateUpdateRelease(array $release): void
    {
        $version = (string) ($release['version'] ?? '');
        $targetVersion = (string) ($release['target_version'] ?? '');
        $php = (string) ($release['php'] ?? '');
        $files = $release['files'] ?? null;
        if ($version === '' || !$this->newerThanCurrent($version)) {
            throw new \RuntimeException('アップデートバージョンが不正です。');
        }
        if ($targetVersion !== Config::VERSION) {
            throw new \RuntimeException('対象バージョンが現在バージョンと一致しません。');
        }
        if ($php === '' || version_compare(PHP_VERSION, $php, '<')) {
            throw new \RuntimeException('必須PHPバージョンを満たしていません。');
        }
        if (!is_array($files) || $files === []) {
            throw new \RuntimeException('アップデート対象ファイル一覧が不正です。');
        }
        foreach ($files as $file) {
            if (!is_array($file)) {
                throw new \RuntimeException('アップデート対象ファイル一覧が不正です。');
            }
            $path = (string) ($file['path'] ?? '');
            $source = (string) ($file['source'] ?? $path);
            if (!$this->validUpdateTarget($path) || !Security::validRepositoryPath($source)) {
                throw new \RuntimeException('アップデート禁止パスが含まれています。');
            }
            if ((string) ($file['checksum'] ?? '') === '') {
                throw new \RuntimeException('Coreファイルチェックサムが不足しています。');
            }
        }
        $this->assertWorkClean();
    }

    private function writeUpdateTarget(string $path, string $bytes): void
    {
        $root = dirname($this->runtime->coreRoot);
        $target = $root . '/' . $path;
        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException('アップデート対象ディレクトリを作成できません。');
        }
        if (file_put_contents($target, $bytes, LOCK_EX) === false) {
            throw new \RuntimeException('アップデート対象ファイルを書き込めません: ' . $path);
        }
    }

    private function validUpdateTarget(string $path): bool
    {
        if ($path === 'Core/app.php' || $path === 'Core/.htaccess') {
            return true;
        }
        return preg_match('/^Core\/App\/[A-Za-z0-9_.-]+\.php$/', $path) === 1;
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

    private function assertWorkClean(): void
    {
        foreach (new \FilesystemIterator($this->runtime->workRoot, \FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item->getFilename() !== '.gitignore') {
                throw new \RuntimeException('アップデート開始前の作業データ保全状態を確認できません。');
            }
        }
    }
}
