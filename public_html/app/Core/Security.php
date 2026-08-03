<?php

declare(strict_types=1);

namespace RepositoryCms\Core;

final class Security
{
    private const EXTENSIONS = ['md', 'json', 'png', 'svg'];
    private const PUBLIC_EXTENSIONS = ['html', 'json', 'png', 'svg', 'css', 'js'];

    public static function validContentPath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..')) {
            return false;
        }
        if (!preg_match('/^[A-Za-z0-9_\/.-]+$/', $path)) {
            return false;
        }
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($extension, self::EXTENSIONS, true);
    }

    public static function allowedExtension(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, self::EXTENSIONS, true)) {
            throw new \InvalidArgumentException('許可されていない拡張子です。');
        }
        return $extension;
    }

    public static function validateContent(string $path, string $bytes): void
    {
        $extension = self::allowedExtension($path);
        if ($extension === 'json') {
            json_decode($bytes, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException('JSONの形式が不正です。');
            }
            return;
        }
        if ($extension === 'png' && !str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            throw new \InvalidArgumentException('PNGの形式が不正です。');
        }
        if ($extension === 'svg') {
            if (!preg_match('/<svg[\s>]/i', $bytes)) {
                throw new \InvalidArgumentException('SVGの形式が不正です。');
            }
            if (preg_match('/<script[\s>]|on[a-z]+\s*=/i', $bytes)) {
                throw new \InvalidArgumentException('SVGに許可されていないスクリプト要素があります。');
            }
        }
    }

    public static function validPublicPath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..')) {
            return false;
        }
        if (!preg_match('/^[A-Za-z0-9_\/.-]+$/', $path)) {
            return false;
        }
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($extension, self::PUBLIC_EXTENSIONS, true);
    }

    public static function csrfToken(): string
    {
        if (!isset($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['csrf'];
    }

    public static function requireCsrf(): void
    {
        $token = (string) ($_POST['csrf'] ?? '');
        if ($token === '' || !hash_equals(self::csrfToken(), $token)) {
            throw new \RuntimeException('CSRF検証に失敗しました。');
        }
    }
}
