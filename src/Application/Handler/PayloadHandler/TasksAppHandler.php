<?php

declare(strict_types=1);

namespace Semitexa\Tasks\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\CspNonce;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Ssr\Application\Service\Asset\AssetManager;
use Semitexa\Os\Application\Service\OsPreferences;
use Semitexa\Tasks\Application\Payload\Request\TasksAppPayload;

/**
 * Renders the Tasks app: a live task list (status, progress, deadline, start)
 * with create / start / complete / delete, styled to the Semitexa OS palette.
 * Polls /os/app/tasks/list so background-tick progress updates on its own.
 */
#[AsPayloadHandler(payload: TasksAppPayload::class, resource: ResourceResponse::class)]
final class TasksAppHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected OsPreferences $prefs;

    public function handle(TasksAppPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Tasks</title>
<style>
  :root { --bg:#0a1a2f; --panel:#0f2136; --line:rgba(var(--line-rgb),.16); --text:#d6e6ff; --dim:#6f8bb0; --accent:#37b7ff; }
  * { box-sizing: border-box; }
  html,body { margin:0; height:100%; background:var(--bg); color:var(--text); font:400 15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif; }
  .wrap { max-width:820px; margin:0 auto; padding:22px 22px 40px; }
  h1 { font-size:22px; font-weight:700; margin:0; letter-spacing:-.01em; }
  .sub { color:var(--dim); font-size:13px; margin-top:2px; }
  .new { display:flex; gap:8px; align-items:center; margin:18px 0 10px; flex-wrap:wrap; }
  .new input[type=text] { flex:1; min-width:200px; background:var(--panel); border:1px solid var(--line); border-radius:10px; padding:10px 14px; color:var(--text); font:inherit; outline:none; }
  .new input[type=text]:focus { border-color:var(--accent); }
  .new input[type=number] { width:78px; background:var(--panel); border:1px solid var(--line); border-radius:10px; padding:10px; color:var(--text); font:inherit; outline:none; }
  .new label { display:inline-flex; align-items:center; gap:6px; color:var(--dim); font-size:13px; cursor:pointer; user-select:none; }
  .btn { border:none; border-radius:10px; padding:10px 16px; font:600 14px/1 inherit; cursor:pointer; background:rgba(var(--accent-rgb),.18); color:var(--accent); }
  .btn:hover { background:rgba(var(--accent-rgb),.3); }
  .btn.mini { padding:6px 10px; font-size:12px; background:rgba(var(--line-rgb),.12); color:var(--text3); }
  .btn.mini:hover { background:rgba(var(--line-rgb),.22); }
  .btn.go { background:rgba(94,234,212,.16); color:var(--ok); }
  .btn.x { background:transparent; color:var(--dim); padding:6px 8px; }
  .btn.x:hover { color:#e0655f; }
  ul { list-style:none; margin:14px 0 0; padding:0; display:flex; flex-direction:column; gap:10px; }
  li { background:var(--panel); border:1px solid var(--line); border-radius:14px; padding:14px 16px; }
  li.done { opacity:.62; }
  .row { display:flex; align-items:center; gap:12px; }
  .title { flex:1; font-weight:500; }
  li.done .title { text-decoration:line-through; }
  .pill { font-size:11px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; padding:3px 9px; border-radius:999px; white-space:nowrap; }
  .pill.todo { background:rgba(var(--line-rgb),.16); color:var(--text3); }
  .pill.in_progress { background:rgba(var(--accent-rgb),.16); color:var(--accent); }
  .pill.blocked { background:rgba(245,196,81,.16); color:#f5c451; }
  .pill.done { background:rgba(94,234,212,.16); color:var(--ok); }
  .pill.cancelled { background:rgba(var(--line-rgb),.1); color:var(--dim); }
  .auto { font-size:11px; color:var(--accent); border:1px solid rgba(var(--accent-rgb),.35); border-radius:999px; padding:2px 7px; }
  .bar { height:6px; border-radius:999px; background:rgba(var(--line-rgb),.14); margin-top:10px; overflow:hidden; }
  .bar > i { display:block; height:100%; background:var(--accent); border-radius:999px; transition:width .4s; }
  .meta { display:flex; gap:14px; margin-top:8px; color:var(--dim); font-size:12px; flex-wrap:wrap; }
  .meta .over { color:#e0655f; }
  .acts { display:flex; gap:6px; }
  .empty { color:var(--dim); text-align:center; padding:40px 10px; }
  :root{--accent-rgb:55,183,255;--line-rgb:148,163,184;--text3:#a8b8d4;--ok:#5eead4}
  :root[data-mode=light]{color-scheme:light;--bg:#f4f7fb;--panel:#ffffff;--line:rgba(100,116,139,.25);--text:#243447;
    --dim:#5b6c82;--accent:#1e7fb8;--accent-rgb:30,127,184;--line-rgb:100,116,139;--text3:#3b4c61;--ok:#0e8a72}
</style><script src="__THEME_SYNC_JS__"></script>
</head>
<body><div class="wrap">
  <h1>Tasks</h1>
  <div class="sub" id="sub">—</div>
  <div class="new">
    <input type="text" id="title" placeholder="Add a task…" autocomplete="off">
    <label><input type="checkbox" id="auto"> automated</label>
    <input type="number" id="eta" placeholder="sec" min="1" title="Auto-complete after N seconds" style="display:none">
    <button class="btn" id="add">Add</button>
  </div>
  <ul id="list" data-aname="%ANAME%"></ul>
</div>
<script src="__TASKS_APP_JS__"></script>
</body></html>
HTML;

        // Nowdoc — the empty-state copy names the assistant, and the operator
        // may have renamed her, so the one token is filled in here.
        $html = str_replace('%ANAME%', htmlspecialchars($this->prefs->assistantName(), ENT_QUOTES), $html);

        // The page's scripts are static assets (fingerprinted, cached immutable);
        // nothing executable is left inline.
        $html = strtr($html, [
            '__THEME_SYNC_JS__' => AssetManager::getUrl('js/app-theme-sync.js', 'os'),
            '__TASKS_APP_JS__' => AssetManager::getUrl('js/tasks-app.js', 'tasks'),
        ]);

        return $resource
            ->setContent(CspNonce::stamp($html))
            ->setHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
