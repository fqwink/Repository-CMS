#!/bin/sh
set -eu

CMS_ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
REPO_ROOT=$(CDPATH= cd -- "$CMS_ROOT/.." && pwd)
CMS_DIR=RepositoryCMS
cd "$REPO_ROOT"

check_protected_tracked_config() {
  echo "== Protected tracked config =="
  if git ls-files "$CMS_DIR/Core/Config" | grep -E "^$CMS_DIR/Core/Config/.+\.json$" >/dev/null 2>&1; then
    echo "Protected runtime config is tracked under $CMS_DIR/Core/Config." >&2
    git ls-files "$CMS_DIR/Core/Config" | grep -E "^$CMS_DIR/Core/Config/.+\.json$" >&2
    exit 1
  fi
}

check_whitespace_diff() {
  echo "== Whitespace diff =="
  git diff --check
}

run_git_checks() {
  check_protected_tracked_config
  check_whitespace_diff
}

DOCKER_BIN=${DOCKER_BIN:-}
if [ -z "${PHP_BIN:-}" ]; then
  if command -v php >/dev/null 2>&1; then
    PHP_BIN=php
  elif [ -n "$DOCKER_BIN" ] && [ -x "$DOCKER_BIN" ]; then
    if command -v git >/dev/null 2>&1 && [ "${RELEASE_CHECK_GIT_DONE:-0}" != "1" ]; then
      run_git_checks
    fi
    exec "$DOCKER_BIN" run --rm -e RELEASE_CHECK_GIT_DONE=1 -v "$REPO_ROOT:/app" -w /app php:8.4-cli sh "$CMS_DIR/Core/App/release-check.sh"
  elif [ -x /Applications/Docker.app/Contents/Resources/bin/docker ]; then
    if command -v git >/dev/null 2>&1 && [ "${RELEASE_CHECK_GIT_DONE:-0}" != "1" ]; then
      run_git_checks
    fi
    exec /Applications/Docker.app/Contents/Resources/bin/docker run --rm -e RELEASE_CHECK_GIT_DONE=1 -v "$REPO_ROOT:/app" -w /app php:8.4-cli sh "$CMS_DIR/Core/App/release-check.sh"
  else
    echo "php or docker is required." >&2
    exit 1
  fi
fi

echo "== PHP syntax =="
for file in "$CMS_DIR"/Core/app.php "$CMS_DIR"/Core/App/*.php ServerSideLogicFramework/*.php; do
  if [ ! -f "$file" ]; then
    continue
  fi
  "$PHP_BIN" -l "$file" >/dev/null
done

echo "== ServerSideLogicFramework single file =="
framework_php_count=$(find ServerSideLogicFramework -maxdepth 1 -name '*.php' | wc -l | tr -d ' ')
if [ "$framework_php_count" -ne 1 ] || [ ! -f ServerSideLogicFramework/ServerSideLogicFramework.php ]; then
  echo "ServerSideLogicFramework PHP implementation must be ServerSideLogicFramework.php only." >&2
  exit 1
fi

echo "== Core/App file count =="
core_app_count=$(find "$CMS_DIR/Core/App" -maxdepth 1 -name '*.php' | wc -l | tr -d ' ')
if [ "$core_app_count" -gt 23 ]; then
  echo "Core/App PHP file count exceeds 23: $core_app_count" >&2
  exit 1
fi

echo "== Root directory count =="
root_dirs=$(find . -mindepth 1 -maxdepth 1 -type d ! -name '.git' -print | sed 's#^\./##' | sort)
if [ "$root_dirs" != "RepositoryCMS
ServerSideLogicFramework" ]; then
  echo "Repository root directories must be RepositoryCMS and ServerSideLogicFramework:" >&2
  echo "$root_dirs" >&2
  exit 1
fi

echo "== CMS directory count =="
cms_dirs=$(find "$CMS_DIR" -mindepth 1 -maxdepth 1 -type d ! -name 'Docs' -print | sed "s#^$CMS_DIR/##" | sort)
if [ "$cms_dirs" != "Core
Modules
Work" ]; then
  echo "RepositoryCMS counted directories must be Core, Modules, Work:" >&2
  echo "$cms_dirs" >&2
  exit 1
fi

echo "== Core direct directories =="
core_dirs=$(find "$CMS_DIR/Core" -mindepth 1 -maxdepth 1 -type d -print | sed "s#^$CMS_DIR/Core/##" | sort)
core_dir_count=$(printf '%s\n' "$core_dirs" | sed '/^$/d' | wc -l | tr -d ' ')
if [ "$core_dir_count" -gt 7 ]; then
  echo "Core direct directory count exceeds 7: $core_dir_count" >&2
  exit 1
fi
if printf '%s\n' "$core_dirs" | grep -x 'Data' >/dev/null 2>&1; then
  echo "Core/Data is prohibited." >&2
  exit 1
fi

echo "== Core/Config structure =="
if find "$CMS_DIR/Core/Config" -mindepth 1 -type d -print | grep . >/dev/null 2>&1; then
  echo "Core/Config must not contain subdirectories." >&2
  find "$CMS_DIR/Core/Config" -mindepth 1 -type d -print >&2
  exit 1
fi

echo "== Developer managed app resources =="
for file in "$CMS_DIR"/Core/App/Lang/ja.json "$CMS_DIR"/Core/App/Themes/standard.json "$CMS_DIR"/Core/App/Themes/blog.json "$CMS_DIR"/Core/App/Themes/media.json; do
  if [ ! -f "$file" ]; then
    echo "Required developer managed resource is missing: $file" >&2
    exit 1
  fi
done

echo "== Work data =="
work_entries=$(find "$CMS_DIR/Work" -mindepth 1 -maxdepth 2 -print | sed "s#^$CMS_DIR/##" | sort)
if [ "$work_entries" != "Work/.gitignore" ]; then
  echo "Work contains unexpected files:" >&2
  echo "$work_entries" >&2
  exit 1
fi

echo "== Core work data prohibition =="
if find "$CMS_DIR/Core" -maxdepth 2 -type d \( -iname '*work*' -o -iname '*cache*' -o -iname '*tmp*' -o -iname '*temp*' \) -print | grep . >/dev/null 2>&1; then
  echo "Core contains work/cache/tmp-like paths." >&2
  find "$CMS_DIR/Core" -maxdepth 2 -type d \( -iname '*work*' -o -iname '*cache*' -o -iname '*tmp*' -o -iname '*temp*' \) -print >&2
  exit 1
fi

echo "== Protected tracked config =="
if [ "${RELEASE_CHECK_GIT_DONE:-0}" = "1" ]; then
  echo "already checked before Docker execution."
elif command -v git >/dev/null 2>&1; then
  check_protected_tracked_config
else
  echo "git not found; skipped tracked config check."
fi

echo "== Future content features not exposed in v0.13 =="
if grep -R --exclude='release-check.sh' -n -E 'blog_post|ad_slot|広告枠を作成|ブログ投稿' "$CMS_DIR/Core/App" AGENTS.md >/dev/null 2>&1; then
  echo "v0.14+ content feature code or UI is exposed in v0.13." >&2
  grep -R --exclude='release-check.sh' -n -E 'blog_post|ad_slot|広告枠を作成|ブログ投稿' "$CMS_DIR/Core/App" AGENTS.md >&2
  exit 1
fi

echo "== Whitespace diff =="
if [ "${RELEASE_CHECK_GIT_DONE:-0}" = "1" ]; then
  echo "already checked before Docker execution."
elif command -v git >/dev/null 2>&1; then
  check_whitespace_diff
else
  echo "git not found; skipped git diff --check."
fi

echo "== Conservation tests =="
"$PHP_BIN" <<'PHP'
<?php
require 'RepositoryCMS/Core/App/Bootstrap.php';

use RepositoryCms\Core\Config;
use RepositoryCms\Core\ContentManager;
use RepositoryCms\Core\GitProvider;
use RepositoryCms\Core\Runtime;
use ServerSideLogicFramework\Auth;
use ServerSideLogicFramework\LockManager;
use ServerSideLogicFramework\ServerSideLogicFramework;
use ServerSideLogicFramework\WorkData;

final class ReleaseCheckMemoryGit implements GitProvider
{
    public array $content = [];
    public bool $failSave = false;

    public function configured(): bool { return true; }
    public function listContent(): array { return []; }
    public function readContent(string $path): string { return $this->content[$path] ?? ''; }
    public function readContentAt(string $path, string $ref): string { return $this->readContent($path); }
    public function saveContent(string $path, string $bytes, string $message): void
    {
        if ($this->failSave) {
            throw new RuntimeException('save failed');
        }
        $this->content[$path] = $bytes;
    }
    public function history(string $path): array { return []; }
    public function readPublicContent(string $path): string { return ''; }
    public function savePublicContent(string $path, string $bytes, string $message): void {}
    public function saveOperationLog(array $event): void {}
    public function listUpdateReleases(): array { return []; }
    public function readUpdateFile(string $path): string { return ''; }
}

function release_runtime(string $suffix, ReleaseCheckMemoryGit $git): array
{
    $base = sys_get_temp_dir() . '/repository-cms-release-' . $suffix . '-' . bin2hex(random_bytes(4));
    mkdir($base . '/config', 0700, true);
    mkdir($base . '/work', 0700, true);
    file_put_contents($base . '/work/.gitignore', '');
    file_put_contents($base . '/config/auth.json', json_encode([
        'username' => 'admin',
        'password_hash' => password_hash('Password123456', PASSWORD_DEFAULT),
    ]));
    $config = new Config('github', 'token', 'owner', 'content', 'public', 'ops', 'updates', 'main', 'updates/releases.json', 'main');
    $locks = new LockManager($base . '/config/cms_lock.json');
    $auth = new Auth($base . '/config/auth.json', $base . '/config/login_state.json', $base . '/config/admin_initial_state.json');
    $workData = new WorkData($base . '/work', $locks);
    $runtime = new Runtime('/app/RepositoryCMS/Core', '/app/RepositoryCMS/Core/App', $base . '/config', $base . '/work', $config, $locks, $workData, $git, $auth, new ServerSideLogicFramework($auth, $locks, $workData));
    return [$base, $runtime, $locks];
}

$git = new ReleaseCheckMemoryGit();
[$base, $runtime, $locks] = release_runtime('save-ok', $git);
(new ContentManager($runtime))->save('pages/index.md', '# Saved');
$entries = array_values(array_diff(scandir($base . '/work'), ['.', '..']));
if ($locks->locked() || ($git->content['pages/index.md'] ?? '') !== '# Saved' || $entries !== ['.gitignore']) {
    fwrite(STDERR, "save success conservation failed\n");
    exit(1);
}

$git = new ReleaseCheckMemoryGit();
$git->failSave = true;
[$base, $runtime, $locks] = release_runtime('save-fail', $git);
$failed = false;
try {
    (new ContentManager($runtime))->save('pages/index.md', '# Failed');
} catch (Throwable $error) {
    $failed = str_contains($error->getMessage(), 'save failed');
}
$entries = array_values(array_diff(scandir($base . '/work'), ['.', '..']));
if (!$failed || !$locks->locked() || $entries !== ['.gitignore']) {
    fwrite(STDERR, "save failure conservation failed\n");
    exit(1);
}

echo "conservation-ok\n";
PHP

echo "== Theme source tests =="
"$PHP_BIN" <<'PHP'
<?php
require 'RepositoryCMS/Core/App/Bootstrap.php';

use RepositoryCms\Core\StaticGenerator;

$themes = StaticGenerator::themes();
foreach (['standard', 'blog', 'media'] as $name) {
    if (!isset($themes[$name]) || !StaticGenerator::validTheme($name)) {
        fwrite(STDERR, "theme source failed: {$name}\n");
        exit(1);
    }
}
if (StaticGenerator::validTheme('custom')) {
    fwrite(STDERR, "unexpected custom theme accepted\n");
    exit(1);
}
echo "theme-source-ok\n";
PHP

echo "== App render tests =="
"$PHP_BIN" <<'PHP'
<?php
require 'RepositoryCMS/Core/App/Bootstrap.php';

use RepositoryCms\Core\App;
use RepositoryCms\Core\Config;
use RepositoryCms\Core\GitProvider;
use RepositoryCms\Core\Runtime;
use ServerSideLogicFramework\Auth;
use ServerSideLogicFramework\LockManager;
use ServerSideLogicFramework\ServerSideLogicFramework;
use ServerSideLogicFramework\WorkData;

final class ReleaseCheckRenderGit implements GitProvider
{
    public array $content = ['pages/index.md' => '# Hello'];
    public array $public = [];
    public array $logs = [];

    public function configured(): bool { return true; }
    public function listContent(): array { return [['path' => 'pages/index.md', 'size' => strlen($this->content['pages/index.md'])]]; }
    public function readContent(string $path): string { return $this->content[$path] ?? ''; }
    public function readContentAt(string $path, string $ref): string { return $this->readContent($path); }
    public function saveContent(string $path, string $bytes, string $message): void { $this->content[$path] = $bytes; }
    public function history(string $path): array { return [['sha' => 'abcdef1234567890', 'date' => '2026-08-05T00:00:00Z', 'message' => 'test']]; }
    public function readPublicContent(string $path): string { return $this->public[$path] ?? ''; }
    public function savePublicContent(string $path, string $bytes, string $message): void { $this->public[$path] = $bytes; }
    public function saveOperationLog(array $event): void { $this->logs[] = $event; }
    public function listUpdateReleases(): array { return []; }
    public function readUpdateFile(string $path): string { return ''; }
}

$base = sys_get_temp_dir() . '/repository-cms-render-' . bin2hex(random_bytes(4));
mkdir($base . '/core/App', 0700, true);
mkdir($base . '/config', 0700, true);
mkdir($base . '/work', 0700, true);
file_put_contents($base . '/work/.gitignore', '');
$config = new Config('github', 'token', 'owner', 'content', 'public', 'ops', 'updates', 'main', 'updates/releases.json', 'main');
$locks = new LockManager($base . '/config/cms_lock.json');
$auth = new Auth($base . '/config/auth.json', $base . '/config/login_state.json', $base . '/config/admin_initial_state.json');
$workData = new WorkData($base . '/work', $locks);
$git = new ReleaseCheckRenderGit();
$runtime = new Runtime($base . '/core', '/app/RepositoryCMS/Core/App', $base . '/config', $base . '/work', $config, $locks, $workData, $git, $auth, new ServerSideLogicFramework($auth, $locks, $workData));
$runtime->serverSide->boot('メンテナンス解除待機中です。');
$runtime->serverSide->completeInitialAdminChange('owner', 'Password123456');
$_SESSION['admin'] = 'owner';
$_SESSION['role'] = 'admin';
$_SESSION['last_seen_at'] = time();
$_SERVER['REQUEST_METHOD'] = 'GET';

$_GET = [];
ob_start();
(new App($runtime))->handle();
$html = ob_get_clean();
if (!str_contains($html, 'ダッシュボード') || !str_contains($html, 'pages/index.md')) {
    fwrite(STDERR, "dashboard render failed\n");
    exit(1);
}

$_GET = ['action' => 'generate'];
ob_start();
(new App($runtime))->handle();
$html = ob_get_clean();
if (!str_contains($html, '静的生成') || !str_contains($html, '実行')) {
    fwrite(STDERR, "generate form render failed\n");
    exit(1);
}

echo "app-render-ok\n";
PHP

echo "== update apply and users =="
"$PHP_BIN" <<'PHP'
<?php
require 'RepositoryCMS/Core/App/Bootstrap.php';

use RepositoryCms\Core\App;
use RepositoryCms\Core\Config;
use RepositoryCms\Core\GitProvider;
use RepositoryCms\Core\Runtime;
use ServerSideLogicFramework\Auth;
use ServerSideLogicFramework\LockManager;
use ServerSideLogicFramework\ServerSideLogicFramework;
use ServerSideLogicFramework\WorkData;

final class ReleaseCheckUpdateGit implements GitProvider
{
    public string $updateBytes = '<?php echo "v0.14";';
    public bool $badBytes = false;

    public function configured(): bool { return true; }
    public function listContent(): array { return []; }
    public function readContent(string $path): string { return ''; }
    public function readContentAt(string $path, string $ref): string { return ''; }
    public function saveContent(string $path, string $bytes, string $message): void {}
    public function history(string $path): array { return []; }
    public function readPublicContent(string $path): string { return ''; }
    public function savePublicContent(string $path, string $bytes, string $message): void {}
    public function saveOperationLog(array $event): void {}
    public function listUpdateReleases(): array
    {
        return [
            ['version' => 'v.0.14', 'target_version' => 'v.0.13', 'released_at' => '2026-08-04T00:00:00Z', 'php' => '8.4', 'files' => [['path' => 'Core/App/App.php', 'source' => 'updates/v0.14/Core/App/App.php', 'checksum' => hash('sha256', $this->updateBytes)]]],
            ['version' => 'v.0.13', 'target_version' => 'v.0.12', 'released_at' => '2026-08-04T00:00:00Z', 'php' => '8.4', 'files' => [['path' => 'Core/App/App.php', 'source' => 'updates/v0.13/Core/App/App.php', 'checksum' => str_repeat('a', 64)]]],
            ['version' => 'broken'],
        ];
    }
    public function readUpdateFile(string $path): string { return $this->badBytes ? 'bad' : $this->updateBytes; }
}

$base = sys_get_temp_dir() . '/repository-cms-release-update-' . bin2hex(random_bytes(4));
mkdir($base . '/config', 0700, true);
mkdir($base . '/work', 0700, true);
mkdir($base . '/core/App', 0700, true);
file_put_contents($base . '/work/.gitignore', '');
file_put_contents($base . '/core/App/App.php', '<?php echo "old";');
file_put_contents($base . '/config/auth.json', json_encode([
    'users' => [[
        'username' => 'admin',
        'password_hash' => password_hash('Password123456', PASSWORD_DEFAULT),
        'role' => 'admin',
        'created_at' => gmdate(DATE_ATOM),
    ]],
]));
$config = new Config('github', 'token', 'owner', 'content', 'public', 'ops', 'updates', 'main', 'updates/releases.json', 'main');
$locks = new LockManager($base . '/config/cms_lock.json');
$git = new ReleaseCheckUpdateGit();
$auth = new Auth($base . '/config/auth.json', $base . '/config/login_state.json', $base . '/config/admin_initial_state.json');
$workData = new WorkData($base . '/work', $locks);
$runtime = new Runtime($base . '/core', $base . '/core/App', $base . '/config', $base . '/work', $config, $locks, $workData, $git, $auth, new ServerSideLogicFramework($auth, $locks, $workData));
$_SESSION['admin'] = 'admin';
$_SESSION['role'] = 'admin';
$_SESSION['last_seen_at'] = time();
$app = new App($runtime);
$method = new ReflectionMethod(App::class, 'updates');
$method->setAccessible(true);
ob_start();
$method->invoke($app);
$html = ob_get_clean();
if (!str_contains($html, 'v.0.14') || !str_contains($html, '事前検証')) {
    fwrite(STDERR, "update list failed\n");
    exit(1);
}
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['csrf'] = $runtime->serverSide->csrfToken();
$_POST['version'] = 'v.0.14';
$method = new ReflectionMethod(App::class, 'validateUpdate');
$method->setAccessible(true);
ob_start();
$method->invoke($app);
$html = ob_get_clean();
if (!str_contains($html, '検証は成功しました') || !str_contains($html, 'アップデート適用')) {
    fwrite(STDERR, "update validation failed\n");
    exit(1);
}

$report = $runtime->serverSide->applyUpdate($runtime, $git->listUpdateReleases()[0], 'メンテナンス解除待機中です。');
if ($report['valid'] !== true || file_get_contents($base . '/core/App/App.php') !== $git->updateBytes || $locks->state()['reason'] !== 'メンテナンス解除待機中です。') {
    fwrite(STDERR, "update apply failed\n");
    exit(1);
}
$entries = array_values(array_diff(scandir($base . '/work'), ['.', '..']));
if ($entries !== ['.gitignore']) {
    fwrite(STDERR, "update work cleanup failed\n");
    exit(1);
}

$badBase = sys_get_temp_dir() . '/repository-cms-release-update-bad-' . bin2hex(random_bytes(4));
mkdir($badBase . '/config', 0700, true);
mkdir($badBase . '/work', 0700, true);
mkdir($badBase . '/core/App', 0700, true);
mkdir($badBase . '/core/App/App.php', 0700, true);
file_put_contents($badBase . '/work/.gitignore', '');
file_put_contents($badBase . '/config/auth.json', file_get_contents($base . '/config/auth.json'));
$badLocks = new LockManager($badBase . '/config/cms_lock.json');
$badGit = new ReleaseCheckUpdateGit();
$badAuth = new Auth($badBase . '/config/auth.json', $badBase . '/config/login_state.json', $badBase . '/config/admin_initial_state.json');
$badWorkData = new WorkData($badBase . '/work', $badLocks);
$badRuntime = new Runtime($badBase . '/core', $badBase . '/core/App', $badBase . '/config', $badBase . '/work', $config, $badLocks, $badWorkData, $badGit, $badAuth, new ServerSideLogicFramework($badAuth, $badLocks, $badWorkData));
$failed = false;
try {
    $badRuntime->serverSide->applyUpdate($badRuntime, $badGit->listUpdateReleases()[0], 'メンテナンス解除待機中です。');
} catch (Throwable) {
    $failed = true;
}
if (!$failed || !$badLocks->locked()) {
    fwrite(STDERR, "v0.10 update failure lock failed\n");
    exit(1);
}

$auth = new Auth($base . '/config/auth.json', $base . '/config/login_state.json', $base . '/config/admin_initial_state.json');
$auth->createUser('editor1', 'Password123456', 'editor');
$auth->createUser('editor2', 'Password123456', 'editor');
$tooManyEditors = false;
try {
    $auth->createUser('editor3', 'Password123456', 'editor');
} catch (Throwable) {
    $tooManyEditors = true;
}
$tooManyAdmins = false;
try {
    $auth->createUser('admin2', 'Password123456', 'admin');
} catch (Throwable) {
    $tooManyAdmins = true;
}
if (!$tooManyEditors || !$tooManyAdmins || count($auth->users()) !== 3) {
    fwrite(STDERR, "users failed\n");
    exit(1);
}

$initialBase = sys_get_temp_dir() . '/repository-cms-release-initial-admin-' . bin2hex(random_bytes(4));
mkdir($initialBase . '/config', 0700, true);
$initialAuth = new Auth($initialBase . '/config/auth.json', $initialBase . '/config/login_state.json', $initialBase . '/config/admin_initial_state.json');
$initialAuth->ensureInitialAdmin();
if (!$initialAuth->login('admin', 'admin') || !$initialAuth->initialAdminChangeRequired()) {
    fwrite(STDERR, "initial admin login failed\n");
    exit(1);
}
for ($i = 0; $i < 5; $i++) {
    $initialAuth->recordInitialAdminAccess();
}
$initialState = $initialAuth->initialAdminState();
if ($initialState['access_count'] !== 5 || $initialState['deadline_reached'] !== true) {
    fwrite(STDERR, "initial admin access count failed\n");
    exit(1);
}
$blocked = false;
try {
    $initialAuth->createUser('blocked-editor', 'Password123456', 'editor');
} catch (Throwable) {
    $blocked = true;
}
if (!$blocked) {
    fwrite(STDERR, "initial admin user block failed\n");
    exit(1);
}
$initialAuth->completeInitialAdminChange('owner', 'Password123456');
if ($initialAuth->initialAdminChangeRequired() || !$initialAuth->login('owner', 'Password123456')) {
    fwrite(STDERR, "initial admin completion failed\n");
    exit(1);
}
$initialAuth->createUser('writer', 'Password123456', 'editor');
$initialAuth->changePassword('writer', 'Password654321');
if (!$initialAuth->login('writer', 'Password654321')) {
    fwrite(STDERR, "user password change failed\n");
    exit(1);
}
echo "update-apply-users-ok\n";
PHP

echo "release-check-ok"
