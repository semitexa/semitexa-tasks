<?php

declare(strict_types=1);

namespace Semitexa\Tasks\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Tasks\Application\Payload\Request\TasksAppPayload;

/**
 * Renders the Tasks app: a live task list (status, progress, deadline, start)
 * with create / start / complete / delete, styled to the Semitexa OS palette.
 * Polls /os/app/tasks/list so background-tick progress updates on its own.
 */
#[AsPayloadHandler(payload: TasksAppPayload::class, resource: ResourceResponse::class)]
final class TasksAppHandler implements TypedHandlerInterface
{
    public function handle(TasksAppPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Tasks</title>
<style>
  :root { --bg:#0a1a2f; --panel:#0f2136; --line:rgba(148,163,184,.16); --text:#d6e6ff; --dim:#6f8bb0; --accent:#37b7ff; }
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
  .btn { border:none; border-radius:10px; padding:10px 16px; font:600 14px/1 inherit; cursor:pointer; background:rgba(55,183,255,.18); color:var(--accent); }
  .btn:hover { background:rgba(55,183,255,.3); }
  .btn.mini { padding:6px 10px; font-size:12px; background:rgba(148,163,184,.12); color:#a8b8d4; }
  .btn.mini:hover { background:rgba(148,163,184,.22); }
  .btn.go { background:rgba(94,234,212,.16); color:#5eead4; }
  .btn.x { background:transparent; color:var(--dim); padding:6px 8px; }
  .btn.x:hover { color:#e0655f; }
  ul { list-style:none; margin:14px 0 0; padding:0; display:flex; flex-direction:column; gap:10px; }
  li { background:var(--panel); border:1px solid var(--line); border-radius:14px; padding:14px 16px; }
  li.done { opacity:.62; }
  .row { display:flex; align-items:center; gap:12px; }
  .title { flex:1; font-weight:500; }
  li.done .title { text-decoration:line-through; }
  .pill { font-size:11px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; padding:3px 9px; border-radius:999px; white-space:nowrap; }
  .pill.todo { background:rgba(148,163,184,.16); color:#a8b8d4; }
  .pill.in_progress { background:rgba(55,183,255,.16); color:var(--accent); }
  .pill.blocked { background:rgba(245,196,81,.16); color:#f5c451; }
  .pill.done { background:rgba(94,234,212,.16); color:#5eead4; }
  .pill.cancelled { background:rgba(148,163,184,.1); color:var(--dim); }
  .auto { font-size:11px; color:var(--accent); border:1px solid rgba(55,183,255,.35); border-radius:999px; padding:2px 7px; }
  .bar { height:6px; border-radius:999px; background:rgba(148,163,184,.14); margin-top:10px; overflow:hidden; }
  .bar > i { display:block; height:100%; background:var(--accent); border-radius:999px; transition:width .4s; }
  .meta { display:flex; gap:14px; margin-top:8px; color:var(--dim); font-size:12px; flex-wrap:wrap; }
  .meta .over { color:#e0655f; }
  .acts { display:flex; gap:6px; }
  .empty { color:var(--dim); text-align:center; padding:40px 10px; }
</style></head>
<body><div class="wrap">
  <h1>Tasks</h1>
  <div class="sub" id="sub">—</div>
  <div class="new">
    <input type="text" id="title" placeholder="Add a task…" autocomplete="off">
    <label><input type="checkbox" id="auto"> automated</label>
    <input type="number" id="eta" placeholder="sec" min="1" title="Auto-complete after N seconds" style="display:none">
    <button class="btn" id="add">Add</button>
  </div>
  <ul id="list"></ul>
</div>
<script>
(function(){
  var listEl=document.getElementById('list'), subEl=document.getElementById('sub');
  var titleEl=document.getElementById('title'), autoEl=document.getElementById('auto'), etaEl=document.getElementById('eta');
  var esc=function(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});};
  autoEl.addEventListener('change',function(){ etaEl.style.display=autoEl.checked?'':'none'; });

  function when(iso){ if(!iso) return null; try{ var d=new Date(iso); return d.toLocaleString([], {month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}); }catch(e){ return iso; } }
  function overdue(t){ return t.deadline && t.status!=='done' && t.status!=='cancelled' && new Date(t.deadline) < new Date(); }

  function render(tasks){
    tasks=tasks||[];
    var open=tasks.filter(function(t){return t.status!=='done'&&t.status!=='cancelled';}).length;
    subEl.textContent = tasks.length+' task'+(tasks.length===1?'':'s')+' · '+open+' open';
    if(!tasks.length){ listEl.innerHTML='<div class="empty">No tasks yet. Add one above — or ask Semi to.</div>'; return; }
    listEl.innerHTML = tasks.map(function(t){
      var showBar = t.status==='in_progress' || (t.automated && t.status!=='done' && t.status!=='cancelled');
      var meta=[];
      if(t.started_at) meta.push('<span>started '+when(t.started_at)+'</span>');
      if(t.deadline) meta.push('<span class="'+(overdue(t)?'over':'')+'">due '+when(t.deadline)+'</span>');
      if(t.completed_at) meta.push('<span>done '+when(t.completed_at)+'</span>');
      var acts='';
      if(t.status==='todo') acts+='<button class="btn mini" data-act="start" data-id="'+t.id+'">Start</button>';
      if(t.status!=='done'&&t.status!=='cancelled') acts+='<button class="btn mini go" data-act="complete" data-id="'+t.id+'">Done</button>';
      acts+='<button class="btn x" data-act="delete" data-id="'+t.id+'" title="Delete">&times;</button>';
      return '<li class="'+(t.status==='done'?'done':'')+'">'
        + '<div class="row"><span class="pill '+t.status+'">'+esc(t.status_label)+'</span>'
        + '<span class="title">'+esc(t.title)+'</span>'
        + (t.automated?'<span class="auto">auto</span>':'')
        + '<span class="acts">'+acts+'</span></div>'
        + (showBar?'<div class="bar"><i style="width:'+(t.progress||0)+'%"></i></div>':'')
        + (meta.length?'<div class="meta">'+meta.join('')+'</div>':'')
        + '</li>';
    }).join('');
  }

  function load(){ fetch('/os/app/tasks/list',{headers:{'Accept':'application/json'}}).then(function(r){return r.json();}).then(function(d){ render(d.tasks); }).catch(function(){}); }
  function mutate(body){ return fetch('/os/app/tasks/mutate',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(body)}).then(function(r){return r.json();}).then(function(d){ render(d.tasks); }).catch(function(){}); }

  document.getElementById('add').addEventListener('click',function(){
    var title=titleEl.value.trim(); if(!title) return;
    var body={action:'create',title:title,automated:autoEl.checked};
    if(autoEl.checked){ var e=parseInt(etaEl.value,10); body.etaSeconds=(e>0?e:20); }
    titleEl.value=''; etaEl.value=''; autoEl.checked=false; etaEl.style.display='none'; titleEl.focus();
    mutate(body);
  });
  titleEl.addEventListener('keydown',function(e){ if(e.key==='Enter') document.getElementById('add').click(); });
  listEl.addEventListener('click',function(e){ var b=e.target.closest('[data-act]'); if(!b) return; mutate({action:b.dataset.act,id:b.dataset.id}); });

  load();
  setInterval(load, 3000);   // reflect background-tick progress live
})();
</script>
</body></html>
HTML;

        return $resource
            ->setContent($html)
            ->setHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
