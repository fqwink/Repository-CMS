<?php

declare(strict_types=1);

namespace RepositoryCms\Core;

final class Runtime
{
    public function __construct(
        public readonly string $coreRoot,
        public readonly string $appRoot,
        public readonly string $configRoot,
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
        $configRoot = $coreRoot . '/Config';
        $workRoot = $root . '/Work';
        self::ensureDirectory($configRoot);
        self::ensureDirectory($appRoot . '/Lang');
        self::ensureDirectory($appRoot . '/Themes');
        self::ensureDirectory($workRoot);

        $config = Config::fromEnvironment();
        $locks = new LockManager($configRoot . '/cms_lock.json');
        $workData = new WorkData($workRoot, $locks);
        $git = GitHubProvider::fromConfig($config, $locks);

        return new self(
            $coreRoot,
            $appRoot,
            $configRoot,
            $workRoot,
            $config,
            $locks,
            $workData,
            $git,
            new Auth($configRoot . '/auth.json', $configRoot . '/login_state.json'),
        );
    }

    private static function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new \RuntimeException('Directory creation failed: ' . $path);
        }
    }
}
