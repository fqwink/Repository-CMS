#!/bin/sh
set -eu

CMS_ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
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
    exec "$DOCKER_BIN" run --rm -e RELEASE_CHECK_GIT_DONE=1 -v "$REPO_ROOT:/app" -w /app php:8.4-cli sh "$CMS_DIR/Core/release-check.sh"
  elif [ -x /Applications/Docker.app/Contents/Resources/bin/docker ]; then
    if command -v git >/dev/null 2>&1 && [ "${RELEASE_CHECK_GIT_DONE:-0}" != "1" ]; then
      run_git_checks
    fi
    exec /Applications/Docker.app/Contents/Resources/bin/docker run --rm -e RELEASE_CHECK_GIT_DONE=1 -v "$REPO_ROOT:/app" -w /app php:8.4-cli sh "$CMS_DIR/Core/release-check.sh"
  else
    echo "php or docker is required." >&2
    exit 1
  fi
fi

echo "== PHP syntax =="
for file in "$CMS_DIR"/Core/app.php "$CMS_DIR"/Core/ServerSideLogicFrameworkClient.php ServerSideLogicFramework/*.php; do
  "$PHP_BIN" -l "$file" >/dev/null
done

echo "== ServerSideLogicFramework files =="
framework_php_count=$(find ServerSideLogicFramework -maxdepth 1 -name '*.php' | wc -l | tr -d ' ')
if [ "$framework_php_count" -ne 2 ] || [ ! -f ServerSideLogicFramework/ServerSideLogicFramework.php ] || [ ! -f ServerSideLogicFramework/ServerSideLogicFrameworkClient.php ]; then
  echo "ServerSideLogicFramework must contain ServerSideLogicFramework.php and ServerSideLogicFrameworkClient.php only." >&2
  find ServerSideLogicFramework -maxdepth 1 -name '*.php' -print >&2
  exit 1
fi

echo "== ServerSideLogicFramework client copy =="
if [ ! -f "$CMS_DIR/Core/ServerSideLogicFrameworkClient.php" ]; then
  echo "ServerSideLogicFramework client copy is missing from Core." >&2
  exit 1
fi
if ! cmp -s ServerSideLogicFramework/ServerSideLogicFrameworkClient.php "$CMS_DIR/Core/ServerSideLogicFrameworkClient.php"; then
  echo "ServerSideLogicFramework client copy differs from source." >&2
  exit 1
fi

echo "== Framework direct call boundary =="
direct_framework_calls=$(grep -n -E 'ServerSideLogicFramework\\(Auth|LockManager|WorkData|Security|UpdateValidator|UpdateApplier|ServerSideLogicFramework);|ServerSideLogicFramework::|new (Auth|LockManager|WorkData|ServerSideLogicFramework)\b' "$CMS_DIR/Core/app.php" || true)
if [ -n "$direct_framework_calls" ]; then
  echo "Core/app.php must use ServerSideLogicFrameworkClient instead of framework internals:" >&2
  echo "$direct_framework_calls" >&2
  exit 1
fi

echo "== Core PHP file count =="
core_php_count=$(find "$CMS_DIR/Core" -maxdepth 1 -name '*.php' | wc -l | tr -d ' ')
if [ "$core_php_count" -ne 2 ]; then
  echo "Core PHP file count must be exactly 2: $core_php_count" >&2
  find "$CMS_DIR/Core" -maxdepth 1 -name '*.php' -print >&2
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
Work" ]; then
  echo "RepositoryCMS counted directories must be Core and Work:" >&2
  echo "$cms_dirs" >&2
  exit 1
fi

echo "== Core direct directories =="
core_dirs=$(find "$CMS_DIR/Core" -mindepth 1 -maxdepth 1 -type d -print | sed "s#^$CMS_DIR/Core/##" | sort)
if [ "$core_dirs" != "Config
Lang
Themes" ]; then
  echo "Core direct directories must be Config, Lang, Themes:" >&2
  echo "$core_dirs" >&2
  exit 1
fi

echo "== Prohibited directories =="
for dir in "$CMS_DIR/Core/App" "$CMS_DIR/Core/Data" "$CMS_DIR/Modules"; do
  if [ -e "$dir" ]; then
    echo "Prohibited or frozen directory exists: $dir" >&2
    exit 1
  fi
done

echo "== Core/Config structure =="
if find "$CMS_DIR/Core/Config" -mindepth 1 -type d -print | grep . >/dev/null 2>&1; then
  echo "Core/Config must not contain subdirectories." >&2
  find "$CMS_DIR/Core/Config" -mindepth 1 -type d -print >&2
  exit 1
fi

echo "== Developer managed resources =="
for file in "$CMS_DIR"/Core/Lang/ja.json "$CMS_DIR"/Core/Themes/standard.json "$CMS_DIR"/Core/Themes/blog.json "$CMS_DIR"/Core/Themes/media.json; do
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

echo "== Protected tracked config =="
if [ "${RELEASE_CHECK_GIT_DONE:-0}" = "1" ]; then
  echo "already checked before Docker execution."
elif command -v git >/dev/null 2>&1; then
  check_protected_tracked_config
else
  echo "git not found; skipped tracked config check."
fi

echo "== Future content features not exposed in v0.14 stable =="
if grep -n -E 'blog_post|ad_slot|広告枠を作成|ブログ投稿' "$CMS_DIR/Core/app.php" AGENTS.md >/dev/null 2>&1; then
  echo "future content feature code or UI is exposed in v0.14 stable." >&2
  grep -n -E 'blog_post|ad_slot|広告枠を作成|ブログ投稿' "$CMS_DIR/Core/app.php" AGENTS.md >&2
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

echo "== Runtime client integration =="
"$PHP_BIN" <<'PHP'
<?php
define('REPOSITORY_CMS_NO_RUN', true);
require 'RepositoryCMS/Core/app.php';

use RepositoryCms\Core\Runtime;
use ServerSideLogicFramework\ServerSideLogicFrameworkClient;

$base = sys_get_temp_dir() . '/repository-cms-runtime-' . bin2hex(random_bytes(4));
mkdir($base . '/Core/Config', 0700, true);
mkdir($base . '/Work', 0700, true);
file_put_contents($base . '/Work/.gitignore', '');

$previous = [
    'REPOSITORY_CMS_GITHUB_TOKEN' => getenv('REPOSITORY_CMS_GITHUB_TOKEN'),
    'REPOSITORY_CMS_GITHUB_OWNER' => getenv('REPOSITORY_CMS_GITHUB_OWNER'),
    'REPOSITORY_CMS_CONTENT_REPO' => getenv('REPOSITORY_CMS_CONTENT_REPO'),
    'REPOSITORY_CMS_PUBLIC_REPO' => getenv('REPOSITORY_CMS_PUBLIC_REPO'),
    'REPOSITORY_CMS_OPS_REPO' => getenv('REPOSITORY_CMS_OPS_REPO'),
    'REPOSITORY_CMS_UPDATE_REPO' => getenv('REPOSITORY_CMS_UPDATE_REPO'),
];
foreach (array_keys($previous) as $name) {
    putenv($name);
}

$runtime = Runtime::create($base . '/Core');
if (!$runtime->serverSideClient instanceof ServerSideLogicFrameworkClient) {
    fwrite(STDERR, "runtime client integration failed\n");
    exit(1);
}
if ($runtime->git->configured()) {
    fwrite(STDERR, "runtime null git configuration failed\n");
    exit(1);
}
if (($runtime->serverSideClient->lockState()['reason'] ?? '') !== 'Gitプロバイダーが未設定です。') {
    fwrite(STDERR, "runtime git lock state failed\n");
    exit(1);
}
foreach (['Config', 'Lang', 'Themes'] as $dir) {
    if (!is_dir($base . '/Core/' . $dir)) {
        fwrite(STDERR, "runtime directory failed: {$dir}\n");
        exit(1);
    }
}

foreach ($previous as $name => $value) {
    if ($value === false) {
        putenv($name);
    } else {
        putenv($name . '=' . $value);
    }
}

echo "runtime-client-ok\n";
PHP

echo "== App render smoke =="
"$PHP_BIN" <<'PHP'
<?php
define('REPOSITORY_CMS_NO_RUN', true);
require 'RepositoryCMS/Core/app.php';

use RepositoryCms\Core\App;
use RepositoryCms\Core\Config;
use RepositoryCms\Core\GitProvider;
use RepositoryCms\Core\Runtime;
use ServerSideLogicFramework\Auth;
use ServerSideLogicFramework\LockManager;
use ServerSideLogicFramework\ServerSideLogicFramework;
use ServerSideLogicFramework\ServerSideLogicFrameworkClient;
use ServerSideLogicFramework\WorkData;

final class ReleaseCheckRenderGit implements GitProvider
{
    public function configured(): bool { return true; }
    public function listContent(): array { return []; }
    public function readContent(string $path): string { return ''; }
    public function readContentAt(string $path, string $ref): string { return ''; }
    public function saveContent(string $path, string $bytes, string $message): void {}
    public function history(string $path): array { return []; }
    public function readPublicContent(string $path): string { return ''; }
    public function savePublicContent(string $path, string $bytes, string $message): void {}
    public function saveOperationLog(array $event): void {}
    public function listUpdateReleases(): array { return []; }
    public function readUpdateFile(string $path): string { return ''; }
}

function render_runtime(string $base): Runtime
{
    mkdir($base . '/config', 0700, true);
    mkdir($base . '/work', 0700, true);
    mkdir($base . '/core', 0700, true);
    file_put_contents($base . '/work/.gitignore', '');
    $auth = new Auth($base . '/config/auth.json', $base . '/config/login_state.json', $base . '/config/admin_initial_state.json');
    $locks = new LockManager($base . '/config/cms_lock.json');
    $workData = new WorkData($base . '/work', $locks);
    $config = new Config('github', 'token', 'owner', 'content', 'public', 'ops', 'updates', 'main', 'updates/releases.json', 'main');
    return new Runtime($base . '/core', $base . '/config', $base . '/work', $config, new ReleaseCheckRenderGit(), new ServerSideLogicFrameworkClient(new ServerSideLogicFramework($auth, $locks, $workData)));
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['action' => 'login'];
$_POST = [];
$_SESSION = [];
$runtime = render_runtime(sys_get_temp_dir() . '/repository-cms-render-login-' . bin2hex(random_bytes(4)));
ob_start();
(new App($runtime))->handle();
$loginHtml = ob_get_clean();
if (!is_string($loginHtml) || !str_contains($loginHtml, 'ログイン') || !str_contains($loginHtml, 'v.0.14')) {
    fwrite(STDERR, "login render failed\n");
    exit(1);
}

$_GET = [];
$_POST = [];
$_SESSION = [];
$runtime = render_runtime(sys_get_temp_dir() . '/repository-cms-render-dashboard-' . bin2hex(random_bytes(4)));
$runtime->serverSideClient->boot('メンテナンス解除待機中です。');
$runtime->serverSideClient->completeInitialAdminChange('owner', 'Password123456');
$runtime->serverSideClient->login('owner', 'Password123456');
ob_start();
(new App($runtime))->handle();
$dashboardHtml = ob_get_clean();
if (!is_string($dashboardHtml) || !str_contains($dashboardHtml, 'ダッシュボード') || !str_contains($dashboardHtml, 'v.0.14')) {
    fwrite(STDERR, "dashboard render failed\n");
    exit(1);
}

echo "app-render-ok\n";
PHP

echo "== Conservation tests =="
"$PHP_BIN" <<'PHP'
<?php
define('REPOSITORY_CMS_NO_RUN', true);
require 'RepositoryCMS/Core/app.php';

use RepositoryCms\Core\Config;
use RepositoryCms\Core\ContentManager;
use RepositoryCms\Core\GitProvider;
use RepositoryCms\Core\Runtime;
use ServerSideLogicFramework\Auth;
use ServerSideLogicFramework\LockManager;
use ServerSideLogicFramework\ServerSideLogicFramework;
use ServerSideLogicFramework\ServerSideLogicFrameworkClient;
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
    $runtime = new Runtime('/app/RepositoryCMS/Core', $base . '/config', $base . '/work', $config, $git, new ServerSideLogicFrameworkClient(new ServerSideLogicFramework($auth, $locks, $workData)));
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
define('REPOSITORY_CMS_NO_RUN', true);
require 'RepositoryCMS/Core/app.php';

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

echo "== update apply and users =="
"$PHP_BIN" <<'PHP'
<?php
define('REPOSITORY_CMS_NO_RUN', true);
require 'RepositoryCMS/Core/app.php';

use RepositoryCms\Core\Config;
use RepositoryCms\Core\GitProvider;
use RepositoryCms\Core\Runtime;
use ServerSideLogicFramework\Auth;
use ServerSideLogicFramework\LockManager;
use ServerSideLogicFramework\ServerSideLogicFramework;
use ServerSideLogicFramework\ServerSideLogicFrameworkClient;
use ServerSideLogicFramework\WorkData;

final class ReleaseCheckUpdateGit implements GitProvider
{
    public string $updateBytes = '<?php echo "v0.15";';
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
            ['version' => 'v.0.15', 'target_version' => 'v.0.14', 'released_at' => '2026-08-04T00:00:00Z', 'php' => '8.4', 'files' => [['path' => 'Core/app.php', 'source' => 'updates/v0.15/Core/app.php', 'checksum' => hash('sha256', $this->updateBytes)]]],
            ['version' => 'v.0.13', 'target_version' => 'v.0.12', 'released_at' => '2026-08-04T00:00:00Z', 'php' => '8.4', 'files' => [['path' => 'Core/app.php', 'source' => 'updates/v0.13/Core/app.php', 'checksum' => str_repeat('a', 64)]]],
            ['version' => 'broken'],
        ];
    }
    public function readUpdateFile(string $path): string { return $this->badBytes ? 'bad' : $this->updateBytes; }
}

$base = sys_get_temp_dir() . '/repository-cms-release-update-' . bin2hex(random_bytes(4));
mkdir($base . '/config', 0700, true);
mkdir($base . '/work', 0700, true);
mkdir($base . '/core', 0700, true);
file_put_contents($base . '/work/.gitignore', '');
file_put_contents($base . '/core/app.php', '<?php echo "old";');
file_put_contents($base . '/core/ServerSideLogicFrameworkClient.php', file_get_contents('ServerSideLogicFramework/ServerSideLogicFrameworkClient.php'));
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
$runtime = new Runtime($base . '/core', $base . '/config', $base . '/work', $config, $git, new ServerSideLogicFrameworkClient(new ServerSideLogicFramework($auth, $locks, $workData)));

$report = $runtime->serverSideClient->validateUpdate($runtime, $git->listUpdateReleases()[0]);
if ($report['valid'] !== true) {
    fwrite(STDERR, "update validation failed\n");
    exit(1);
}
$report = $runtime->serverSideClient->applyUpdate($runtime, $git->listUpdateReleases()[0], 'メンテナンス解除待機中です。');
if ($report['valid'] !== true || file_get_contents($base . '/core/app.php') !== $git->updateBytes || $locks->state()['reason'] !== 'メンテナンス解除待機中です。') {
    fwrite(STDERR, "update apply failed\n");
    exit(1);
}
$entries = array_values(array_diff(scandir($base . '/work'), ['.', '..']));
if ($entries !== ['.gitignore']) {
    fwrite(STDERR, "update work cleanup failed\n");
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

echo "update-apply-users-ok\n";
PHP

echo "release-check-ok"
