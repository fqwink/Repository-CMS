<?php

declare(strict_types=1);

namespace RepositoryCms\Core;

final class Runtime
{
    public function __construct(
        public readonly string $publicRoot,
        public readonly string $appRoot,
        public readonly string $dataRoot,
        public readonly Config $config,
        public readonly LockManager $locks,
        public readonly WorkData $workData,
        public readonly GitProvider $git,
        public readonly Auth $auth,
    ) {
    }

    public static function create(string $publicRoot): self
    {
        $appRoot = $publicRoot . '/app';
        $dataRoot = $publicRoot . '/data';
        self::ensureDirectory($dataRoot);
        self::ensureDirectory($dataRoot . '/auth');
        self::ensureDirectory($dataRoot . '/work');
        self::ensureDirectory($dataRoot . '/locks');

        $config = Config::fromEnvironment();
        $locks = new LockManager($dataRoot . '/locks');
        $workData = new WorkData($dataRoot . '/work', $locks);
        $git = GitHubProvider::fromConfig($config, $locks);

        return new self(
            $publicRoot,
            $appRoot,
            $dataRoot,
            $config,
            $locks,
            $workData,
            $git,
            new Auth($dataRoot . '/auth/admin.json'),
        );
    }

    private static function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new \RuntimeException('Directory creation failed: ' . $path);
        }
    }
}
