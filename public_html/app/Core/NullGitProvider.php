<?php

declare(strict_types=1);

namespace RepositoryCms\Core;

final class NullGitProvider implements GitProvider
{
    public function configured(): bool
    {
        return false;
    }

    public function listContent(): array
    {
        return [];
    }

    public function readContent(string $path): string
    {
        throw new \RuntimeException('Gitプロバイダーが未設定です。');
    }

    public function readContentAt(string $path, string $ref): string
    {
        throw new \RuntimeException('Gitプロバイダーが未設定です。');
    }

    public function saveContent(string $path, string $bytes, string $message): void
    {
        throw new \RuntimeException('Gitプロバイダーが未設定です。');
    }

    public function history(string $path): array
    {
        return [];
    }

    public function readPublicContent(string $path): string
    {
        throw new \RuntimeException('Gitプロバイダーが未設定です。');
    }

    public function savePublicContent(string $path, string $bytes, string $message): void
    {
        throw new \RuntimeException('Gitプロバイダーが未設定です。');
    }

    public function saveOperationLog(array $event): void
    {
        throw new \RuntimeException('Gitプロバイダーが未設定です。');
    }
}
