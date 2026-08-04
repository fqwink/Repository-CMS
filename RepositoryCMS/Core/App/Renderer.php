<?php

declare(strict_types=1);

namespace RepositoryCms\Core;

use ServerSideLogicFramework\ServerSideLogicFramework;

final class Renderer
{
    public function __construct(private readonly ServerSideLogicFramework $serverSide)
    {
    }

    public function preview(string $path, string $bytes): string
    {
        $this->serverSide->validateContent($path, $bytes);
        return match ($this->serverSide->allowedExtension($path)) {
            'md' => $this->markdown($bytes),
            'json' => '<pre>' . Response::escape($this->json($bytes)) . '</pre>',
            'svg' => '<pre>' . Response::escape($bytes) . '</pre>',
            'png' => '<img alt="" style="max-width:100%;height:auto" src="data:image/png;base64,' . base64_encode($bytes) . '">',
            default => '',
        };
    }

    public function markdown(string $markdown): string
    {
        $html = [];
        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            $line = rtrim($line);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '# ')) {
                $html[] = '<h1>' . Response::escape(substr($line, 2)) . '</h1>';
            } elseif (str_starts_with($line, '## ')) {
                $html[] = '<h2>' . Response::escape(substr($line, 3)) . '</h2>';
            } elseif (str_starts_with($line, '- ')) {
                $html[] = '<p>&bull; ' . Response::escape(substr($line, 2)) . '</p>';
            } else {
                $html[] = '<p>' . Response::escape($line) . '</p>';
            }
        }
        return implode("\n", $html);
    }

    private function json(string $json): string
    {
        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $json;
        }
        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $json;
    }
}
