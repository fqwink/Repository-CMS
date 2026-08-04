<?php

declare(strict_types=1);

namespace RepositoryCms\Core;

final class UpdateValidator
{
    public function __construct(private readonly Runtime $runtime)
    {
    }

    public function validate(array $release): array
    {
        $checks = [];
        $version = (string) ($release['version'] ?? '');
        $targetVersion = (string) ($release['target_version'] ?? '');
        $files = $release['files'] ?? null;

        $this->addCheck($checks, '現在バージョン', Config::VERSION !== '', Config::VERSION);
        $this->addCheck($checks, '更新先バージョン', $this->newerThanCurrent($version), $version === '' ? 'バージョンが不正です。' : $version);
        $this->addCheck($checks, '対象バージョン', $targetVersion === Config::VERSION, $targetVersion === '' ? '対象バージョンが不正です。' : $targetVersion);
        $this->addCheck($checks, '必須PHPバージョン', $this->phpAllowed((string) ($release['php'] ?? '')), PHP_VERSION);
        $this->addCheck($checks, 'ファイル一覧', is_array($files) && count($files) > 0, is_array($files) ? count($files) . ' files' : 'ファイル一覧が不正です。');
        $this->addCheck($checks, 'Work保全状態', $this->workClean(), 'Work/ は作業データなし');

        if (is_array($files)) {
            $this->validateFiles($files, $checks);
        }

        $failed = count(array_filter($checks, static fn (array $check): bool => $check['ok'] !== true));
        return [
            'version' => $version,
            'target_version' => $targetVersion,
            'valid' => $failed === 0,
            'passed' => count($checks) - $failed,
            'failed' => $failed,
            'checks' => $checks,
        ];
    }

    private function validateFiles(array $files, array &$checks): void
    {
        $phpTargets = $this->currentCoreAppPhpFiles();
        foreach ($files as $index => $file) {
            if (!is_array($file)) {
                $this->addCheck($checks, '更新ファイル #' . ($index + 1), false, 'ファイル定義が不正です。');
                continue;
            }
            $path = (string) ($file['path'] ?? '');
            $source = (string) ($file['source'] ?? '');
            $checksum = (string) ($file['checksum'] ?? '');

            $this->addCheck($checks, '対象パス ' . $path, $this->allowedCoreUpdatePath($path), $path === '' ? '対象パスが空です。' : $path);
            $this->addCheck($checks, '取得元 ' . $path, Security::validRepositoryPath($source), $source === '' ? '取得元が空です。' : $source);
            $this->addCheck($checks, 'チェックサム形式 ' . $path, preg_match('/^[a-f0-9]{64}$/', $checksum) === 1, $checksum === '' ? 'チェックサムが空です。' : $checksum);

            if ($this->allowedCoreUpdatePath($path) && str_starts_with($path, 'Core/App/') && str_ends_with($path, '.php') && substr_count($path, '/') === 2) {
                $phpTargets[$path] = true;
            }

            if ($source === '' || !Security::validRepositoryPath($source) || preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1) {
                continue;
            }
            try {
                $bytes = $this->runtime->git->readUpdateFile($source);
                $actual = hash('sha256', $bytes);
                $this->addCheck($checks, 'ファイルチェックサム ' . $path, hash_equals($checksum, $actual), $path);
            } catch (\Throwable $error) {
                $this->addCheck($checks, 'ファイル取得 ' . $path, false, $error->getMessage());
            }
        }
        $this->addCheck($checks, 'Core/App PHPファイル数', count($phpTargets) <= 23, count($phpTargets) . ' files');
    }

    private function allowedCoreUpdatePath(string $path): bool
    {
        if (!Security::validRepositoryPath($path)) {
            return false;
        }
        if ($path === 'Core/app.php' || $path === 'Core/.htaccess') {
            return true;
        }
        if (!str_starts_with($path, 'Core/App/')) {
            return false;
        }
        if ($path === 'Core/App/' || str_ends_with($path, '/')) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if (in_array(strtolower($segment), ['work', 'cache', 'tmp', 'temp'], true)) {
                return false;
            }
        }
        return !str_starts_with($path, 'Core/App/../')
            && !str_starts_with($path, 'Core/Config/')
            && !str_starts_with($path, 'Core/Data/');
    }

    private function currentCoreAppPhpFiles(): array
    {
        $files = [];
        foreach (glob($this->runtime->appRoot . '/*.php') ?: [] as $file) {
            $files['Core/App/' . basename($file)] = true;
        }
        return $files;
    }

    private function workClean(): bool
    {
        $entries = array_values(array_diff(scandir($this->runtime->workRoot) ?: [], ['.', '..']));
        return $entries === ['.gitignore'];
    }

    private function phpAllowed(string $required): bool
    {
        if ($required === '') {
            return false;
        }
        return version_compare(PHP_VERSION, $required, '>=');
    }

    private function newerThanCurrent(string $version): bool
    {
        if ($version === '') {
            return false;
        }
        return version_compare($this->versionNumber($version), $this->versionNumber(Config::VERSION), '>');
    }

    private function versionNumber(string $version): string
    {
        return ltrim($version, 'v.');
    }

    private function addCheck(array &$checks, string $name, bool $ok, string $message): void
    {
        $checks[] = [
            'name' => $name,
            'ok' => $ok,
            'message' => $message,
        ];
    }
}
