#!/bin/sh
set -eu

TOOL_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
REPO_ROOT=$(CDPATH= cd -- "$TOOL_DIR/../.." && pwd)
SOURCE_DIR="$REPO_ROOT/Source"
CMS_DIR="$SOURCE_DIR/RepositoryCMS"
CORE_DIR="$CMS_DIR/Core"
FRAMEWORK_DIR="$SOURCE_DIR/ServerSideLogicFramework"
ADMIN_DIR="$SOURCE_DIR/AdminFrontend"
STATIC_DIR="$SOURCE_DIR/StaticGenerator"
EDITOR_DIR="$SOURCE_DIR/EditorSystem"
RELEASE_DIR="$REPO_ROOT/Release"
RELEASE_CMS_DIR="$RELEASE_DIR/RepositoryCMS"

cd "$REPO_ROOT"

check_whitespace_diff() {
  echo "== Whitespace diff =="
  git diff --check
}

check_protected_tracked_config() {
  echo "== Protected tracked config =="
  if git ls-files "Source/RepositoryCMS/Core/Config" | grep -E '^Source/RepositoryCMS/Core/Config/.+\.json$' >/dev/null 2>&1; then
    echo "Protected runtime config is tracked under Source/RepositoryCMS/Core/Config." >&2
    git ls-files "Source/RepositoryCMS/Core/Config" | grep -E '^Source/RepositoryCMS/Core/Config/.+\.json$' >&2
    exit 1
  fi
}

run_git_checks() {
  if command -v git >/dev/null 2>&1; then
    check_protected_tracked_config
    check_whitespace_diff
  fi
}

DOCKER_BIN=${DOCKER_BIN:-}
if [ -z "${PHP_BIN:-}" ]; then
  if command -v php >/dev/null 2>&1; then
    PHP_BIN=php
  elif [ -n "$DOCKER_BIN" ] && [ -x "$DOCKER_BIN" ]; then
    if [ "${RELEASE_CHECK_GIT_DONE:-0}" != "1" ]; then
      run_git_checks
    fi
    exec "$DOCKER_BIN" run --rm -e RELEASE_CHECK_GIT_DONE=1 -v "$REPO_ROOT:/app" -w /app php:8.4-cli sh Tools/release-check/release-check.sh
  elif [ -x /Applications/Docker.app/Contents/Resources/bin/docker ]; then
    if [ "${RELEASE_CHECK_GIT_DONE:-0}" != "1" ]; then
      run_git_checks
    fi
    exec /Applications/Docker.app/Contents/Resources/bin/docker run --rm -e RELEASE_CHECK_GIT_DONE=1 -v "$REPO_ROOT:/app" -w /app php:8.4-cli sh Tools/release-check/release-check.sh
  else
    echo "php or docker is required." >&2
    exit 1
  fi
fi

echo "== Source layout =="
root_dirs=$(find . -mindepth 1 -maxdepth 1 -type d ! -name '.git' -print | sed 's#^\./##' | sort)
if [ "$root_dirs" != "Docs
Release
Source
Tools
Work" ]; then
  echo "Repository root directories must be Docs, Release, Source, Tools and Work:" >&2
  echo "$root_dirs" >&2
  exit 1
fi
for dir in "$CMS_DIR" "$FRAMEWORK_DIR" "$ADMIN_DIR" "$STATIC_DIR" "$EDITOR_DIR" "$RELEASE_CMS_DIR" "$RELEASE_DIR/Packages" "$RELEASE_DIR/Manifests" "$RELEASE_DIR/Checksums" "$REPO_ROOT/Tools/release-check"; do
  if [ ! -d "$dir" ]; then
    echo "Required directory is missing: $dir" >&2
    exit 1
  fi
done
if [ ! -f Docs/Document_Source ] || ! grep -F "future canonical documentation repository is" Docs/Document_Source >/dev/null 2>&1; then
  echo "Legacy Docs must identify Adlaire-Docs as the canonical documentation source." >&2
  exit 1
fi

echo "== Documentation source policy =="
for marker in "Adlaire-Docs" "Adlaire-Ecosystem" "Source/" "Release/"; do
  if ! grep -F "$marker" AGENTS.md README.md >/dev/null 2>&1; then
    echo "Documentation/source policy marker is missing: $marker" >&2
    exit 1
  fi
done

echo "== PHP syntax =="
for file in "$CORE_DIR"/app.php "$CORE_DIR"/ServerSideLogicFramework.php "$CORE_DIR"/ServerSideLogicFrameworkClient.php "$FRAMEWORK_DIR"/*.php; do
  "$PHP_BIN" -l "$file" >/dev/null
done

echo "== ServerSideLogicFramework source and copies =="
framework_php_count=$(find "$FRAMEWORK_DIR" -maxdepth 1 -name '*.php' | wc -l | tr -d ' ')
if [ "$framework_php_count" -ne 2 ]; then
  echo "ServerSideLogicFramework must contain exactly two PHP files." >&2
  find "$FRAMEWORK_DIR" -maxdepth 1 -name '*.php' -print >&2
  exit 1
fi
if ! cmp -s "$FRAMEWORK_DIR/ServerSideLogicFramework.php" "$CORE_DIR/ServerSideLogicFramework.php"; then
  echo "Core ServerSideLogicFramework.php differs from source." >&2
  exit 1
fi
if ! cmp -s "$FRAMEWORK_DIR/ServerSideLogicFrameworkClient.php" "$CORE_DIR/ServerSideLogicFrameworkClient.php"; then
  echo "Core ServerSideLogicFrameworkClient.php differs from source." >&2
  exit 1
fi

echo "== Core layout =="
core_dirs=$(find "$CORE_DIR" -mindepth 1 -maxdepth 1 -type d -print | sed "s#^$CORE_DIR/##" | sort)
if [ "$core_dirs" != "Config
Lang
Themes" ]; then
  echo "Core direct directories must be Config, Lang and Themes:" >&2
  echo "$core_dirs" >&2
  exit 1
fi
core_file_count=$(find "$CORE_DIR" -maxdepth 1 -type f | wc -l | tr -d ' ')
if [ "$core_file_count" -gt 7 ]; then
  echo "Core direct file count must be 7 or fewer: $core_file_count" >&2
  find "$CORE_DIR" -maxdepth 1 -type f -print >&2
  exit 1
fi
core_php_count=$(find "$CORE_DIR" -maxdepth 1 -name '*.php' | wc -l | tr -d ' ')
if [ "$core_php_count" -ne 3 ]; then
  echo "Core PHP file count must be exactly 3: $core_php_count" >&2
  find "$CORE_DIR" -maxdepth 1 -name '*.php' -print >&2
  exit 1
fi
if find "$CORE_DIR/Config" -mindepth 1 -type d -print | grep . >/dev/null 2>&1; then
  echo "Core/Config must not contain subdirectories." >&2
  exit 1
fi
for dir in "$CORE_DIR/App" "$CORE_DIR/Data" "$CMS_DIR/Modules" "$CMS_DIR/Docs" "$FRAMEWORK_DIR/Docs"; do
  if [ -e "$dir" ]; then
    echo "Prohibited or frozen directory exists: $dir" >&2
    exit 1
  fi
done

echo "== Developer managed resources =="
for file in "$CORE_DIR/Lang/ja.json" "$CORE_DIR/Lang/en.json" "$CORE_DIR/Themes/standard.json" "$CORE_DIR/Themes/blog.json" "$CORE_DIR/Themes/media.json"; do
  if [ ! -f "$file" ]; then
    echo "Required developer managed resource is missing: $file" >&2
    exit 1
  fi
done

echo "== TypeScript source policy =="
if [ ! -f "$ADMIN_DIR/admin-frontend.ts" ] || [ ! -f "$STATIC_DIR/static-generator.ts" ]; then
  echo "TypeScript source canonical files are missing." >&2
  exit 1
fi
for dir in "$ADMIN_DIR" "$STATIC_DIR"; do
  if find "$dir" -maxdepth 1 \( -name 'package.json' -o -name 'package-lock.json' -o -name 'deno.json' -o -name 'deno.lock' -o -name 'tsconfig.json' \) -print | grep . >/dev/null 2>&1; then
    echo "TypeScript source area must not require Node.js, Deno, npm or external build configuration: $dir" >&2
    exit 1
  fi
done
if find "$CORE_DIR" -maxdepth 1 \( -name '*.ts' -o -name '*.d.ts' -o -name 'package.json' -o -name 'package-lock.json' -o -name 'deno.json' -o -name 'deno.lock' -o -name 'tsconfig.json' \) -print | grep . >/dev/null 2>&1; then
  echo "Core must not contain TypeScript source or build/dependency files." >&2
  exit 1
fi
for js in "$CORE_DIR/admin-frontend.js" "$CORE_DIR/static-generator.js"; do
  if grep -n -E 'fetch\(|XMLHttpRequest|localStorage|sessionStorage|document\.cookie|eval\(|Function\(|navigator\.sendBeacon|import\(' "$js" >/dev/null 2>&1; then
    echo "Generated JavaScript must remain local helper only: $js" >&2
    exit 1
  fi
done

echo "== Work data =="
work_entries=$(find "$CMS_DIR/Work" -mindepth 1 -maxdepth 2 -print | sed "s#^$CMS_DIR/##" | sort)
if [ "$work_entries" != "Work/.gitignore" ]; then
  echo "RepositoryCMS Work contains unexpected files:" >&2
  echo "$work_entries" >&2
  exit 1
fi

echo "== Release artifact policy =="
for file in \
  "Core/app.php" \
  "Core/.htaccess" \
  "Core/admin-frontend.js" \
  "Core/static-generator.js" \
  "Core/ServerSideLogicFramework.php" \
  "Core/ServerSideLogicFrameworkClient.php" \
  "Core/Lang/ja.json" \
  "Core/Lang/en.json" \
  "Core/Themes/standard.json" \
  "Core/Themes/blog.json" \
  "Core/Themes/media.json"; do
  if [ ! -f "$RELEASE_CMS_DIR/$file" ]; then
    echo "Release RepositoryCMS artifact is missing required file: $file" >&2
    exit 1
  fi
done
for path in "Core/Config" "Work" "Docs" "Source"; do
  if [ -e "$RELEASE_CMS_DIR/$path" ]; then
    echo "Release RepositoryCMS artifact contains prohibited path: $path" >&2
    exit 1
  fi
done
if find "$RELEASE_CMS_DIR" -type f -name '*.ts' -print | grep . >/dev/null 2>&1; then
  echo "Release RepositoryCMS artifact must not contain TypeScript source files." >&2
  exit 1
fi
for file in "$RELEASE_DIR/Manifests/repository-cms-v0.25.json" "$RELEASE_DIR/Checksums/SHA256SUMS" "$RELEASE_DIR/Packages/repository-cms-v0.25.zip"; do
  if [ ! -f "$file" ]; then
    echo "Release file is missing: $file" >&2
    exit 1
  fi
done

echo "== Runtime client integration =="
"$PHP_BIN" <<'PHP'
<?php
define('REPOSITORY_CMS_NO_RUN', true);
require 'Source/RepositoryCMS/Core/app.php';

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

foreach ($previous as $name => $value) {
    if ($value === false) {
        putenv($name);
    } else {
        putenv($name . '=' . $value);
    }
}

echo "runtime-client-ok\n";
PHP

if [ "${RELEASE_CHECK_GIT_DONE:-0}" = "1" ]; then
  echo "== Git checks =="
  echo "already checked before Docker execution."
else
  run_git_checks
fi

echo "release-check-ok"
