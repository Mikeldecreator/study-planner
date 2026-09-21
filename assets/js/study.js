(() => {
  const API = '../api';
  let STUDY_DATA = null;
  let ACTIVE_SESSION = null;
  let timerInterval = null;
  let timerClockSeconds = 0;
  let timerTaskEstimatedSeconds = 0;
  let timerTaskBaseFocusedSeconds = 0;

  const esc = (val) => typeof window.escapeHtml === 'function'
    ? window.escapeHtml(String(val ?? ''))
    : String(val ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));

  function formatDuration(sec) {
    sec = Math.max(0, Math.floor(Number(sec) || 0));
    const h = Math.floor(sec / 3600);
    const m = Math.floor((sec % 3600) / 60);
    const s = sec % 60;
    if (h > 0) return `${h}h ${m}m`;
    if (m > 0) return `${m}m ${s}s`;
    return `${s}s`;
  }

  function formatDigitalClock(sec) {
    sec = Math.max(0, Math.floor(Number(sec) || 0));
    const h = Math.floor(sec / 3600);
    const m = Math.floor((sec % 3600) / 60);
    const s = sec % 60;
    return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
  }

  function formatPercent(pct) {
    const n = Number(pct) || 0;
    if (n % 1 === 0) return `${n}%`;
    return `${n.toFixed(2)}%`;
  }

  function updateTimerUIState(status) {
    const card = document.getElementById('focus-timer-card');
    const badge = document.getElementById('timer-status-badge');
    const dot = document.getElementById('timer-status-dot');
    const text = document.getElementById('timer-status-text');
    const btnStart = document.getElementById('timer-btn-start');
    const btnPause = document.getElementById('timer-btn-pause');
    const btnResume = document.getElementById('timer-btn-resume');
    const btnStop = document.getElementById('timer-btn-stop');

    if (!card) return;

    card.classList.remove('is-running', 'is-paused');
    if (status === 'running') {
      card.classList.add('is-running');
      if (text) text.textContent = 'Running';
      if (dot) dot.className = 'w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse';
      if (badge) badge.className = 'inline-flex items-center gap-1.5 text-[10px] font-bold px-2.5 py-0.5 rounded-full bg-emerald-100 dark:bg-emerald-950/60 text-emerald-800 dark:text-emerald-300';
      if (btnStart) btnStart.classList.add('hidden');
      if (btnPause) btnPause.classList.remove('hidden');
      if (btnResume) btnResume.classList.add('hidden');
      if (btnStop) {
        btnStop.disabled = false;
        btnStop.classList.remove('opacity-50', 'cursor-not-allowed');
      }
    } else if (status === 'paused') {
      card.classList.add('is-paused');
      if (text) text.textContent = 'Paused';
      if (dot) dot.className = 'w-1.5 h-1.5 rounded-full bg-amber-500';
      if (badge) badge.className = 'inline-flex items-center gap-1.5 text-[10px] font-bold px-2.5 py-0.5 rounded-full bg-amber-100 dark:bg-amber-950/60 text-amber-800 dark:text-amber-300';
      if (btnStart) btnStart.classList.add('hidden');
      if (btnPause) btnPause.classList.add('hidden');
      if (btnResume) btnResume.classList.remove('hidden');
      if (btnStop) {
        btnStop.disabled = false;
        btnStop.classList.remove('opacity-50', 'cursor-not-allowed');
      }
    } else {
      if (text) text.textContent = 'Idle';
      if (dot) dot.className = 'w-1.5 h-1.5 rounded-full bg-gray-400';
      if (badge) badge.className = 'inline-flex items-center gap-1.5 text-[10px] font-bold px-2.5 py-0.5 rounded-full bg-gray-100 dark:bg-white/10 text-gray-600 dark:text-gray-300';
      if (btnStart) btnStart.classList.remove('hidden');
      if (btnPause) btnPause.classList.add('hidden');
      if (btnResume) btnResume.classList.add('hidden');
      if (btnStop) {
        btnStop.disabled = true;
        btnStop.classList.add('opacity-50', 'cursor-not-allowed');
      }
    }
  }

  function tickTimer() {
    timerClockSeconds++;
    const display = document.getElementById('timer-display');
    if (display) display.textContent = formatDigitalClock(timerClockSeconds);

    const currentTotal = timerTaskBaseFocusedSeconds + timerClockSeconds;
    const focusedEl = document.getElementById('timer-task-focused');
    if (focusedEl) focusedEl.textContent = formatDuration(currentTotal);

    if (timerTaskEstimatedSeconds > 0) {
      const pct = Math.min(100, Math.max(0, (currentTotal / timerTaskEstimatedSeconds) * 100));
      const pctEl = document.getElementById('timer-progress-pct');
      const barEl = document.getElementById('timer-progress-bar');
      if (pctEl) pctEl.textContent = formatPercent(pct);
      if (barEl) barEl.style.width = `${pct}%`;

      if (pct >= 100 && ACTIVE_SESSION?.status === 'running') {
        stopTimer(true);
      }
    }
  }

  function startClock(initialSeconds = 0) {
    stopClock();
    timerClockSeconds = initialSeconds;
    const display = document.getElementById('timer-display');
    if (display) display.textContent = formatDigitalClock(timerClockSeconds);
    timerInterval = setInterval(tickTimer, 1000);
  }

  function stopClock() {
    if (timerInterval) {
      clearInterval(timerInterval);
      timerInterval = null;
    }
  }

  function selectTaskInTimer(taskId, autoStart = false) {
    const select = document.getElementById('timer-task-select');
    if (!select || !taskId) return;
    select.value = String(taskId);
    onTaskSelectChange(autoStart);
  }

  function onTaskSelectChange(autoStart = false) {
    const select = document.getElementById('timer-task-select');
    const metaWrap = document.getElementById('timer-task-meta');
    if (!select || !metaWrap) return;

    const taskId = parseInt(select.value, 10);
    if (!taskId) {
      metaWrap.classList.add('hidden');
      timerTaskEstimatedSeconds = 0;
      timerTaskBaseFocusedSeconds = 0;
      return;
    }

    const task = (STUDY_DATA?.tasks || []).find(t => t.id === taskId);
    if (!task) return;

    metaWrap.classList.remove('hidden');
    document.getElementById('timer-task-title').textContent = task.title || 'Untitled Task';
    document.getElementById('timer-course-badge').textContent = task.course_code || 'General';
    document.getElementById('timer-task-priority').textContent = `${(task.priority || 'medium').toUpperCase()} Priority`;

    const focusedSec = Number(task.total_focused_seconds || 0);
    const durationHours = parseFloat(task.duration_hours || 0);
    timerTaskBaseFocusedSeconds = focusedSec;
    timerTaskEstimatedSeconds = Math.round(durationHours * 3600);

    document.getElementById('timer-task-focused').textContent = formatDuration(focusedSec);
    document.getElementById('timer-task-estimated').textContent = durationHours > 0 ? `${durationHours}h` : 'No estimate';

    let pct = 0;
    if (timerTaskEstimatedSeconds > 0) {
      pct = Math.min(100, Math.max(0, (focusedSec / timerTaskEstimatedSeconds) * 100));
    }
    document.getElementById('timer-progress-pct').textContent = formatPercent(pct);
    document.getElementById('timer-progress-bar').style.width = `${pct}%`;

    if (autoStart && (!ACTIVE_SESSION || ACTIVE_SESSION.status === 'stopped' || ACTIVE_SESSION.status === 'completed')) {
      startTimer();
    }
  }

  async function getCsrfToken() {
    if (window.CSRF_TOKEN) return window.CSRF_TOKEN;
    try {
      const res = await fetch(`${API}/csrf.php`, { credentials: 'same-origin' });
      if (res.ok) {
        const d = await res.json();
        window.CSRF_TOKEN = d.csrf_token;
        return d.csrf_token;
      }
    } catch (e) {}
    return '';
  }

  async function startTimer() {
    const select = document.getElementById('timer-task-select');
    const taskId = parseInt(select?.value, 10);
    if (!taskId) {
      if (typeof window.showToast === 'function') {
        window.showToast('Please select a task to focus on.', 'warning');
      }
      return;
    }

    try {
      const csrf = await getCsrfToken();
      const res = await fetch(`${API}/study-sessions.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ action: 'start', task_id: taskId, csrf_token: csrf })
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Failed to start session');

      ACTIVE_SESSION = data.session;
      updateTimerUIState('running');
      startClock(0);
      window.dispatchEvent(new CustomEvent('study-session-updated', { detail: { action: 'start', task_id: taskId } }));
    } catch (err) {
      console.error('Start timer error:', err);
      if (typeof window.showToast === 'function') {
        window.showToast(err.message, 'error');
      }
    }
  }

  async function pauseTimer() {
    if (!ACTIVE_SESSION?.id) return;
    try {
      const csrf = await getCsrfToken();
      const res = await fetch(`${API}/study-sessions.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ action: 'pause', session_id: ACTIVE_SESSION.id, csrf_token: csrf })
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Failed to pause session');

      ACTIVE_SESSION = data.session;
      stopClock();
      updateTimerUIState('paused');
    } catch (err) {
      console.error('Pause timer error:', err);
      if (typeof window.showToast === 'function') {
        window.showToast(err.message, 'error');
      }
    }
  }

  async function resumeTimer() {
    if (!ACTIVE_SESSION?.id) return;
    try {
      const csrf = await getCsrfToken();
      const res = await fetch(`${API}/study-sessions.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ action: 'resume', session_id: ACTIVE_SESSION.id, csrf_token: csrf })
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Failed to resume session');

      ACTIVE_SESSION = data.session;
      updateTimerUIState('running');
      startClock(timerClockSeconds);
    } catch (err) {
      console.error('Resume timer error:', err);
      if (typeof window.showToast === 'function') {
        window.showToast(err.message, 'error');
      }
    }
  }

  async function stopTimer(autoComplete = false) {
    if (!ACTIVE_SESSION?.id) return;
    try {
      const actionName = autoComplete ? 'complete' : 'stop';
      const csrf = await getCsrfToken();
      const res = await fetch(`${API}/study-sessions.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ action: actionName, session_id: ACTIVE_SESSION.id, csrf_token: csrf })
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Failed to stop session');

      stopClock();
      updateTimerUIState('idle');
      timerClockSeconds = 0;
      document.getElementById('timer-display').textContent = '00:00:00';
      ACTIVE_SESSION = null;

      // Reload study data to reflect recorded session
      await loadStudyData();
      window.dispatchEvent(new CustomEvent('study-session-updated', { detail: { action: 'stop' } }));
    } catch (err) {
      console.error('Stop timer error:', err);
      if (typeof window.showToast === 'function') {
        window.showToast(err.message, 'error');
      }
    }
  }

  function renderTodaysFocus(focus) {
    const titleEl = document.getElementById('todays-focus-title');
    const reasonEl = document.getElementById('todays-focus-reason');
    const metaEl = document.getElementById('todays-focus-meta');
    const riskEl = document.getElementById('todays-focus-risk');
    const btn = document.getElementById('focus-now-btn');

    if (!titleEl) return;

    if (!focus) {
      titleEl.textContent = 'All caught up! No urgent tasks pending.';
      reasonEl.textContent = 'You have completed your active workload. Plan ahead or schedule a study block.';
      metaEl.innerHTML = '';
      if (riskEl) riskEl.classList.add('hidden');
      if (btn) btn.classList.add('hidden');
      return;
    }

    titleEl.textContent = focus.title;
    reasonEl.textContent = focus.reason;

    if (focus.risk_label && focus.risk_label !== 'Normal') {
      riskEl.textContent = `${focus.risk_label} Risk`;
      riskEl.classList.remove('hidden');
    } else {
      riskEl.classList.add('hidden');
    }

    metaEl.innerHTML = `
      ${focus.course_code ? `<span class="inline-flex items-center px-2 py-0.5 rounded-md font-bold bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-300"><i data-lucide="book" class="w-3 h-3 mr-1"></i> ${esc(focus.course_code)}</span>` : ''}
      <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-gray-100 dark:bg-white/10 text-gray-700 dark:text-gray-300"><i data-lucide="clock" class="w-3 h-3 mr-1"></i> ${focus.remaining_hours}h remaining workload</span>
      <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-gray-100 dark:bg-white/10 text-gray-700 dark:text-gray-300"><i data-lucide="calendar" class="w-3 h-3 mr-1"></i> Due: ${esc(focus.deadline_display)}</span>
      <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 font-semibold"><i data-lucide="sparkles" class="w-3 h-3 mr-1"></i> ${esc(focus.recommended_action)}</span>
    `;

    if (btn) {
      btn.classList.remove('hidden');
      btn.onclick = () => {
        selectTaskInTimer(focus.task_id, true);
        document.getElementById('focus-timer-card')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      };
    }
  }

  function renderWeeklyGoal(goal) {
    const valEl = document.getElementById('weekly-goal-val');
    const subEl = document.getElementById('weekly-goal-sub');
    const barEl = document.getElementById('weekly-goal-bar');

    if (!valEl || !goal) return;

    const logged = Number(goal.logged_study_hours || 0);
    const target = Number(goal.weekly_goal_hours || 15);
    const pct = Number(goal.goal_progress_percent || 0);

    valEl.textContent = `${logged.toFixed(1)}h / ${target.toFixed(1)}h`;
    subEl.textContent = `${formatPercent(pct)} of weekly goal reached`;
    barEl.style.width = `${Math.min(100, Math.max(0, pct))}%`;

    const todayVal = document.getElementById('today-focus-val');
    if (todayVal) todayVal.textContent = formatDuration(Number(goal.today_study_hours || 0) * 3600);
  }

  function renderCourseNeedingAttention(course) {
    const codeEl = document.getElementById('course-attention-code');
    const msgEl = document.getElementById('course-attention-msg');
    const pillEl = document.getElementById('course-attention-pill');

    if (!codeEl) return;
    if (!course) {
      codeEl.textContent = 'All Courses On Track';
      msgEl.textContent = 'No high-pressure courses detected.';
      if (pillEl) pillEl.textContent = 'Good Standing';
      return;
    }

    codeEl.textContent = course.code;
    msgEl.textContent = course.context_message;
    if (pillEl) {
      pillEl.textContent = `${course.pressure_level} Pressure`;
    }
  }

  function renderRecentSessions(sessions) {
    const tbody = document.getElementById('recent-sessions-body');
    const countEl = document.getElementById('today-sessions-count');
    if (!tbody) return;

    const list = Array.isArray(sessions) ? sessions : [];
    if (countEl) countEl.textContent = `${list.length} recent sessions`;

    if (!list.length) {
      tbody.innerHTML = `
        <tr>
          <td colspan="4" class="py-8 text-center text-xs text-gray-400">
            No study sessions recorded yet. Start a focus timer to log your study time!
          </td>
        </tr>
      `;
      return;
    }

    tbody.innerHTML = list.map(s => {
      const dur = formatDuration(s.duration_seconds);
      const dateStr = s.started_at ? new Date(s.started_at.replace(' ', 'T')).toLocaleDateString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';
      return `
        <tr class="border-b border-gray-100 dark:border-white/5 hover:bg-gray-50/50 dark:hover:bg-white/[0.02]">
          <td class="py-3 px-4 font-semibold text-gray-900 dark:text-gray-100">${esc(s.task_title || 'General Study')}</td>
          <td class="py-3 px-4 text-xs">${s.course_code ? `<span class="px-2 py-0.5 rounded font-bold bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">${esc(s.course_code)}</span>` : '—'}</td>
          <td class="py-3 px-4 font-mono font-bold text-xs text-emerald-700 dark:text-emerald-400">${dur}</td>
          <td class="py-3 px-4 text-xs text-gray-500 dark:text-gray-400">${dateStr}</td>
        </tr>
      `;
    }).join('');
  }

  function renderUpcomingSessions(sessions) {
    const listEl = document.getElementById('upcoming-sessions-list');
    if (!listEl) return;

    const list = Array.isArray(sessions) ? sessions : [];
    if (!list.length) {
      listEl.innerHTML = `<div class="text-xs text-gray-400 py-4 text-center">No scheduled study blocks or classes for today or tomorrow.</div>`;
      return;
    }

    listEl.innerHTML = list.map(ev => {
      const typeLabel = ev.event_type === 'study' ? 'Study Block' : 'Class Lecture';
      const typeBg = ev.event_type === 'study' ? 'bg-purple-50 text-purple-700 dark:bg-purple-950/40 dark:text-purple-300' : 'bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300';
      return `
        <div class="flex items-center justify-between p-3 rounded-xl bg-gray-50/70 dark:bg-white/[0.02] border border-gray-200/80 dark:border-white/5">
          <div class="flex items-center gap-3">
            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full ${typeBg}">${typeLabel}</span>
            <div>
              <div class="text-xs font-bold text-gray-900 dark:text-gray-100">${esc(ev.title)}</div>
              <div class="text-[10px] text-gray-500 dark:text-gray-400">${esc(ev.course_code || '')} • ${ev.start_time?.slice(0,5)} – ${ev.end_time?.slice(0,5)}</div>
            </div>
          </div>
        </div>
      `;
    }).join('');
  }

  function populateTaskSelect(tasks) {
    const select = document.getElementById('timer-task-select');
    const hint = document.getElementById('timer-task-count-hint');
    if (!select) return;

    const activeList = (tasks || []).filter(t => t.status !== 'completed');
    if (hint) hint.textContent = `${activeList.length} active`;

    const prevValue = select.value;
    select.innerHTML = '<option value="">— Select a task to focus —</option>';

    if (!activeList.length) {
      select.innerHTML += '<option value="" disabled>No active tasks</option>';
      return;
    }

    const optgroup = document.createElement('optgroup');
    optgroup.label = 'Active & Pending Tasks';
    activeList.forEach(t => {
      const opt = document.createElement('option');
      opt.value = String(t.id);
      const course = t.course_code ? `[${t.course_code}] ` : '';
      const dur = parseFloat(t.duration_hours || 0);
      const est = dur > 0 ? ` (${dur}h)` : '';
      opt.textContent = `${course}${t.title}${est}`;
      optgroup.appendChild(opt);
    });
    select.appendChild(optgroup);

    if (prevValue) {
      select.value = prevValue;
    }
  }

  async function loadStudyData() {
    try {
      const res = await fetch(`${API}/study.php`, { credentials: 'same-origin' });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      STUDY_DATA = await res.json();

      renderTodaysFocus(STUDY_DATA.todays_focus);
      renderWeeklyGoal(STUDY_DATA.weekly_goal);
      renderCourseNeedingAttention(STUDY_DATA.course_needing_attention);
      renderRecentSessions(STUDY_DATA.recent_sessions);
      renderUpcomingSessions(STUDY_DATA.upcoming_sessions);
      populateTaskSelect(STUDY_DATA.tasks);

      // Check active focus session
      if (STUDY_DATA.active_session) {
        ACTIVE_SESSION = STUDY_DATA.active_session;
        selectTaskInTimer(ACTIVE_SESSION.task_id, false);
        if (ACTIVE_SESSION.status === 'running') {
          updateTimerUIState('running');
          startClock(ACTIVE_SESSION.current_elapsed_seconds || 0);
        } else if (ACTIVE_SESSION.status === 'paused') {
          updateTimerUIState('paused');
          timerClockSeconds = ACTIVE_SESSION.current_elapsed_seconds || 0;
          document.getElementById('timer-display').textContent = formatDigitalClock(timerClockSeconds);
        }
      } else {
        updateTimerUIState('idle');
      }

      if (window.lucide) window.lucide.createIcons();
    } catch (err) {
      console.error('Failed to load study data:', err);
    }
  }

  function initStudy() {
    // Attach timer event listeners
    document.getElementById('timer-task-select')?.addEventListener('change', () => onTaskSelectChange(false));
    document.getElementById('timer-btn-start')?.addEventListener('click', startTimer);
    document.getElementById('timer-btn-pause')?.addEventListener('click', pauseTimer);
    document.getElementById('timer-btn-resume')?.addEventListener('click', resumeTimer);
    document.getElementById('timer-btn-stop')?.addEventListener('click', () => stopTimer(false));

    loadStudyData();

    // Check URL parameters (e.g. ?task_id=X)
    const params = new URLSearchParams(window.location.search);
    const taskId = params.get('task_id') || params.get('focus_task_id');
    if (taskId) {
      selectTaskInTimer(taskId, params.get('start') === '1');
    }
  }

  let isStudyLoaded = false;
  function safeInitStudy() {
    if (isStudyLoaded) return;
    isStudyLoaded = true;
    initStudy();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', safeInitStudy);
  } else {
    safeInitStudy();
  }

  if (window.APP_READY && typeof window.APP_READY.then === 'function') {
    window.APP_READY.then(() => {
      safeInitStudy();
    }).catch(() => {
      safeInitStudy();
    });
  }

})();
