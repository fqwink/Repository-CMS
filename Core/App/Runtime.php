<?php

declare(strict_types=1);

namespace RepositoryCms\Core;

final class Runtime
{
    public function __construct(
        public readonly string $coreRoot,
        public readonly string $appRoot,
        public readonly string $dataRoot,
        public readonly string $workRoot,
        public readonly Config $config,
        public readonly LockManager $locks,
        public readonly WorkData $workData,
        public readonly GitProvider $git,
        public readonly Auth $auth,
    ) {
    }

    public static function create(string $coreRoot): self
    {
        $root = dirname($coreRoot);
        $appRoot = $coreRoot . '/App';
        $dataRoot = $coreRoot . '/Data';
        $workRoot = $root . '/Work';
        self::ensureDirectory($coreRoot . '/Config');
        self::ensureDirectory($dataRoot);
        self::ensureDirectory($dataRoot . '/auth');
        self::ensureDirectory($dataRoot . '/locks');
        self::ensureDirectory($dataRoot . '/theme');
        self::ensureDirectory($workRoot);

        $config = Config::fromEnvironment();
        $locks = new LockManager($dataRoot . '/locks');
        $workData = new WorkData($workRoot, $locks);
        $git = GitHubProvider::fromConfig($config, $locks);

        return new self(
            $coreRoot,
            $appRoot,
            $dataRoot,
            $workRoot,
            $config,
            $locks,
            $workData,
            $git,
            new Auth($dataRoot . '/auth/admin.json', $dataRoot . '/auth/login_state.json'),
        );
    }

    private static function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new \RuntimeException('Directory creation failed: ' . $path);
        }
    }
}
