const BUCKET_META = {
  study:       { label: 'Study',       color: '#166534' },
  assignments: { label: 'Assignments', color: '#2563eb' },
  projects:    { label: 'Projects',    color: '#7c3aed' },
  tests:       { label: 'Tests',       color: '#ea580c' },
};
const STATUS_DOT = { Light: 'bg-green-500', Moderate: 'bg-amber-500', Heavy: 'bg-red-500' };
const STATUS_PILL = {
  Light:    'bg-green-50 text-green-700 dark:bg-green-500/10 dark:text-green-400',
  Moderate: 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400',
  Heavy:    'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400',
};

// ---- Full notification feed (this page shows everything; the bell dropdown shows a preview) ----
async function loadNotificationFeed() {
  const res = await fetch(`${API}/notifications.php`);
  if (!res.ok) return;
  const data = await res.json();
  const el = document.getElementById('notification-feed');
  el.innerHTML = data.notifications.length
    ? data.notifications.map(n => `
      <div class="flex items-start gap-3 py-3 ${n.read_at ? '' : 'bg-green-50/40 dark:bg-green-900/10 -mx-2 px-2 rounded-lg'}">
        <span class="mt-0.5">${n.read_at ? '🔔' : '🟢'}</span>
        <div class="flex-1 min-w-0">
          <div class="text-sm font-medium">${escapeHtml(n.message)}</div>
          <div class="text-xs text-gray-400 mt-0.5">${new Date(n.send_at).toLocaleString()}</div>
        </div>
      </div>`).join('')
    : '<p class="text-sm text-gray-400 py-4">No notifications yet — reminders appear here as deadlines approach.</p>';
}

document.getElementById('mark-all-read').addEventListener('click', async () => {
  await fetch(`${API}/notifications.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ all: true, csrf_token: window.CSRF_TOKEN }),
  });
  loadNotificationFeed();
});

// ---- Weekly workload ----
async function loadWorkload() {
  const res = await fetch(`${API}/workload.php`);
  if (!res.ok) return;
  const d = await res.json();

  document.getElementById('total-hours').textContent = d.total_hours;

  const maxBucket = Math.max(1, ...Object.values(d.buckets));
  document.getElementById('workload-buckets').innerHTML = Object.entries(d.buckets).map(([key, hrs]) => {
    const meta = BUCKET_META[key];
    return `
      <div class="flex items-center gap-3">
        <span class="w-2.5 h-2.5 rounded-full shrink-0" style="background:${meta.color}"></span>
        <span class="w-28 text-sm shrink-0">${meta.label}</span>
        <div class="flex-1 h-2.5 bg-gray-100 dark:bg-white/10 rounded-full overflow-hidden">
          <div class="h-full" style="width:${(hrs / maxBucket) * 100}%; background:${meta.color}"></div>
        </div>
        <span class="w-12 text-right text-sm text-gray-500 dark:text-gray-400 shrink-0">${hrs}h</span>
      </div>`;
  }).join('');

  document.getElementById('weekday-status-list').innerHTML = d.weekday_status.map(w => `
    <div class="flex items-center justify-between py-3">
      <div class="flex items-center gap-3">
        <span class="w-2.5 h-2.5 rounded-full ${STATUS_DOT[w.status]}"></span>
        <span class="text-sm font-medium">${w.day}</span>
        <span class="text-xs text-gray-400">${w.hours}h</span>
      </div>
      <span class="text-xs font-medium px-3 py-1 rounded-full ${STATUS_PILL[w.status]}">${w.status}</span>
    </div>`).join('');
}

window.APP_READY.then(me => {
  if (!me) return;
  loadNotificationFeed();
  loadWorkload();
});
