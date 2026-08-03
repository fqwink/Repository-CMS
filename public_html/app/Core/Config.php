<?php

declare(strict_types=1);

namespace RepositoryCms\Core;

final class Config
{
    public const VERSION = 'v.0.1';

    public function __construct(
        public readonly string $provider,
        public readonly string $githubToken,
        public readonly string $githubOwner,
        public readonly string $contentRepository,
        public readonly string $publicRepository,
        public readonly string $opsRepository,
        public readonly string $branch,
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(
            strtolower((string) getenv('REPOSITORY_CMS_GIT_PROVIDER')),
            (string) getenv('REPOSITORY_CMS_GITHUB_TOKEN'),
            (string) getenv('REPOSITORY_CMS_GITHUB_OWNER'),
            (string) getenv('REPOSITORY_CMS_CONTENT_REPO'),
            (string) getenv('REPOSITORY_CMS_PUBLIC_REPO'),
            (string) getenv('REPOSITORY_CMS_OPS_REPO'),
            (string) (getenv('REPOSITORY_CMS_BRANCH') ?: 'main'),
        );
    }

    public function gitConfigured(): bool
    {
        return $this->provider === 'github'
            && $this->githubToken !== ''
            && $this->githubOwner !== ''
            && $this->contentRepository !== ''
            && $this->publicRepository !== ''
            && $this->opsRepository !== '';
    }
}
