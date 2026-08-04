#!/bin/sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT_DIR"

check_protected_tracked_data() {
  echo "== Protected tracked data =="
  if git ls-files Core/Data | grep -E '^Core/Data/(auth|locks)/' >/dev/null 2>&1; then
    echo "Protected runtime data is tracked under Core/Data." >&2
    git ls-files Core/Data | grep -E '^Core/Data/(auth|locks)/' >&2
    exit 1
  fi
}

check_whitespace_diff() {
  echo "== Whitespace diff =="
  git diff --check
}

run_git_checks() {
  check_protected_tracked_data
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
    exec "$DOCKER_BIN" run --rm -e RELEASE_CHECK_GIT_DONE=1 -v "$ROOT_DIR:/app" -w /app php:8.4-cli sh scripts/release-check.sh
  elif [ -x /Applications/Docker.app/Contents/Resources/bin/docker ]; then
    if command -v git >/dev/null 2>&1 && [ "${RELEASE_CHECK_GIT_DONE:-0}" != "1" ]; then
      run_git_checks
    fi
    exec /Applications/Docker.app/Contents/Resources/bin/docker run --rm -e RELEASE_CHECK_GIT_DONE=1 -v "$ROOT_DIR:/app" -w /app php:8.4-cli sh scripts/release-check.sh
  else
    echo "php or docker is required." >&2
    exit 1
  fi
fi

echo "== PHP syntax =="
for file in Core/app.php Core/App/*.php; do
  "$PHP_BIN" -l "$file" >/dev/null
done

echo "== Core/App file count =="
core_app_count=$(find Core/App -maxdepth 1 -name '*.php' | wc -l | tr -d ' ')
if [ "$core_app_count" -gt 23 ]; then
  echo "Core/App PHP file count exceeds 23: $core_app_count" >&2
  exit 1
fi

echo "== Work data =="
work_entries=$(find Work -mindepth 1 -maxdepth 2 -print | sort)
if [ "$work_entries" != "Work/.gitignore" ]; then
  echo "Work contains unexpected files:" >&2
  echo "$work_entries" >&2
  exit 1
fi

echo "== Core work data prohibition =="
if find Core -maxdepth 2 -type d \( -iname '*work*' -o -iname '*cache*' -o -iname '*tmp*' -o -iname '*temp*' \) -print | grep . >/dev/null 2>&1; then
  echo "Core contains work/cache/tmp-like paths." >&2
  find Core -maxdepth 2 -type d \( -iname '*work*' -o -iname '*cache*' -o -iname '*tmp*' -o -iname '*temp*' \) -print >&2
  exit 1
fi

echo "== Protected tracked data =="
if [ "${RELEASE_CHECK_GIT_DONE:-0}" = "1" ]; then
  echo "already checked before Docker execution."
elif command -v git >/dev/null 2>&1; then
  check_protected_tracked_data
else
  echo "git not found; skipped tracked data check."
fi

echo "== Future update actions not exposed in v0.7 =="
if grep -R -n -E 'update_apply|選択して開始|function applyUpdate|validateUpdateRelease|writeUpdateTarget|assertWorkClean' Core Docs AGENTS.md >/dev/null 2>&1; then
  echo "v0.8+ update execution code or UI is exposed in v0.7." >&2
  grep -R -n -E 'update_apply|選択して開始|function applyUpdate|validateUpdateRelease|writeUpdateTarget|assertWorkClean' Core Docs AGENTS.md >&2
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
require 'Core/App/Bootstrap.php';

use RepositoryCms\Core\Auth;
use RepositoryCms\Core\Config;
use RepositoryCms\Core\ContentManager;
use RepositoryCms\Core\GitProvider;
use RepositoryCms\Core\LockManager;
use RepositoryCms\Core\Runtime;
use RepositoryCms\Core\WorkData;

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
    mkdir($base . '/data/auth', 0700, true);
    mkdir($base . '/data/locks', 0700, true);
    mkdir($base . '/work', 0700, true);
    file_put_contents($base . '/work/.gitignore', '');
    file_put_contents($base . '/data/auth/admin.json', json_encode([
        'username' => 'admin',
        'password_hash' => password_hash('Password123456', PASSWORD_DEFAULT),
    ]));
    $config = new Config('github', 'token', 'owner', 'content', 'public', 'ops', 'updates', 'main', 'updates/releases.json', 'main');
    $locks = new LockManager($base . '/data/locks');
    $runtime = new Runtime('/app/Core', '/app/Core/App', $base . '/data', $base . '/work', $config, $locks, new WorkData($base . '/work', $locks), $git, new Auth($base . '/data/auth/admin.json', $base . '/data/auth/login_state.json'));
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

echo "== v0.7 update list =="
"$PHP_BIN" <<'PHP'
<?php
require 'Core/App/Bootstrap.php';

use RepositoryCms\Core\App;
use RepositoryCms\Core\Auth;
use RepositoryCms\Core\Config;
use RepositoryCms\Core\GitProvider;
use RepositoryCms\Core\LockManager;
use RepositoryCms\Core\Runtime;
use RepositoryCms\Core\WorkData;

final class ReleaseCheckUpdateGit implements GitProvider
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
    public function listUpdateReleases(): array
    {
        return [
            ['version' => 'v.0.8', 'target_version' => 'v.0.7', 'released_at' => '2026-08-04T00:00:00Z', 'php' => '8.4', 'files' => [['path' => 'Core/App/App.php', 'checksum' => str_repeat('a', 64)]]],
            ['version' => 'v.0.7', 'target_version' => 'v.0.6', 'released_at' => '2026-08-04T00:00:00Z', 'php' => '8.4', 'files' => []],
            ['version' => 'broken'],
        ];
    }
    public function readUpdateFile(string $path): string { return ''; }
}

$base = sys_get_temp_dir() . '/repository-cms-release-update-' . bin2hex(random_bytes(4));
mkdir($base . '/data/auth', 0700, true);
mkdir($base . '/data/locks', 0700, true);
mkdir($base . '/work', 0700, true);
file_put_contents($base . '/data/auth/admin.json', json_encode([
    'username' => 'admin',
    'password_hash' => password_hash('Password123456', PASSWORD_DEFAULT),
]));
$_SESSION['admin'] = 'admin';
$_SESSION['last_seen_at'] = time();
$config = new Config('github', 'token', 'owner', 'content', 'public', 'ops', 'updates', 'main', 'updates/releases.json', 'main');
$locks = new LockManager($base . '/data/locks');
$runtime = new Runtime('/app/Core', '/app/Core/App', $base . '/data', $base . '/work', $config, $locks, new WorkData($base . '/work', $locks), new ReleaseCheckUpdateGit(), new Auth($base . '/data/auth/admin.json', $base . '/data/auth/login_state.json'));
$app = new App($runtime);
$method = new ReflectionMethod(App::class, 'updates');
$method->setAccessible(true);
ob_start();
$method->invoke($app);
$html = ob_get_clean();
if (!str_contains($html, 'v.0.8') || str_contains($html, 'update_apply') || !str_contains($html, '通知のみ')) {
    fwrite(STDERR, "v0.7 update list failed\n");
    exit(1);
}
echo "update-list-ok\n";
PHP

echo "release-check-ok"
