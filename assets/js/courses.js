let ALL_COURSES = [];
let COURSE_FILTERED = [];
let CURRENT_PAGE = 1;
const PAGE_SIZE = 6;
let courseRequestId = 0;

const PROGRESS_COLOR = pct => pct >= 70 ? '#059669' : pct >= 40 ? '#d97706' : '#dc2626';
const esc = value => typeof window.escapeHtml === 'function' ? window.escapeHtml(String(value ?? '')) : String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
const safeColor = value => /^#[0-9a-f]{6}$/i.test(String(value || '')) ? String(value) : '#059669';

function showCourseError(message) {
  const el = document.getElementById('course-form-error');
  el.textContent = message || '';
  el.classList.toggle('hidden', !message);
}

async function loadCourses() {
  const requestId = ++courseRequestId;
  const loading = document.getElementById('course-loading');
  loading.classList.remove('hidden');
  try {
    const res = await fetch(`${API}/courses.php`, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Could not load courses.');
    if (requestId !== courseRequestId) return;
    ALL_COURSES = Array.isArray(data.courses) ? data.courses : [];
    populateFilters();
    renderStatCards(data.summary || {});
    renderCourseProgress(data.summary || {});
    renderUpcomingDeadlines(data.upcoming_deadlines || []);
    applyFilters(1);
  } catch (error) {
    if (requestId !== courseRequestId) return;
    document.getElementById('course-cards').innerHTML = `<div class="empty-state col-span-full"><i data-lucide="triangle-alert"></i><strong>Unable to load courses</strong><span>${esc(error.message)}</span><button type="button" id="retry-courses" class="text-emerald-700 dark:text-emerald-400 font-semibold">Try again</button></div>`;
    if (window.lucide) window.lucide.createIcons();
  } finally {
    if (requestId === courseRequestId) loading.classList.add('hidden');
  }
}

function statCard(icon, iconClass, bgClass, value, label, subtext) {
  return `<div class="course-stat-card">
    <div class="course-stat-icon ${bgClass} ${iconClass}"><i data-lucide="${icon}"></i></div>
    <div class="min-w-0"><div class="text-2xl font-bold leading-tight truncate">${esc(value)}</div><div class="text-xs text-[#53736D] dark:text-gray-400 mt-0.5">${esc(label)}</div>${subtext ? `<div class="text-[10px] text-emerald-600 dark:text-emerald-400 mt-1">↗ ${esc(subtext)}</div>` : ''}</div>
  </div>`;
}

function renderStatCards(s) {
  const gpa = s.gpa === null || s.gpa === undefined ? '—' : Number(s.gpa).toFixed(2);
  document.getElementById('course-stat-cards').innerHTML = [
    statCard('book-open', 'text-emerald-700', 'bg-emerald-50 dark:bg-emerald-950/40', s.total_courses ?? 0, 'Total Courses', s.new_courses_text || ''),
    statCard('clipboard-list', 'text-blue-600', 'bg-blue-50 dark:bg-blue-950/40', s.total_tasks ?? 0, 'Total Tasks', s.tasks_change_text || ''),
    statCard('graduation-cap', 'text-purple-600', 'bg-purple-50 dark:bg-purple-950/40', s.total_credits ?? 0, 'Total Credits', s.credits_change_text || ''),
    statCard('chart-no-axes-combined', 'text-amber-600', 'bg-amber-50 dark:bg-amber-950/40', `${s.average_progress ?? 0}%`, 'Overall Progress', s.progress_change_text || ''),
    statCard('star', 'text-pink-600', 'bg-pink-50 dark:bg-pink-950/40', gpa, 'GPA Tracker', s.gpa_change_text || '')
  ].join('');
  if (window.lucide) window.lucide.createIcons();
}

function renderCourseProgress(summary) {
  const pct = Math.max(0, Math.min(100, Number(summary.average_progress || 0)));
  const donut = document.getElementById('course-donut');
  donut.style.setProperty('--progress', `${pct * 3.6}deg`);
  document.getElementById('course-progress-total').textContent = `${pct}%`;
  const breakdown = summary.progress_breakdown || { completed: 0, in_progress: 0, not_started: 0 };
  const total = Number(breakdown.completed || 0) + Number(breakdown.in_progress || 0) + Number(breakdown.not_started || 0);
  const rows = [
    ['Completed', breakdown.completed, '#059669'],
    ['In Progress', breakdown.in_progress, '#f59e0b'],
    ['Not Started', breakdown.not_started, '#94a3b8']
  ];
  document.getElementById('course-progress-legend').innerHTML = rows.map(([label, count, color]) => `<div class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full" style="background:${color}"></span><span class="flex-1">${label}</span><strong>${count}${total ? ` <span class="font-normal text-gray-400">(${Math.round(Number(count || 0) / total * 100)}%)</span>` : ''}</strong></div>`).join('');
}

function renderUpcomingDeadlines(items) {
  const box = document.getElementById('course-upcoming-deadlines');
  if (!items.length) {
    box.innerHTML = `<div class="empty-state py-6"><i data-lucide="calendar-check"></i><span>No upcoming deadlines.</span></div>`;
    if (window.lucide) window.lucide.createIcons();
    return;
  }
  box.innerHTML = items.slice(0, 4).map(item => {
    const priority = String(item.priority || 'medium');
    const iconClass = priority === 'high' ? 'bg-red-50 text-red-600 dark:bg-red-950/30' : priority === 'low' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30' : 'bg-amber-50 text-amber-600 dark:bg-amber-950/30';
    return `<a href="deadlines.php" class="deadline-mini flex gap-3 py-3 first:pt-0 last:pb-0">
      <span class="w-9 h-9 rounded-full ${iconClass} flex items-center justify-center shrink-0"><i data-lucide="${priority === 'high' ? 'briefcase-business' : 'file-text'}" class="w-4 h-4"></i></span>
      <span class="min-w-0 flex-1"><strong class="block text-xs truncate">${esc(item.title)}</strong><small class="block text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">${esc(item.course_code || 'No course')}</small><small class="block text-[11px] ${priority === 'high' ? 'text-red-600' : priority === 'medium' ? 'text-amber-600' : 'text-emerald-600'} mt-0.5">${esc(item.due_label)}</small></span>
    </a>`;
  }).join('');
  if (window.lucide) window.lucide.createIcons();
}

function populateFilters() {
  const semester = document.getElementById('course-semester');
  const department = document.getElementById('course-department');
  const currentSemester = semester.value;
  const currentDepartment = department.value;
  const semesters = [...new Set(ALL_COURSES.map(c => c.semester).filter(Boolean))].sort((a,b) => a.localeCompare(b));
  const departments = [...new Set(ALL_COURSES.map(c => String(c.code || '').trim().split(/\s+/)[0]).filter(Boolean))].sort((a,b) => a.localeCompare(b));
  semester.innerHTML = '<option value="">All Semesters</option>' + semesters.map(v => `<option value="${esc(v)}">${esc(v)}</option>`).join('');
  department.innerHTML = '<option value="">All Departments</option>' + departments.map(v => `<option value="${esc(v)}">${esc(v)}</option>`).join('');
  if (semesters.includes(currentSemester)) semester.value = currentSemester;
  if (departments.includes(currentDepartment)) department.value = currentDepartment;
}

function getFilteredCourses() {
  const q = document.getElementById('course-search').value.trim().toLowerCase();
  const inline = document.getElementById('course-search-inline').value.trim().toLowerCase();
  const query = inline || q;
  const semester = document.getElementById('course-semester').value;
  const department = document.getElementById('course-department').value;
  let list = ALL_COURSES.filter(c => {
    const haystack = `${c.code || ''} ${c.name || ''} ${c.lecturer || ''}`.toLowerCase();
    const dept = String(c.code || '').trim().split(/\s+/)[0];
    return (!query || haystack.includes(query)) && (!semester || c.semester === semester) && (!department || dept === department);
  });
  const by = document.getElementById('course-sort').value;
  list.sort((a,b) => {
    if (by === 'progress') return Number(b.progress || 0) - Number(a.progress || 0) || String(a.code).localeCompare(String(b.code));
    if (by === 'credits') return Number(b.credits || 0) - Number(a.credits || 0) || String(a.code).localeCompare(String(b.code));
    if (by === 'code') return String(a.code || '').localeCompare(String(b.code || ''));
    return String(a.name || '').localeCompare(String(b.name || ''));
  });
  return list;
}

function applyFilters(page = 1) {
  COURSE_FILTERED = getFilteredCourses();
  const totalPages = Math.max(1, Math.ceil(COURSE_FILTERED.length / PAGE_SIZE));
  CURRENT_PAGE = Math.min(Math.max(1, page), totalPages);
  const start = (CURRENT_PAGE - 1) * PAGE_SIZE;
  renderCourseCards(COURSE_FILTERED.slice(start, start + PAGE_SIZE));
  renderPagination(totalPages);
  document.getElementById('course-count-label').textContent = COURSE_FILTERED.length ? `Showing ${start + 1}–${Math.min(start + PAGE_SIZE, COURSE_FILTERED.length)} of ${COURSE_FILTERED.length} courses` : 'No courses match your filters';
}

function renderCourseCards(courses) {
  const grid = document.getElementById('course-cards');
  if (!courses.length) {
    grid.innerHTML = `<div class="empty-state col-span-full py-12"><i data-lucide="book-open-check"></i><strong>No courses found</strong><span>Try another search or add a new course.</span><button type="button" id="empty-add-course" class="mt-1 text-emerald-700 dark:text-emerald-400 font-semibold">Add a course</button></div>`;
    document.getElementById('empty-add-course')?.addEventListener('click', openAddCourse);
    if (window.lucide) window.lucide.createIcons();
    return;
  }
  grid.innerHTML = courses.map(c => {
    const progress = Math.max(0, Math.min(100, Number(c.progress || 0)));
    return `<article class="course-card hover-lift">
      <div class="flex items-start gap-3">
        <div class="course-icon" style="background:${safeColor(c.color)}">${esc(c.icon || '📘')}</div>
        <div class="min-w-0 flex-1">
          <div class="flex items-start justify-between gap-2"><div><h4 class="font-bold text-sm">${esc(c.code)}</h4><p class="text-sm mt-1">${esc(c.name)}</p><p class="text-xs text-[#63817A] dark:text-gray-400 mt-1">${esc(c.lecturer || 'No lecturer set')}</p></div><span class="credit-pill">${esc(c.credits)} Credit${Number(c.credits) === 1 ? '' : 's'}</span></div>
          <div class="flex items-center gap-2 mt-4"><div class="flex-1 h-1.5 bg-[#E7EEEC] dark:bg-white/10 rounded-full overflow-hidden"><div class="h-full rounded-full" data-work-progress-item="course:${esc(c.id)}" style="width:${progress}%;background:${PROGRESS_COLOR(progress)}"></div></div><span class="text-[11px] font-semibold">${progress}%</span></div>
          <div class="flex items-center justify-between mt-3 gap-2"><span class="semester-pill">${esc(c.semester || 'Current Semester')}</span><span class="text-xs text-[#486C64] dark:text-gray-400 flex items-center gap-1"><i data-lucide="calendar-check" class="w-3.5 h-3.5"></i>${Number(c.task_count || 0)} Task${Number(c.task_count || 0) === 1 ? '' : 's'}</span></div>
        </div>
      </div>
      <div class="course-card-actions"><button class="course-action timer-course-btn text-emerald-700 dark:text-emerald-300" data-work-item-type="course" data-work-item-id="${esc(c.id)}" data-work-item-title="${esc(c.code)} — ${esc(c.name)}" data-work-complete="${progress >= 100 ? 'true' : 'false'}"><i data-lucide="timer"></i><span data-work-label>${progress >= 100 ? 'Completed' : 'Start'}</span></button><button class="course-action edit-course-btn" data-id="${esc(c.id)}"><i data-lucide="pencil"></i>Edit</button><button class="course-action delete-course-btn danger" data-id="${esc(c.id)}"><i data-lucide="trash-2"></i>Delete</button></div>
    </article>`;
  }).join('');
  grid.querySelectorAll('.edit-course-btn').forEach(btn => btn.addEventListener('click', () => openEditCourse(btn.dataset.id)));
  grid.querySelectorAll('.delete-course-btn').forEach(btn => btn.addEventListener('click', () => deleteCourse(btn.dataset.id)));
  if (window.lucide) window.lucide.createIcons();
}

function renderPagination(totalPages) {
  const box = document.getElementById('course-pagination');
  if (totalPages <= 1) { box.innerHTML = ''; return; }
  const button = (page, label, disabled = false, active = false) => `<button type="button" data-page="${page}" class="pagination-btn ${active ? 'active' : ''}" ${disabled ? 'disabled' : ''}>${label}</button>`;
  let html = button(CURRENT_PAGE - 1, '‹', CURRENT_PAGE === 1);
  for (let p = 1; p <= totalPages; p++) {
    if (totalPages > 5 && p > 2 && p < totalPages - 1 && Math.abs(p - CURRENT_PAGE) > 1) { if (p === 3 || p === totalPages - 2) html += '<span class="px-1 text-gray-400">…</span>'; continue; }
    html += button(p, p, false, p === CURRENT_PAGE);
  }
  html += button(CURRENT_PAGE + 1, '›', CURRENT_PAGE === totalPages);
  box.innerHTML = html;
  box.querySelectorAll('[data-page]').forEach(btn => btn.addEventListener('click', () => applyFilters(Number(btn.dataset.page))));
}

const modal = document.getElementById('course-modal');
const form = document.getElementById('course-form');

function openAddCourse() {
  form.reset();
  form.id.value = '';
  form.credits.value = '3';
  form.color.value = '#059669';
  form.status.value = 'pending';
  form.estimated_hours.value = '';
  form.progress_percent.value = '0';
  document.getElementById('course-progress-value').textContent = '0%';
  document.getElementById('course-modal-title').textContent = 'Add Course';
  showCourseError('');
  modal.classList.remove('hidden');
  setTimeout(() => form.code.focus(), 0);
}

function closeCourseModal() { modal.classList.add('hidden'); showCourseError(''); }

document.getElementById('open-add-course').addEventListener('click', openAddCourse);
document.getElementById('quick-add-course').addEventListener('click', openAddCourse);
document.getElementById('cancel-course').addEventListener('click', closeCourseModal);
document.getElementById('close-course-modal').addEventListener('click', closeCourseModal);
modal.addEventListener('click', e => { if (e.target === modal) closeCourseModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeCourseModal(); });

document.getElementById('retry-courses')?.addEventListener('click', loadCourses);

function openEditCourse(id) {
  const course = ALL_COURSES.find(c => String(c.id) === String(id));
  if (!course) return;
  form.id.value = course.id;
  form.code.value = course.code || '';
  form.name.value = course.name || '';
  form.lecturer.value = course.lecturer || '';
  form.credits.value = course.credits || 3;
  form.semester.value = course.semester || '';
  form.icon.value = course.icon || '📘';
  form.color.value = safeColor(course.color);
  form.grade_point.value = course.grade_point ?? '';
  form.status.value = course.status || 'pending';
  form.estimated_hours.value = course.estimated_hours ?? '';
  const courseProgress = Math.round(Number(course.progress || course.progress_percent || 0));
  form.progress_percent.value = courseProgress;
  document.getElementById('course-progress-value').textContent = `${courseProgress}%`;
  document.getElementById('course-modal-title').textContent = 'Edit Course';
  showCourseError('');
  modal.classList.remove('hidden');
}

async function deleteCourse(id) {
  const course = ALL_COURSES.find(c => String(c.id) === String(id));
  if (!course) return;
  if (!confirm(`Delete ${course.code} — ${course.name}? Linked tasks and schedule entries will keep their history but lose the course link.`)) return;
  try {
    const res = await fetch(`${API}/courses.php`, {
      method: 'DELETE', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
      body: new URLSearchParams({ id: String(id), csrf_token: window.CSRF_TOKEN || '' }).toString()
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Could not delete course.');
    window.showToast?.('Course deleted', 'success');
    await loadCourses();
  } catch (error) { window.showToast?.(error.message, 'error'); }
}

form.addEventListener('submit', async e => {
  e.preventDefault();
  showCourseError('');
  const fd = new FormData(form);
  const payload = Object.fromEntries(fd.entries());
  payload.csrf_token = window.CSRF_TOKEN || '';
  const isEdit = Boolean(payload.id);
  const saveBtn = document.getElementById('save-course-btn');
  saveBtn.disabled = true;
  saveBtn.textContent = 'Saving…';
  try {
    const res = await fetch(`${API}/courses.php`, {
      method: isEdit ? 'PUT' : 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Could not save course.');
    closeCourseModal();
    window.showToast?.(isEdit ? 'Course updated' : 'Course added', 'success');
    await loadCourses();
  } catch (error) { showCourseError(error.message); }
  finally { saveBtn.disabled = false; saveBtn.textContent = 'Save Course'; }
});

function syncSearch(value, source) {
  const other = source === 'top' ? document.getElementById('course-search-inline') : document.getElementById('course-search');
  if (other.value !== value) other.value = value;
  applyFilters(1);
}
document.getElementById('course-search').addEventListener('input', e => syncSearch(e.target.value, 'top'));
document.getElementById('course-search-inline').addEventListener('input', e => syncSearch(e.target.value, 'inline'));
document.getElementById('course-semester').addEventListener('change', () => applyFilters(1));
document.getElementById('course-department').addEventListener('change', () => applyFilters(1));
document.getElementById('course-sort').addEventListener('change', () => applyFilters(1));

document.getElementById('course-cards').addEventListener('click', e => {
  if (e.target.closest('#retry-courses')) loadCourses();
});

window.addEventListener('work-item-updated', e => { if (e.detail?.type === 'course') loadCourses(); });
window.APP_READY.then(me => { if (me) loadCourses(); });
