(function(){
  // Every lookup goes through the component root — the .wrap this script
  // follows — never the whole document.
  var me=document.currentScript, prev=me&&me.previousElementSibling;
  var root=(prev&&prev.classList.contains('wrap'))?prev:document.querySelector('.wrap');
  if(!root) return;
  var listEl=root.querySelector('#list'), subEl=root.querySelector('#sub'), errEl=root.querySelector('#err');
  var titleEl=root.querySelector('#title'), autoEl=root.querySelector('#auto'), etaEl=root.querySelector('#eta'), addEl=root.querySelector('#add');
  if(!listEl||!subEl||!titleEl||!autoEl||!etaEl||!addEl) return;
  var esc=function(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});};
  autoEl.addEventListener('change',function(){ etaEl.style.display=autoEl.checked?'':'none'; });

  function when(iso){ if(!iso) return null; try{ var d=new Date(iso); return d.toLocaleString([], {month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}); }catch(e){ return iso; } }
  function overdue(t){ return t.deadline && t.status!=='done' && t.status!=='cancelled' && new Date(t.deadline) < new Date(); }

  function render(tasks){
    tasks=tasks||[];
    var open=tasks.filter(function(t){return t.status!=='done'&&t.status!=='cancelled';}).length;
    subEl.textContent = tasks.length+' task'+(tasks.length===1?'':'s')+' · '+open+' open';
    if(!tasks.length){ listEl.innerHTML='<div class="empty">No tasks yet. Add one above — or ask '+esc(listEl.dataset.aname||'')+' to.</div>'; return; }
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
  function showErr(msg){ if(!errEl) return; errEl.textContent=msg||''; errEl.hidden=!msg; }
  // Resolves true only when both the HTTP answer and the mutation itself
  // report success; a failure is shown, never swallowed.
  // Every write carries the double-submit CSRF token: without it CsrfListener
  // answered 403 and no task could be added. The rule is Platform UI's client
  // core, a module, so it is looked up when a write happens, not at load.
  function csrfHeaders(headers){
    var core=window.SemitexaUi&&window.SemitexaUi.core;
    return core&&typeof core.withCsrf==='function'?core.withCsrf('POST',headers):headers;
  }
  function mutate(body){
    return fetch('/os/app/tasks/mutate',{method:'POST',headers:csrfHeaders({'Content-Type':'application/json','Accept':'application/json'}),body:JSON.stringify(body)})
      .then(function(r){ if(!r.ok) throw 0; return r.json(); })
      .then(function(d){ if(d&&d.tasks) render(d.tasks); if(!d||!d.ok) throw 0; showErr(''); return true; })
      .catch(function(){ showErr('Couldn’t save the change — try again.'); return false; });
  }

  addEl.addEventListener('click',function(){
    var title=titleEl.value.trim(); if(!title) return;
    var body={action:'create',title:title,automated:autoEl.checked};
    if(autoEl.checked){ var e=parseInt(etaEl.value,10); body.etaSeconds=(e>0?e:20); }
    // The form keeps what was typed until the task really exists; Add stays
    // off meanwhile so a second click cannot create it twice.
    addEl.disabled=true;
    var sent={title:titleEl.value,auto:autoEl.checked,eta:etaEl.value};
    mutate(body).then(function(ok){
      addEl.disabled=false;
      if(!ok) return;
      // Typed into while the request was out: that is the next task, not this one.
      if(titleEl.value!==sent.title||autoEl.checked!==sent.auto||etaEl.value!==sent.eta) return;
      titleEl.value=''; etaEl.value=''; autoEl.checked=false; etaEl.style.display='none'; titleEl.focus();
    });
  });
  titleEl.addEventListener('keydown',function(e){ if(e.key==='Enter') addEl.click(); });
  listEl.addEventListener('click',function(e){ var b=e.target.closest('[data-act]'); if(!b) return; mutate({action:b.dataset.act,id:b.dataset.id}); });

  load();
  setInterval(load, 3000);   // reflect background-tick progress live
})();
