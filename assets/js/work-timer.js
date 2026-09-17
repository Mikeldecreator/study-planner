(() => {
  const API = '../api';
  let active = null;
  let interval = null;
  const cache = new Map();

  const escapeHtml = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const cacheKey = (type,id) => `${type}:${id}`;
  const formatTime = secs => {
    secs = Math.max(0, Math.floor(Number(secs) || 0));
    const h = Math.floor(secs / 3600), m = Math.floor((secs % 3600) / 60), s = secs % 60;
    return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
  };
  const liveSeconds = timer => {
    let value = Number(timer?.total_work_seconds ?? timer?.total_seconds_live ?? 0);
    if (timer?.status === 'running' && timer?.started_at) {
      const started = new Date(String(timer.started_at).replace(' ','T')).getTime();
      if (Number.isFinite(started)) value += Math.max(0, Math.floor((Date.now() - started) / 1000));
    }
    return value;
  };
  const liveProgress = timer => {
    if (!timer) return 0;
    if (timer.status === 'completed') return 100;
    const estimate = Number(timer.estimate_hours || 0) * 3600;
    const base = Math.max(0, Math.min(100, Number(timer.base_progress ?? timer.progress_percent ?? 0)));
    if (estimate <= 0) return Math.max(0, Math.min(100, Number(timer.progress_percent ?? base)));
    return Math.min(100, Math.round(base + (100 - base) * liveSeconds(timer) / estimate));
  };

  async function request(payload, keepalive = false) {
    const res = await fetch(`${API}/work-timer.php`, {
      method: 'POST', credentials: 'same-origin', keepalive,
      headers: {'Content-Type':'application/json','Accept':'application/json'},
      body: JSON.stringify(payload)
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Could not update the timer.');
    return data;
  }

  async function getTimer(type,id) {
    const res = await fetch(`${API}/work-timer.php?item_type=${encodeURIComponent(type)}&item_id=${encodeURIComponent(id)}`, {credentials:'same-origin',cache:'no-store'});
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Could not load timer.');
    cache.set(cacheKey(type,id), data.timer);
    return data.timer;
  }

  function ensureOverlay() {
    let el = document.getElementById('work-timer-overlay');
    if (!el) {
      el = document.createElement('div');
      el.id = 'work-timer-overlay';
      document.body.appendChild(el);
    }
    return el;
  }

  function renderOverlay() {
    const el = ensureOverlay();
    if (!active?.timer) { el.innerHTML = ''; el.className = 'hidden'; return; }
    const t = active.timer;
    const progress = liveProgress(t);
    el.className = 'fixed bottom-4 left-1/2 -translate-x-1/2 z-[80] w-[min(92vw,600px)]';
    el.innerHTML = `<div class="rounded-2xl border border-emerald-200 dark:border-emerald-800 bg-white/95 dark:bg-[#101715]/95 backdrop-blur-xl shadow-2xl px-4 py-3">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 flex items-center justify-center"><i data-lucide="timer" class="w-5 h-5"></i></div>
        <div class="min-w-0 flex-1"><div class="text-[10px] uppercase tracking-wide text-gray-400">Working now</div><div class="font-bold text-sm truncate dark:text-white">${escapeHtml(active.title || t.item_title)}</div></div>
        <div class="text-right"><div class="font-mono font-bold text-lg dark:text-white" data-work-clock>${formatTime(liveSeconds(t))}</div><div class="text-xs font-bold text-emerald-700 dark:text-emerald-300" data-work-progress>${progress}%</div></div>
        <button type="button" data-work-pause class="px-3 py-2 rounded-lg bg-gray-100 dark:bg-white/10 text-xs font-bold dark:text-white">Pause</button>
        <button type="button" data-work-done class="px-3 py-2 rounded-lg bg-emerald-700 text-white text-xs font-bold">Done</button>
      </div>
      <div class="mt-2 h-1.5 rounded-full bg-gray-100 dark:bg-white/10 overflow-hidden"><div data-work-bar class="h-full bg-emerald-600 rounded-full" style="width:${progress}%"></div></div>
    </div>`;
    el.querySelector('[data-work-pause]').onclick = () => pauseActive();
    el.querySelector('[data-work-done]').onclick = () => completeActive();
    if (window.lucide) window.lucide.createIcons();
  }

  function renderButtons() {
    document.querySelectorAll('[data-work-item-type][data-work-item-id]').forEach(btn => {
      const type=btn.dataset.workItemType, id=String(btn.dataset.workItemId);
      const same = active && active.type===type && active.id===id;
      const done = btn.dataset.workComplete === 'true';
      const label = btn.querySelector('[data-work-label]');
      if (label) label.textContent = done ? 'Completed' : same ? 'Pause' : 'Start';
      else if (!btn.querySelector('i')) btn.textContent = done ? 'Completed' : same ? 'Pause' : 'Start';
      btn.disabled = done;
      btn.classList.toggle('opacity-60', done);
    });
  }

  function stopInterval() { if (interval) clearInterval(interval); interval=null; }
  function startInterval() { stopInterval(); interval=setInterval(tick,1000); }
  function tick() {
    if (!active?.timer) return;
    const progress=liveProgress(active.timer), seconds=liveSeconds(active.timer);
    document.querySelector('[data-work-clock]')?.replaceChildren(document.createTextNode(formatTime(seconds)));
    document.querySelector('[data-work-progress]')?.replaceChildren(document.createTextNode(`${progress}%`));
    const bar=document.querySelector('[data-work-bar]'); if(bar) bar.style.width=`${progress}%`;
    const itemBar=document.querySelectorAll(`[data-work-progress-item="${active.type}:${active.id}"]`); itemBar.forEach(el => el.style.width=`${progress}%`);
    renderButtons();
    if(progress>=100) completeActive(true);
  }

  async function start(type,id,title) {
    try {
      const current = cache.get(cacheKey(type,id)) || await getTimer(type,id);
      const action = current.status==='paused' && Number(current.total_work_seconds||0)>0 ? 'resume' : 'start';
      const data=await request({item_type:type,item_id:id,action,csrf_token:window.CSRF_TOKEN||''});
      active={type,id:String(id),title,timer:data.timer}; cache.set(cacheKey(type,id),data.timer);
      startInterval(); renderOverlay(); renderButtons();
      window.dispatchEvent(new CustomEvent('work-item-updated',{detail:{type,id:String(id),action}}));
    } catch(e) { window.showToast?.(e.message,'error'); }
  }

  async function pauseActive(silent=false) {
    if(!active) return;
    const a=active;
    try {
      const data=await request({item_type:a.type,item_id:a.id,action:'pause',csrf_token:window.CSRF_TOKEN||''},true);
      if(data.timer) cache.set(cacheKey(a.type,a.id),data.timer);
      if(!silent) window.showToast?.('Timer paused','success');
      active=null; stopInterval(); renderOverlay(); renderButtons();
      window.dispatchEvent(new CustomEvent('work-item-updated',{detail:{type:a.type,id:a.id,action:'pause'}}));
    } catch(e) { if(!silent) window.showToast?.(e.message,'error'); }
  }

  async function completeActive(silent=false) {
    if(!active) return;
    const a=active;
    try {
      const data=await request({item_type:a.type,item_id:a.id,action:'complete',csrf_token:window.CSRF_TOKEN||''});
      if(data.timer) cache.set(cacheKey(a.type,a.id),data.timer);
      if(!silent) window.showToast?.('Marked as completed','success');
      active=null; stopInterval(); renderOverlay(); renderButtons();
      window.dispatchEvent(new CustomEvent('work-item-updated',{detail:{type:a.type,id:a.id,action:'complete'}}));
    } catch(e) { if(!silent) window.showToast?.(e.message,'error'); }
  }

  document.addEventListener('click', event => {
    const btn=event.target.closest?.('[data-work-item-type][data-work-item-id]');
    if(!btn || btn.disabled) return;
    event.preventDefault();
    const type=btn.dataset.workItemType, id=String(btn.dataset.workItemId);
    if(active?.type===type && active.id===id) pauseActive();
    else start(type,id,btn.dataset.workItemTitle||'Work item');
  });

  const pauseOnLeave = () => {
    if (!active) return;
    const payload={item_type:active.type,item_id:active.id,action:'pause',csrf_token:window.CSRF_TOKEN||''};
    try {
      navigator.sendBeacon?.(`${API}/work-timer.php`, new Blob([JSON.stringify(payload)],{type:'application/json'}));
    } catch (_) {}
    active=null;
    stopInterval();
    renderOverlay();
    renderButtons();
  };
  document.addEventListener('visibilitychange',()=>{ if(document.hidden) pauseOnLeave(); });
  window.addEventListener('pagehide',pauseOnLeave);

  window.WorkTimer={start,pauseActive,completeActive,getTimer,liveProgress,liveSeconds};
  renderButtons();
})();
