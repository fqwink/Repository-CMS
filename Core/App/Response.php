<?php

declare(strict_types=1);

namespace RepositoryCms\Core;

final class Response
{
    public static function html(string $title, string $body, Runtime $runtime, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        $lock = $runtime->locks->state();
        $user = $runtime->auth->user();
        $lockHtml = $lock['locked']
            ? '<div class="alert">CMS LOCKED: ' . self::escape($lock['reason']) . '</div>'
            : '';
        $nav = $user === null ? '' : '<nav><a href="?">一覧</a><a href="?action=new">作成</a><a href="?action=generate">静的生成</a><a href="?action=publish">公開</a><a href="?action=updates">アップデート</a><a href="?action=logout">ログアウト</a></nav>';

        echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . self::escape($title) . ' - Repository CMS</title>';
        echo '<style>' . self::style() . '</style></head><body>';
        echo '<header><h1>Repository CMS <span class="muted">' . self::escape(Config::VERSION) . '</span></h1>' . $nav . '</header><main>' . $lockHtml . $body . '</main></body></html>';
    }

    public static function redirect(string $to): never
    {
        header('Location: ' . $to, true, 302);
        exit;
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function style(): string
    {
        return 'body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0;background:#f6f7f9;color:#17202a}header{display:flex;align-items:center;justify-content:space-between;padding:16px 24px;background:#fff;border-bottom:1px solid #d9dee7}h1{font-size:20px;margin:0}main{max-width:1040px;margin:24px auto;padding:0 16px}nav{display:flex;gap:12px}a{color:#0b5cad;text-decoration:none}.panel{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:18px;margin-bottom:16px}.alert{background:#fff3cd;border:1px solid #ffe08a;border-radius:8px;padding:12px;margin-bottom:16px}label{display:block;margin:12px 0 6px;font-weight:600}input,textarea,select{width:100%;box-sizing:border-box;border:1px solid #c8d0dc;border-radius:6px;padding:10px;font:inherit}textarea{min-height:360px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}button,.button{display:inline-block;background:#17202a;color:#fff;border:0;border-radius:6px;padding:10px 14px;font:inherit;cursor:pointer}.button.secondary{background:#eef1f5;color:#17202a}.row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.list{width:100%;border-collapse:collapse}.list th,.list td{text-align:left;border-bottom:1px solid #edf0f4;padding:10px}.muted{color:#697586}.preview{background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:18px}';
    }
}
