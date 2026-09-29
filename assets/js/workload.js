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

// ---- Weekly workload ----
async function loadWorkload() {
  const apiBase = (typeof window.API !== 'undefined' ? window.API : (typeof API !== 'undefined' ? API : '../api'));
  try {
    const res = await fetch(`${apiBase}/workload.php`, { credentials: 'same-origin' });
    if (!res.ok) return;
    const d = await res.json();

    const totalEl = document.getElementById('total-hours');
    if (totalEl) totalEl.textContent = d.total_hours ?? '–';

    const bucketsEl = document.getElementById('workload-buckets');
    if (bucketsEl && d.buckets) {
      const maxBucket = Math.max(1, ...Object.values(d.buckets));
      bucketsEl.innerHTML = Object.entries(d.buckets).map(([key, hrs]) => {
        const meta = BUCKET_META[key] || { label: key, color: '#166534' };
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
    }

    const weekdayEl = document.getElementById('weekday-status-list');
    if (weekdayEl && Array.isArray(d.weekday_status)) {
      weekdayEl.innerHTML = d.weekday_status.map(w => `
        <div class="flex items-center justify-between py-3">
          <div class="flex items-center gap-3">
            <span class="w-2.5 h-2.5 rounded-full ${STATUS_DOT[w.status] || 'bg-gray-400'}"></span>
            <span class="text-sm font-medium">${w.day}</span>
            <span class="text-xs text-gray-400">${w.hours}h</span>
          </div>
          <span class="text-xs font-medium px-3 py-1 rounded-full ${STATUS_PILL[w.status] || 'bg-gray-100 text-gray-600'}">${w.status}</span>
        </div>`).join('');
    }
  } catch (err) {
    console.warn('Could not load workload data:', err);
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', loadWorkload);
} else {
  loadWorkload();
}
