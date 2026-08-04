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
for file in "$CMS_DIR"/Core/app.php "$CMS_DIR"/Core/ServerSideLogicFramework.php "$CMS_DIR"/Core/ServerSideLogicFrameworkClient.php ServerSideLogicFramework/*.php; do
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
if [ ! -f "$CMS_DIR/Core/ServerSideLogicFramework.php" ]; then
  echo "ServerSideLogicFramework body copy is missing from Core." >&2
  exit 1
fi
if ! cmp -s ServerSideLogicFramework/ServerSideLogicFramework.php "$CMS_DIR/Core/ServerSideLogicFramework.php"; then
  echo "ServerSideLogicFramework body copy differs from source." >&2
  exit 1
fi
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
if [ "$core_php_count" -ne 3 ]; then
  echo "Core PHP file count must be exactly 3: $core_php_count" >&2
  find "$CMS_DIR/Core" -maxdepth 1 -name '*.php' -print >&2
  exit 1
fi

echo "== Root directory count =="
root_dirs=$(find . -mindepth 1 -maxdepth 1 -type d ! -name '.git' -print | sed 's#^\./##' | sort)
if [ "$root_dirs" != "Docs
RepositoryCMS
ServerSideLogicFramework" ]; then
  echo "Repository root directories must be Docs, RepositoryCMS and ServerSideLogicFramework:" >&2
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
for dir in "$CMS_DIR/Core/App" "$CMS_DIR/Core/Data" "$CMS_DIR/Modules" "$CMS_DIR/Docs" "ServerSideLogicFramework/Docs"; do
  if [ -e "$dir" ]; then
    echo "Prohibited or frozen directory exists: $dir" >&2
    exit 1
  fi
done

echo "== Documentation structure =="
for file in Docs/Project_Charter Docs/Development_Plan Docs/Brand_Color_Spec Docs/RepositoryCMS/Master_Design Docs/RepositoryCMS/Change_History Docs/ServerSideLogicFramework/Master_Design Docs/ServerSideLogicFramework/Change_History; do
  if [ ! -f "$file" ]; then
    echo "Required documentation file is missing: $file" >&2
    exit 1
  fi
done

echo "== Release package policy =="
if [ -n "${RELEASE_PACKAGE_ROOT:-}" ]; then
  package_root=$RELEASE_PACKAGE_ROOT
  if [ ! -d "$package_root" ]; then
    echo "RELEASE_PACKAGE_ROOT is not a directory: $package_root" >&2
    exit 1
  fi
  for file in \
    "$CMS_DIR/Core/app.php" \
    "$CMS_DIR/Core/.htaccess" \
    "$CMS_DIR/Core/ServerSideLogicFramework.php" \
    "$CMS_DIR/Core/ServerSideLogicFrameworkClient.php" \
    "$CMS_DIR/Core/Lang/ja.json" \
    "$CMS_DIR/Core/Lang/en.json" \
    "$CMS_DIR/Core/Themes/standard.json" \
    "$CMS_DIR/Core/Themes/blog.json" \
    "$CMS_DIR/Core/Themes/media.json"; do
    if [ ! -f "$package_root/$file" ]; then
      echo "Release package is missing required file: $file" >&2
      exit 1
    fi
  done
  for path in \
    "ServerSideLogicFramework/ServerSideLogicFrameworkClient.php" \
    "ServerSideLogicFramework/ServerSideLogicFramework.php" \
    "$CMS_DIR/Core/Config" \
    "$CMS_DIR/Work" \
    "Docs"; do
    if [ -e "$package_root/$path" ]; then
      echo "Release package contains prohibited path: $path" >&2
      exit 1
    fi
  done
  if find "$package_root" -type f -name '*.ts' -print | grep . >/dev/null 2>&1; then
    echo "Release package must not contain TypeScript source files." >&2
    find "$package_root" -type f -name '*.ts' -print >&2
    exit 1
  fi
else
  echo "RELEASE_PACKAGE_ROOT not set; skipped release package artifact check."
fi

echo "== Core/Config structure =="
if find "$CMS_DIR/Core/Config" -mindepth 1 -type d -print | grep . >/dev/null 2>&1; then
  echo "Core/Config must not contain subdirectories." >&2
  find "$CMS_DIR/Core/Config" -mindepth 1 -type d -print >&2
  exit 1
fi

echo "== Developer managed resources =="
for file in "$CMS_DIR"/Core/Lang/ja.json "$CMS_DIR"/Core/Lang/en.json "$CMS_DIR"/Core/Themes/standard.json "$CMS_DIR"/Core/Themes/blog.json "$CMS_DIR"/Core/Themes/media.json; do
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

echo "== Future content features not exposed in v0.19 =="
if grep -n -E 'blog_post|ブログ投稿' "$CMS_DIR/Core/app.php" AGENTS.md >/dev/null 2>&1; then
    echo "future content feature code or UI is exposed in v0.19." >&2
  grep -n -E 'blog_post|ブログ投稿' "$CMS_DIR/Core/app.php" AGENTS.md >&2
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
    public function listContent(): array { return [['path' => 'assets/logo.svg', 'size' => 46], ['path' => 'assets/pixel.png', 'size' => 68]]; }
    public function readContent(string $path): string
    {
        if ($path === 'assets/logo.svg') {
            return '<svg xmlns="http://www.w3.org/2000/svg"></svg>';
        }
        if ($path === 'assets/pixel.png') {
            return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==') ?: '';
        }
        return '';
    }
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
    mkdir($base . '/core/Lang', 0700, true);
    file_put_contents($base . '/work/.gitignore', '');
    copy('RepositoryCMS/Core/Lang/ja.json', $base . '/core/Lang/ja.json');
    copy('RepositoryCMS/Core/Lang/en.json', $base . '/core/Lang/en.json');
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
if (!is_string($loginHtml) || !str_contains($loginHtml, 'ログイン') || !str_contains($loginHtml, 'v.0.19')) {
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
if (!is_string($dashboardHtml) || !str_contains($dashboardHtml, 'ダッシュボード') || !str_contains($dashboardHtml, 'v.0.19') || !str_contains($dashboardHtml, '素材管理') || !str_contains($dashboardHtml, '広告配信枠') || !str_contains($dashboardHtml, 'ナビゲーション') || !str_contains($dashboardHtml, '固定ページ') || !str_contains($dashboardHtml, 'テーマ表示設定')) {
    fwrite(STDERR, "dashboard render failed\n");
    exit(1);
}

$_GET = ['action' => 'assets'];
$_POST = [];
ob_start();
(new App($runtime))->handle();
$assetsHtml = ob_get_clean();
if (!is_string($assetsHtml) || !str_contains($assetsHtml, '素材管理') || !str_contains($assetsHtml, 'assets/logo.svg') || !str_contains($assetsHtml, 'チェックサム')) {
    fwrite(STDERR, "assets render failed\n");
    exit(1);
}

echo "app-render-ok\n";
PHP

echo "== Language and site settings =="
"$PHP_BIN" <<'PHP'
<?php
define('REPOSITORY_CMS_NO_RUN', true);
require 'RepositoryCMS/Core/app.php';

use RepositoryCms\Core\AdSlots;
use RepositoryCms\Core\Config;
use RepositoryCms\Core\GitProvider;
use RepositoryCms\Core\LanguageManager;
use RepositoryCms\Core\NavigationSettings;
use RepositoryCms\Core\PagesSettings;
use RepositoryCms\Core\Runtime;
use RepositoryCms\Core\SiteSettings;
use RepositoryCms\Core\ThemeDisplaySettings;
use ServerSideLogicFramework\Auth;
use ServerSideLogicFramework\LockManager;
use ServerSideLogicFramework\ServerSideLogicFramework;
use ServerSideLogicFramework\ServerSideLogicFrameworkClient;
use ServerSideLogicFramework\WorkData;

final class ReleaseCheckSiteGit implements GitProvider
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

$base = sys_get_temp_dir() . '/repository-cms-site-' . bin2hex(random_bytes(4));
mkdir($base . '/core/Config', 0700, true);
mkdir($base . '/core/Lang', 0700, true);
mkdir($base . '/work', 0700, true);
file_put_contents($base . '/work/.gitignore', '');
copy('RepositoryCMS/Core/Lang/ja.json', $base . '/core/Lang/ja.json');
copy('RepositoryCMS/Core/Lang/en.json', $base . '/core/Lang/en.json');

LanguageManager::assertLanguageFiles('RepositoryCMS/Core/Lang');
$translator = new LanguageManager('RepositoryCMS/Core/Lang', 'en');
if ($translator->t('nav.site_settings') !== 'Site Settings' || $translator->t('nav.assets') !== 'Assets' || $translator->t('nav.ad_slots') !== 'Ad Slots' || $translator->t('nav.navigation') !== 'Navigation' || $translator->t('nav.pages') !== 'Pages' || $translator->t('nav.theme_display') !== 'Theme Display') {
    fwrite(STDERR, "language translation failed\n");
    exit(1);
}

$locks = new LockManager($base . '/core/Config/cms_lock.json');
$auth = new Auth($base . '/core/Config/auth.json', $base . '/core/Config/login_state.json', $base . '/core/Config/admin_initial_state.json');
$workData = new WorkData($base . '/work', $locks);
$config = new Config('github', 'token', 'owner', 'content', 'public', 'ops', 'updates', 'main', 'updates/releases.json', 'main');
$runtime = new Runtime($base . '/core', $base . '/core/Config', $base . '/work', $config, new ReleaseCheckSiteGit(), new ServerSideLogicFrameworkClient(new ServerSideLogicFramework($auth, $locks, $workData)));

$settings = SiteSettings::save($runtime, [
    'site_name' => 'Example Site',
    'site_description' => 'Example Description',
    'public_url' => 'https://example.com',
    'site_language' => 'en',
    'meta_title' => 'Example Title',
    'meta_description' => 'Example Meta',
]);
$read = SiteSettings::read($runtime);
if ($settings->toArray() !== $read->toArray() || $read->siteLanguage !== 'en') {
    fwrite(STDERR, "site settings conservation failed\n");
    exit(1);
}
if (trim(file_get_contents($base . '/core/Config/site.json') ?: '') === '') {
    fwrite(STDERR, "site settings file missing\n");
    exit(1);
}

$adSlots = AdSlots::save($runtime, [
    'slots' => [
        ['id' => 'header_main', 'name' => 'Header', 'position' => 'header', 'enabled' => true, 'content' => 'Ad Text'],
    ],
]);
$readAdSlots = AdSlots::read($runtime);
if ($adSlots->toArray() !== $readAdSlots->toArray() || count($readAdSlots->enabled()) !== 1) {
    fwrite(STDERR, "ad slots conservation failed\n");
    exit(1);
}

$navigation = NavigationSettings::save($runtime, [
    'items' => [
        ['label' => 'Home', 'url' => '/', 'order' => '0', 'enabled' => true],
        ['label' => 'Contact', 'url' => 'mailto:info@example.com', 'order' => '1', 'enabled' => true],
    ],
]);
$readNavigation = NavigationSettings::read($runtime);
if ($navigation->toArray() !== $readNavigation->toArray() || count($readNavigation->enabled()) !== 2) {
    fwrite(STDERR, "navigation conservation failed\n");
    exit(1);
}

$pages = PagesSettings::save($runtime, [
    'pages' => [
        ['title' => 'Home', 'path' => 'pages/index.md', 'published' => true, 'order' => '0'],
    ],
]);
$readPages = PagesSettings::read($runtime);
if ($pages->toArray() !== $readPages->toArray() || count($readPages->published()) !== 1) {
    fwrite(STDERR, "pages conservation failed\n");
    exit(1);
}

$themeDisplay = ThemeDisplaySettings::save($runtime, [
    'show_site_name' => true,
    'show_navigation' => true,
    'show_ad_slots' => true,
    'color_scope' => 'full',
]);
$readThemeDisplay = ThemeDisplaySettings::read($runtime);
if ($themeDisplay->toArray() !== $readThemeDisplay->toArray() || $readThemeDisplay->colorScope !== 'full') {
    fwrite(STDERR, "theme display conservation failed\n");
    exit(1);
}

$invalid = false;
try {
    SiteSettings::save($runtime, ['site_language' => 'fr']);
} catch (Throwable) {
    $invalid = true;
}
if (!$invalid) {
    fwrite(STDERR, "invalid site language accepted\n");
    exit(1);
}

echo "language-site-settings-ok\n";
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

echo "== Asset validation =="
"$PHP_BIN" <<'PHP'
<?php
define('REPOSITORY_CMS_NO_RUN', true);
require 'RepositoryCMS/Core/app.php';

use ServerSideLogicFramework\Auth;
use ServerSideLogicFramework\LockManager;
use ServerSideLogicFramework\ServerSideLogicFramework;
use ServerSideLogicFramework\ServerSideLogicFrameworkClient;
use ServerSideLogicFramework\WorkData;

$base = sys_get_temp_dir() . '/repository-cms-asset-validation-' . bin2hex(random_bytes(4));
mkdir($base . '/config', 0700, true);
mkdir($base . '/work', 0700, true);
file_put_contents($base . '/work/.gitignore', '');
$locks = new LockManager($base . '/config/cms_lock.json');
$auth = new Auth($base . '/config/auth.json', $base . '/config/login_state.json', $base . '/config/admin_initial_state.json');
$client = new ServerSideLogicFrameworkClient(new ServerSideLogicFramework($auth, $locks, new WorkData($base . '/work', $locks)));
$client->validateContent('assets/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
$client->validateContent('assets/pixel.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==') ?: '');

$blocked = 0;
foreach ([
    '<svg><script>alert(1)</script></svg>',
    '<svg onload="alert(1)"></svg>',
    '<svg><image href="https://example.com/a.png"/></svg>',
    '<svg><image href="assets/a.png"/></svg>',
    '<!DOCTYPE svg><svg></svg>',
] as $svg) {
    try {
        $client->validateContent('assets/bad.svg', $svg);
    } catch (Throwable) {
        $blocked++;
    }
}
if ($blocked !== 5) {
    fwrite(STDERR, "asset validation failed\n");
    exit(1);
}

echo "asset-validation-ok\n";
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

echo "== Static generation site metadata =="
"$PHP_BIN" <<'PHP'
<?php
define('REPOSITORY_CMS_NO_RUN', true);
require 'RepositoryCMS/Core/app.php';

use RepositoryCms\Core\Config;
use RepositoryCms\Core\GitProvider;
use RepositoryCms\Core\AdSlots;
use RepositoryCms\Core\NavigationSettings;
use RepositoryCms\Core\PagesSettings;
use RepositoryCms\Core\Renderer;
use RepositoryCms\Core\Runtime;
use RepositoryCms\Core\SiteSettings;
use RepositoryCms\Core\StaticGenerator;
use RepositoryCms\Core\ThemeDisplaySettings;
use ServerSideLogicFramework\Auth;
use ServerSideLogicFramework\LockManager;
use ServerSideLogicFramework\ServerSideLogicFramework;
use ServerSideLogicFramework\ServerSideLogicFrameworkClient;
use ServerSideLogicFramework\WorkData;

final class ReleaseCheckStaticGit implements GitProvider
{
    public array $public = [];
    public function configured(): bool { return true; }
    public function listContent(): array { return [['path' => 'pages/index.md', 'size' => 7]]; }
    public function readContent(string $path): string { return '# Hello'; }
    public function readContentAt(string $path, string $ref): string { return $this->readContent($path); }
    public function saveContent(string $path, string $bytes, string $message): void {}
    public function history(string $path): array { return []; }
    public function readPublicContent(string $path): string { return $this->public[$path] ?? ''; }
    public function savePublicContent(string $path, string $bytes, string $message): void { $this->public[$path] = $bytes; }
    public function saveOperationLog(array $event): void {}
    public function listUpdateReleases(): array { return []; }
    public function readUpdateFile(string $path): string { return ''; }
}

$base = sys_get_temp_dir() . '/repository-cms-static-' . bin2hex(random_bytes(4));
mkdir($base . '/core/Config', 0700, true);
mkdir($base . '/work', 0700, true);
file_put_contents($base . '/work/.gitignore', '');
$locks = new LockManager($base . '/core/Config/cms_lock.json');
$auth = new Auth($base . '/core/Config/auth.json', $base . '/core/Config/login_state.json', $base . '/core/Config/admin_initial_state.json');
$workData = new WorkData($base . '/work', $locks);
$config = new Config('github', 'token', 'owner', 'content', 'public', 'ops', 'updates', 'main', 'updates/releases.json', 'main');
$git = new ReleaseCheckStaticGit();
$runtime = new Runtime('RepositoryCMS/Core', $base . '/core/Config', $base . '/work', $config, $git, new ServerSideLogicFrameworkClient(new ServerSideLogicFramework($auth, $locks, $workData)));
SiteSettings::save($runtime, [
    'site_name' => 'Example Site',
    'site_description' => 'Description',
    'public_url' => 'https://example.com',
    'site_language' => 'en',
    'meta_title' => 'Meta Title',
    'meta_description' => 'Meta Description',
]);
AdSlots::save($runtime, [
    'slots' => [
        ['id' => 'after_main', 'name' => 'After Main', 'position' => 'after', 'enabled' => true, 'content' => 'Safe Ad'],
    ],
]);
NavigationSettings::save($runtime, [
    'items' => [
        ['label' => 'Home', 'url' => '/', 'order' => 0, 'enabled' => true],
    ],
]);
PagesSettings::save($runtime, [
    'pages' => [
        ['title' => 'Home', 'path' => 'pages/index.md', 'order' => 0, 'published' => true],
    ],
]);
ThemeDisplaySettings::save($runtime, [
    'show_site_name' => true,
    'show_navigation' => true,
    'show_ad_slots' => true,
    'color_scope' => 'full',
]);
$report = (new StaticGenerator($runtime, new Renderer($runtime->serverSideClient)))->publishReport();
$html = $git->public['pages/index.html'] ?? '';
if (($report['succeeded'] ?? 0) !== 1 || !str_contains($html, '<html lang="en">') || !str_contains($html, '<title>Meta Title</title>') || !str_contains($html, '<meta name="description" content="Meta Description">') || !str_contains($html, '<nav class="site-nav"') || !str_contains($html, '<nav class="page-nav"') || !str_contains($html, 'data-color-scope="full"') || !str_contains($html, 'data-ad-slot="after_main"')) {
    fwrite(STDERR, "static site metadata failed\n");
    exit(1);
}
echo "static-site-metadata-ok\n";
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
    public string $updateBytes = '<?php echo "v0.20";';
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
            ['version' => 'v.0.20', 'target_version' => 'v.0.19', 'released_at' => '2026-08-04T00:00:00Z', 'php' => '8.4', 'files' => [['path' => 'Core/app.php', 'source' => 'updates/v0.20/Core/app.php', 'checksum' => hash('sha256', $this->updateBytes)]]],
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
file_put_contents($base . '/core/ServerSideLogicFramework.php', file_get_contents('ServerSideLogicFramework/ServerSideLogicFramework.php'));
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
