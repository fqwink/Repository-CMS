<?php

declare(strict_types=1);

namespace RepositoryCms\Core;

interface GitProvider
{
    public function configured(): bool;
    public function listContent(): array;
    public function readContent(string $path): string;
    public function readContentAt(string $path, string $ref): string;
    public function saveContent(string $path, string $bytes, string $message): void;
    public function history(string $path): array;
    public function readPublicContent(string $path): string;
    public function savePublicContent(string $path, string $bytes, string $message): void;
    public function saveOperationLog(array $event): void;
    public function listUpdateReleases(): array;
    public function readUpdateFile(string $path): string;
}
