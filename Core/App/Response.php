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
        $nav = '';
        if ($user !== null) {
            $admin = $runtime->auth->role() === 'admin';
            $nav = '<nav><a href="?">ダッシュボード</a><a href="?action=new">作成</a><a href="?action=generate">静的生成</a>';
            if ($admin) {
                $nav .= '<a href="?action=publish">公開</a><a href="?action=themes">テーマ</a><a href="?action=updates">アップデート</a><a href="?action=users">ユーザー</a>';
            }
            $nav .= '<a href="?action=logout">ログアウト</a></nav>';
        }

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
        return ':root{--primary:#00a968;--secondary:#3498db;--accent:#40AAEF;--surface:#ecf0f1;--border:#ECEEF1;--support:#58BE89;--ink:#17202a;--muted:#697586;--danger:#b42318;--warning:#ad6800}body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0;background:var(--surface);color:var(--ink)}header{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px 24px;background:#fff;border-bottom:1px solid var(--border)}h1{font-size:20px;margin:0}h2{margin:0 0 12px}main{max-width:1120px;margin:24px auto;padding:0 16px}nav{display:flex;gap:8px;flex-wrap:wrap}nav a{border:1px solid var(--border);border-radius:6px;padding:8px 10px;background:#fff}a{color:var(--secondary);text-decoration:none}.page-head{margin-bottom:18px}.page-head h2{font-size:24px}.page-head p{color:var(--muted);margin:6px 0 0}.panel{background:#fff;border:1px solid var(--border);border-radius:8px;padding:18px;margin-bottom:16px}.alert{background:#fff3cd;border:1px solid #ffe08a;border-radius:8px;padding:12px;margin-bottom:16px}.notice{background:#eef9ff;border:1px solid var(--accent);border-radius:8px;padding:12px;margin-bottom:16px}.dashboard-grid,.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:16px}.status-card,.summary-grid div{background:#fff;border:1px solid var(--border);border-left:4px solid var(--secondary);border-radius:8px;padding:14px}.status-card span,.summary-grid span{display:block;color:var(--muted);font-size:13px;margin-bottom:8px}.status-card strong,.summary-grid strong{font-size:18px}.tone-ok{border-left-color:var(--primary)}.tone-info{border-left-color:var(--secondary)}.tone-warn{border-left-color:var(--warning)}.tone-danger{border-left-color:var(--danger)}.section-title{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}.section-title h2{margin:0}.badge{display:inline-block;background:#e9f8f1;color:#067647;border:1px solid #b7e4cf;border-radius:999px;padding:4px 8px;font-size:12px}.action-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}.action-tile{display:block;border:1px solid var(--border);border-radius:8px;padding:14px;background:#fff}.action-tile strong{display:block;color:var(--ink);margin-bottom:6px}.action-tile span{display:block;color:var(--muted);font-size:14px}.action-tile:hover{border-color:var(--primary)}.action-tile.disabled{background:#f7f8fa;opacity:.65}.theme-option{display:grid;grid-template-columns:auto 1fr 24px 24px 24px;align-items:center;gap:12px;border:1px solid var(--border);border-radius:8px;padding:12px;margin:12px 0}.theme-option em{display:block;color:var(--muted);font-style:normal;font-size:13px}.theme-option i{display:block;width:24px;height:24px;border:1px solid var(--border);border-radius:999px}label:not(.theme-option){display:block;margin:12px 0 6px;font-weight:600}input,textarea,select{width:100%;box-sizing:border-box;border:1px solid #c8d0dc;border-radius:6px;padding:10px;font:inherit}.theme-option input{width:auto}textarea{min-height:360px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}button,.button{display:inline-block;background:var(--primary);color:#fff;border:0;border-radius:6px;padding:10px 14px;font:inherit;cursor:pointer}.button.secondary{background:#eef1f5;color:var(--ink)}.row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.list{width:100%;border-collapse:collapse}.list th,.list td{text-align:left;border-bottom:1px solid var(--border);padding:10px}.table-actions{display:flex;gap:10px;flex-wrap:wrap}.muted{color:var(--muted)}code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}.preview{background:#fff;border:1px solid var(--border);border-radius:8px;padding:18px}@media(max-width:720px){header{align-items:flex-start;flex-direction:column}nav a{font-size:14px}.section-title{align-items:flex-start;flex-direction:column}.list{display:block;overflow-x:auto}.theme-option{grid-template-columns:auto 1fr}}';
    }
}
