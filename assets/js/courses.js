let ALL_COURSES = [];
let ALL_SEMESTERS = [];
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
    ALL_SEMESTERS = Array.isArray(data.semesters) ? data.semesters : [];
    populateFilters();
    renderStatCards(data.summary || {});
    renderCourseProgress(data.summary || {});
    renderUpcomingDeadlines(data.upcoming_deadlines || []);
    applyFilters(1);
    loadSemesterBanner();
  } catch (error) {
    if (requestId !== courseRequestId) return;
    document.getElementById('course-cards').innerHTML = `<div class="empty-state col-span-full"><i data-lucide="triangle-alert"></i><strong>Unable to load courses</strong><span>${esc(error.message)}</span><button type="button" id="retry-courses" class="text-emerald-700 dark:text-emerald-400 font-semibold">Try again</button></div>`;
    if (window.lucide) window.lucide.createIcons();
  } finally {
    if (requestId === courseRequestId) loading.classList.add('hidden');
  }
}

function statCard(icon, iconClass, bgClass, value, label, subtext, href) {
  const inner = `<div class="course-stat-card">
    <div class="course-stat-icon ${bgClass} ${iconClass}"><i data-lucide="${icon}"></i></div>
    <div class="min-w-0"><div class="text-2xl font-bold leading-tight truncate">${esc(value)}</div><div class="text-xs text-[#53736D] dark:text-gray-400 mt-0.5">${esc(label)}</div>${subtext ? `<div class="text-[10px] text-emerald-600 dark:text-emerald-400 mt-1">↗ ${esc(subtext)}</div>` : ''}</div>
  </div>`;
  return href ? `<a href="${href}" class="block hover:no-underline">${inner}</a>` : inner;
}

function renderStatCards(s) {
  const gpa = s.gpa === null || s.gpa === undefined ? '—' : Number(s.gpa).toFixed(2);
  document.getElementById('course-stat-cards').innerHTML = [
    statCard('book-open', 'text-emerald-700', 'bg-emerald-50 dark:bg-emerald-950/40', s.total_courses ?? 0, 'Total Courses', s.new_courses_text || ''),
    statCard('clipboard-list', 'text-blue-600', 'bg-blue-50 dark:bg-blue-950/40', s.total_tasks ?? 0, 'Total Tasks', s.tasks_change_text || '', 'tasks.php'),
    statCard('graduation-cap', 'text-purple-600', 'bg-purple-50 dark:bg-purple-950/40', s.total_credits ?? 0, 'Total Credits', s.credits_change_text || ''),
    statCard('chart-no-axes-combined', 'text-amber-600', 'bg-amber-50 dark:bg-amber-950/40', `${s.average_progress ?? 0}%`, 'Overall Progress', s.progress_change_text || '', 'progress.php'),
    statCard('star', 'text-pink-600', 'bg-pink-50 dark:bg-pink-950/40', gpa, 'GPA Tracker', s.gpa_change_text || '')
  ].join('');
  if (window.lucide) window.lucide.createIcons();
}

function getCourseDept(c) {
  if (c.department && String(c.department).trim()) return String(c.department).trim().toUpperCase();
  const code = String(c.code || '').trim();
  const match = code.match(/^([A-Za-z]+)/);
  if (match) return match[1].toUpperCase();
  return code.split(/\s+/)[0].toUpperCase();
}

function renderCourseProgress(summary) {
  const pct = Math.max(0, Math.min(100, Number(summary.average_progress || 0)));
  const donut = document.getElementById('course-donut');
  if (donut) {
    donut.style.setProperty('--progress', `${pct * 3.6}deg`);
  }
  const totalEl = document.getElementById('course-progress-total');
  if (totalEl) {
    totalEl.textContent = `${pct}%`;
  }
  const breakdown = summary.progress_breakdown || { completed: 0, in_progress: 0, not_started: 0 };
  const total = Number(breakdown.completed || 0) + Number(breakdown.in_progress || 0) + Number(breakdown.not_started || 0);
  const rows = [
    ['Completed', breakdown.completed, '#059669'],
    ['In Progress', breakdown.in_progress, '#f59e0b'],
    ['Not Started', breakdown.not_started, '#94a3b8']
  ];
  const legendEl = document.getElementById('course-progress-legend');
  if (legendEl) {
    legendEl.innerHTML = rows.map(([label, count, color]) => `<div class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full" style="background:${color}"></span><span class="flex-1 text-gray-600 dark:text-gray-300">${label}</span><strong class="text-gray-900 dark:text-gray-100">${count}${total ? ` <span class="font-normal text-gray-400">(${Math.round(Number(count || 0) / total * 100)}%)</span>` : ''}</strong></div>`).join('');
  }
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
  if (!semester || !department) return;
  const currentSemester = semester.value;
  const currentDepartment = department.value;
  
  // Combine semesters from backend semester records and course records
  const courseSemesters = ALL_COURSES.map(c => c.semester).filter(Boolean);
  const semesters = [...new Set([...(ALL_SEMESTERS || []), ...courseSemesters])].sort((a,b) => a.localeCompare(b));
  
  // Extract clean department codes
  const departments = [...new Set(ALL_COURSES.map(getCourseDept).filter(Boolean))].sort((a,b) => a.localeCompare(b));

  semester.innerHTML = '<option value="">All Semesters</option>' + semesters.map(v => `<option value="${esc(v)}">${esc(v)}</option>`).join('');
  department.innerHTML = '<option value="">All Departments</option>' + departments.map(v => `<option value="${esc(v)}">${esc(v)}</option>`).join('');
  if (semesters.includes(currentSemester)) semester.value = currentSemester;
  if (departments.includes(currentDepartment)) department.value = currentDepartment;

  const datalist = document.getElementById('course-semester-datalist');
  if (datalist) {
    datalist.innerHTML = semesters.map(v => `<option value="${esc(v)}"></option>`).join('');
  }
}

function getFilteredCourses() {
  const q = (document.getElementById('course-search')?.value || '').trim().toLowerCase();
  const inline = (document.getElementById('course-search-inline')?.value || '').trim().toLowerCase();
  const query = inline || q;
  const semester = document.getElementById('course-semester')?.value || '';
  const department = document.getElementById('course-department')?.value || '';
  let list = ALL_COURSES.filter(c => {
    const haystack = `${c.code || ''} ${c.name || ''} ${c.lecturer || ''}`.toLowerCase();
    const dept = getCourseDept(c);
    return (!query || haystack.includes(query)) && (!semester || c.semester === semester) && (!department || dept === department);
  });
  const by = document.getElementById('course-sort')?.value || 'name';
  list.sort((a,b) => {
    if (by === 'progress') return Number(b.progress || 0) - Number(a.progress || 0) || String(a.code || '').localeCompare(String(b.code || ''));
    if (by === 'credits') return Number(b.credits || 0) - Number(a.credits || 0) || String(a.code || '').localeCompare(String(b.code || ''));
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
          <div class="flex items-start justify-between gap-2"><div class="min-w-0 flex-1"><h4 class="font-bold text-sm">${esc(c.code)}</h4><p class="text-sm mt-1 break-words">${esc(c.name)}</p><p class="text-xs text-[#63817A] dark:text-gray-400 mt-1">${esc(c.lecturer || 'No lecturer set')}</p></div><span class="credit-pill shrink-0">${esc(c.credits)} Credit${Number(c.credits) === 1 ? '' : 's'}</span></div>
          <a href="progress.php" class="flex items-center gap-2 mt-4 group" title="View in Progress tracker"><div class="flex-1 h-1.5 bg-[#E7EEEC] dark:bg-white/10 rounded-full overflow-hidden"><div class="h-full rounded-full" data-work-progress-item="course:${esc(c.id)}" style="width:${progress}%;background:${PROGRESS_COLOR(progress)}"></div></div><span class="text-[11px] font-semibold text-gray-700 dark:text-gray-300 group-hover:text-emerald-600 dark:group-hover:text-emerald-400">${progress}%</span></a>
          <div class="flex items-center justify-between mt-3 gap-2"><span class="semester-pill">${esc(c.semester || 'Current Semester')}</span><a href="tasks.php?course_id=${esc(c.id)}" class="text-xs text-[#486C64] dark:text-gray-400 hover:text-emerald-700 dark:hover:text-emerald-400 flex items-center gap-1 font-semibold" title="View tasks for ${esc(c.code)}"><i data-lucide="calendar-check" class="w-3.5 h-3.5"></i>${Number(c.task_count || 0)} Task${Number(c.task_count || 0) === 1 ? '' : 's'}</a></div>
        </div>
      </div>
      <div class="course-card-actions">
        <a href="tasks.php?course_id=${esc(c.id)}" class="course-action" title="View tasks for ${esc(c.code)}"><i data-lucide="list-todo"></i>Tasks</a>
        <a href="tasks.php?course_id=${esc(c.id)}&add_task=1" class="course-action" title="Add task for ${esc(c.code)}"><i data-lucide="plus"></i>+ Task</a>
        <button class="course-action timer-course-btn text-emerald-700 dark:text-emerald-300" data-work-item-type="course" data-work-item-id="${esc(c.id)}" data-work-item-title="${esc(c.code)} — ${esc(c.name)}" data-work-complete="${progress >= 100 ? 'true' : 'false'}"><i data-lucide="timer"></i><span data-work-label>${progress >= 100 ? 'Completed' : 'Start'}</span></button>
        <button class="course-action edit-course-btn" data-id="${esc(c.id)}"><i data-lucide="pencil"></i>Edit</button>
        <button class="course-action delete-course-btn danger" data-id="${esc(c.id)}"><i data-lucide="trash-2"></i>Delete</button>
      </div>
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

  const activeSem = document.getElementById('curr-sum-name')?.textContent?.trim()
    || (ALL_COURSES.find(c => c.semester)?.semester)
    || '';
  if (activeSem && form.semester) {
    form.semester.value = activeSem;
  }

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
// ============================================================
// DATE UTILITY
// ============================================================
function addDays(dateStr, days) {
  if (!dateStr) return '';
  const parts = String(dateStr).split('-');
  if (parts.length < 3) return dateStr;
  const d = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
  d.setDate(d.getDate() + Number(days));
  const year = d.getFullYear();
  const month = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

// ============================================================
// SEMESTER BANNER
// ============================================================
async function loadSemesterBanner() {
  const banner = document.getElementById('semester-banner');
  if (!banner) return;
  try {
    const res = await fetch(`${API}/curriculum.php?context=1`, {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.ok) return;
    const ctx = data.context;
    if (ctx && ctx.has_semester) {
      banner.classList.remove('hidden');
      document.getElementById('semester-banner-title').textContent = ctx.semester_name || 'Active Semester';
      const badge = document.getElementById('semester-banner-badge');
      if (ctx.current_week_number) {
        badge.textContent = `Week ${ctx.current_week_number} of ${ctx.total_weeks} • ${ctx.current_phase || 'Active'}`;
        badge.className = 'px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300';
      } else {
        badge.textContent = ctx.current_phase || 'Scheduled';
        badge.className = 'px-2.5 py-0.5 rounded-full text-xs font-bold bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300';
      }
      let sub = `${ctx.start_date} to ${ctx.end_date}`;
      if (ctx.student_guidance) {
        sub += ` • ${ctx.student_guidance}`;
      }
      document.getElementById('semester-banner-subtitle').textContent = sub;
    } else {
      banner.classList.remove('hidden');
      document.getElementById('semester-banner-title').textContent = 'Academic Calendar Not Set';
      const badge = document.getElementById('semester-banner-badge');
      badge.textContent = 'Setup';
      badge.className = 'px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-300';
      document.getElementById('semester-banner-subtitle').textContent = 'Upload your curriculum or set semester dates to enable academic study guidance.';
    }
    if (window.lucide) window.lucide.createIcons();
  } catch (e) {
    console.error('Error loading semester banner:', e);
  }
}

// ============================================================
// DROPZONE HELPER
// ============================================================
function setupDropzone(dropzoneEl, fileInputEl, labelEl, onFileSelected) {
  if (!dropzoneEl || !fileInputEl) return;
  dropzoneEl.addEventListener('click', () => fileInputEl.click());
  fileInputEl.addEventListener('change', e => {
    if (e.target.files && e.target.files[0]) {
      const file = e.target.files[0];
      if (labelEl) {
        labelEl.textContent = `Selected: ${file.name} (${Math.round(file.size / 1024)} KB)`;
        labelEl.classList.remove('hidden');
      }
      onFileSelected(file);
    }
  });
  ['dragenter', 'dragover'].forEach(name => {
    dropzoneEl.addEventListener(name, e => {
      e.preventDefault();
      e.stopPropagation();
      dropzoneEl.classList.add('border-emerald-500', 'bg-emerald-50/50');
    });
  });
  ['dragleave', 'drop'].forEach(name => {
    dropzoneEl.addEventListener(name, e => {
      e.preventDefault();
      e.stopPropagation();
      dropzoneEl.classList.remove('border-emerald-500', 'bg-emerald-50/50');
    });
  });
  dropzoneEl.addEventListener('drop', e => {
    e.preventDefault();
    e.stopPropagation();
    if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) {
      const file = e.dataTransfer.files[0];
      fileInputEl.files = e.dataTransfer.files;
      if (labelEl) {
        labelEl.textContent = `Selected: ${file.name} (${Math.round(file.size / 1024)} KB)`;
        labelEl.classList.remove('hidden');
      }
      onFileSelected(file);
    }
  });
}

// ============================================================
// COURSE FORM IMPORT
// ============================================================
const courseImportModal = document.getElementById('course-import-modal');
const courseDropzone = document.getElementById('course-dropzone');
const courseFileInput = document.getElementById('course-file-input');
const courseTextInput = document.getElementById('course-text-input');
const courseFileChosen = document.getElementById('course-file-chosen');
const courseImportError = document.getElementById('course-import-error');
const courseReviewError = document.getElementById('course-review-error');
let selectedCourseFile = null;
let reviewedCoursesList = [];

function openCourseImportModal() {
  selectedCourseFile = null;
  if (courseFileInput) courseFileInput.value = '';
  if (courseTextInput) courseTextInput.value = '';
  if (courseFileChosen) { courseFileChosen.textContent = ''; courseFileChosen.classList.add('hidden'); }
  if (courseImportError) { courseImportError.textContent = ''; courseImportError.classList.add('hidden'); }
  if (courseReviewError) { courseReviewError.textContent = ''; courseReviewError.classList.add('hidden'); }
  document.getElementById('course-import-step-upload')?.classList.remove('hidden');
  document.getElementById('course-import-step-review')?.classList.add('hidden');
  courseImportModal?.classList.remove('hidden');
  if (window.lucide) window.lucide.createIcons();
}

function closeCourseImportModal() {
  courseImportModal?.classList.add('hidden');
}

setupDropzone(courseDropzone, courseFileInput, courseFileChosen, f => { selectedCourseFile = f; });

document.getElementById('open-import-course-form')?.addEventListener('click', openCourseImportModal);
document.getElementById('close-course-import-modal')?.addEventListener('click', closeCourseImportModal);
document.getElementById('cancel-course-import')?.addEventListener('click', closeCourseImportModal);
document.getElementById('back-to-upload-course')?.addEventListener('click', () => {
  document.getElementById('course-import-step-upload').classList.remove('hidden');
  document.getElementById('course-import-step-review').classList.add('hidden');
});

async function handleExtractCourses() {
  const btn = document.getElementById('extract-course-btn');
  const errEl = document.getElementById('course-import-error');
  errEl.classList.add('hidden');
  errEl.textContent = '';

  const textVal = courseTextInput ? courseTextInput.value.trim() : '';
  if (!selectedCourseFile && !textVal) {
    errEl.textContent = 'Please select a course registration document or paste course details.';
    errEl.classList.remove('hidden');
    return;
  }

  btn.disabled = true;
  btn.innerHTML = '<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> Extracting…';
  if (window.lucide) window.lucide.createIcons();

  try {
    let res;
    if (selectedCourseFile) {
      const fd = new FormData();
      fd.append('action', 'extract_form');
      fd.append('document', selectedCourseFile);
      fd.append('csrf_token', window.CSRF_TOKEN || '');
      res = await fetch(`${API}/course_import.php`, {
        method: 'POST',
        credentials: 'same-origin',
        body: fd
      });
    } else {
      res = await fetch(`${API}/course_import.php`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ action: 'extract_form', text: textVal, csrf_token: window.CSRF_TOKEN || '' })
      });
    }

    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.ok) {
      if (data.is_scanned) {
        throw new Error(data.error || "This document appears to be a scanned image or photo. Please paste the course list into the text box below or add them manually.");
      }
      throw new Error(data.error || "We couldn't extract courses from this file. Try another document or add courses manually.");
    }

    reviewedCoursesList = Array.isArray(data.courses) ? data.courses : (Array.isArray(data.items) ? data.items : []);
    if (reviewedCoursesList.length === 0) {
      throw new Error("No course codes were identified in this document. Please check the file or add courses manually.");
    }

    renderCourseReviewTable();
    document.getElementById('course-import-step-upload').classList.add('hidden');
    document.getElementById('course-import-step-review').classList.remove('hidden');
    if (window.lucide) window.lucide.createIcons();
  } catch (err) {
    errEl.textContent = err.message;
    errEl.classList.remove('hidden');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i data-lucide="sparkles" class="w-4 h-4"></i> Extract Courses';
    if (window.lucide) window.lucide.createIcons();
  }
}

document.getElementById('extract-course-btn')?.addEventListener('click', handleExtractCourses);

function renderCourseReviewTable() {
  const tbody = document.getElementById('course-review-tbody');
  if (!tbody) return;
  document.getElementById('course-review-count').textContent = `${reviewedCoursesList.length} course${reviewedCoursesList.length === 1 ? '' : 's'} ready for review`;
  tbody.innerHTML = reviewedCoursesList.map((c, idx) => {
    const statusPill = c.already_exists
      ? `<span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">Exists (skip)</span>`
      : `<span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">New</span>`;
    return `
      <tr data-index="${idx}" class="hover:bg-gray-50/50 dark:hover:bg-white/[0.02]">
        <td class="p-2"><input type="text" class="form-control text-xs font-bold uppercase p-1.5 min-h-[34px] w-24 sm:w-28" data-field="code" value="${esc(c.code || '')}" required></td>
        <td class="p-2"><input type="text" class="form-control text-xs p-1.5 min-h-[34px]" data-field="name" value="${esc(c.name || '')}" required></td>
        <td class="p-2"><input type="number" min="1" max="6" step="1" class="form-control text-xs p-1.5 min-h-[34px] w-16" data-field="credits" value="${Number(c.credits || 3)}"></td>
        <td class="p-2">${statusPill}</td>
        <td class="p-2 text-right"><button type="button" class="text-red-500 hover:text-red-700 p-1 remove-review-course-btn" title="Remove course"><i data-lucide="trash-2" class="w-4 h-4"></i></button></td>
      </tr>
    `;
  }).join('');

  tbody.querySelectorAll('.remove-review-course-btn').forEach(btn => {
    btn.addEventListener('click', e => {
      const tr = e.target.closest('tr');
      const idx = Number(tr.dataset.index);
      reviewedCoursesList.splice(idx, 1);
      renderCourseReviewTable();
    });
  });

  if (window.lucide) window.lucide.createIcons();
}

document.getElementById('add-review-row-btn')?.addEventListener('click', () => {
  reviewedCoursesList.push({ code: '', name: '', credits: 3, already_exists: false });
  renderCourseReviewTable();
  const inputs = document.querySelectorAll('#course-review-tbody input[data-field="code"]');
  if (inputs.length) inputs[inputs.length - 1].focus();
});

async function handleConfirmCourseImport() {
  const btn = document.getElementById('confirm-import-courses-btn');
  const errEl = document.getElementById('course-review-error');
  errEl.classList.add('hidden');
  errEl.textContent = '';

  const activeSem = document.getElementById('curr-sum-name')?.textContent?.trim()
    || (ALL_COURSES.find(c => c.semester)?.semester)
    || '';

  const rows = document.querySelectorAll('#course-review-tbody tr');
  const coursesToImport = [];
  rows.forEach(tr => {
    const code = tr.querySelector('input[data-field="code"]').value.trim();
    const name = tr.querySelector('input[data-field="name"]').value.trim();
    const credits = Number(tr.querySelector('input[data-field="credits"]').value || 3);
    if (code && name) {
      coursesToImport.push({ code, name, credits, semester: activeSem });
    }
  });

  if (coursesToImport.length === 0) {
    errEl.textContent = 'Please provide at least one valid course with a code and title.';
    errEl.classList.remove('hidden');
    return;
  }

  btn.disabled = true;
  btn.innerHTML = '<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> Saving…';
  if (window.lucide) window.lucide.createIcons();

  try {
    const res = await fetch(`${API}/course_import.php`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({
        action: 'confirm_import',
        semester: activeSem,
        courses: coursesToImport,
        csrf_token: window.CSRF_TOKEN || ''
      })
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.ok) {
      throw new Error(data.error || 'Could not import courses.');
    }

    closeCourseImportModal();
    window.showToast?.(`Import complete: ${data.imported_count} courses added${data.skipped_count > 0 ? `, ${data.skipped_count} skipped` : ''}`, 'success');
    await loadCourses();
  } catch (err) {
    errEl.textContent = err.message;
    errEl.classList.remove('hidden');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i data-lucide="check" class="w-4 h-4"></i> Confirm & Import Courses';
    if (window.lucide) window.lucide.createIcons();
  }
}

document.getElementById('confirm-import-courses-btn')?.addEventListener('click', handleConfirmCourseImport);

// ============================================================
// SEMESTER ACADEMIC CALENDAR & CURRICULUM
// ============================================================
const curriculumModal = document.getElementById('curriculum-modal');
const curriculumDropzone = document.getElementById('curriculum-dropzone');
const curriculumFileInput = document.getElementById('curriculum-file-input');
const curriculumTextInput = document.getElementById('curriculum-text-input');
const curriculumFileChosen = document.getElementById('curriculum-file-chosen');
const curriculumError = document.getElementById('curriculum-error');
let selectedCurriculumFile = null;
let currentSemesterData = null;
let reviewedCurriculumWeeks = [];

const WEEK_TYPE_OPTIONS = [
  { value: 'teaching', label: 'Teaching Week' },
  { value: 'student_week', label: 'Student Week' },
  { value: 'revision', label: 'Revision Week' },
  { value: 'exam', label: 'Examination Week' },
  { value: 'break', label: 'Break / Holiday' },
  { value: 'orientation', label: 'Orientation' },
  { value: 'other', label: 'Other' },
];

function generateDefaultWeeks(name, startMonday, numWeeks) {
  const weeks = [];
  const count = Math.max(4, Math.min(24, Number(numWeeks) || 14));
  for (let i = 1; i <= count; i++) {
    const wStart = addDays(startMonday, (i - 1) * 7);
    const wEnd = addDays(wStart, 6);
    let type = 'teaching';
    let label = `Teaching Week ${i}`;

    if (i === 1) {
      label = 'Lectures Begin';
    } else if (i === Math.floor(count / 2)) {
      type = 'student_week';
      label = 'Mid-term / Student Week';
    } else if (i === count - 1) {
      type = 'revision';
      label = 'Revision Week';
    } else if (i === count) {
      type = 'exam';
      label = 'Examination Week';
    }

    weeks.push({
      week_number: i,
      week_type: type,
      label: label,
      start_date: wStart,
      end_date: wEnd,
      notes: ''
    });
  }
  reviewedCurriculumWeeks = weeks;
  document.getElementById('curr-review-name').value = name || 'First Semester';
  document.getElementById('curr-review-start').value = startMonday;
  document.getElementById('curr-review-end').value = addDays(startMonday, count * 7 - 1);
  renderCurriculumWeeksTable();
}

async function fetchCurriculum() {
  try {
    const res = await fetch(`${API}/curriculum.php`, {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    });
    const data = await res.json().catch(() => ({}));
    if (res.ok && data.ok) {
      currentSemesterData = data;
      renderActiveCurriculumSummary(data);
    }
  } catch (e) {
    console.error('Failed to load curriculum:', e);
  }
}

function renderActiveCurriculumSummary(data) {
  const sumBox = document.getElementById('curriculum-active-summary');
  if (!sumBox) return;
  if (data && data.semester) {
    sumBox.classList.remove('hidden');
    document.getElementById('curr-sum-name').textContent = data.semester.name;
    const badge = document.getElementById('curr-sum-badge');
    badge.textContent = data.context?.current_phase || 'Active';
    document.getElementById('curr-sum-dates').textContent = `${data.semester.start_date} to ${data.semester.end_date} (${data.weeks?.length || 0} Academic Weeks)`;
    document.getElementById('curr-sum-guidance').textContent = data.context?.student_guidance || '';

    document.getElementById('curr-review-name').value = data.semester.name;
    document.getElementById('curr-review-start').value = data.semester.start_date;
    document.getElementById('curr-review-end').value = data.semester.end_date;
    reviewedCurriculumWeeks = Array.isArray(data.weeks) ? [...data.weeks] : [];
    renderCurriculumWeeksTable();
  } else {
    sumBox.classList.add('hidden');
    const today = new Date();
    const day = today.getDay();
    const diff = today.getDate() - day + (day === 0 ? -6 : 1);
    const monday = new Date(today.setDate(diff));
    const year = monday.getFullYear();
    const month = String(monday.getMonth() + 1).padStart(2, '0');
    const dStr = String(monday.getDate()).padStart(2, '0');
    const startStr = `${year}-${month}-${dStr}`;

    document.getElementById('manual-sem-start').value = startStr;
    document.getElementById('curr-review-start').value = startStr;
    document.getElementById('curr-review-end').value = addDays(startStr, 14 * 7 - 1);
    if (!reviewedCurriculumWeeks.length) {
      generateDefaultWeeks('First Semester', startStr, 14);
    }
  }
}

function openCurriculumModal() {
  selectedCurriculumFile = null;
  if (curriculumFileInput) curriculumFileInput.value = '';
  if (curriculumTextInput) curriculumTextInput.value = '';
  if (curriculumFileChosen) { curriculumFileChosen.textContent = ''; curriculumFileChosen.classList.add('hidden'); }
  if (curriculumError) { curriculumError.textContent = ''; curriculumError.classList.add('hidden'); }
  curriculumModal?.classList.remove('hidden');
  fetchCurriculum();
  if (window.lucide) window.lucide.createIcons();
}

function closeCurriculumModal() {
  curriculumModal?.classList.add('hidden');
}

setupDropzone(curriculumDropzone, curriculumFileInput, curriculumFileChosen, f => { selectedCurriculumFile = f; });

document.getElementById('open-curriculum-modal')?.addEventListener('click', openCurriculumModal);
document.getElementById('banner-view-calendar-btn')?.addEventListener('click', openCurriculumModal);
document.getElementById('close-curriculum-modal')?.addEventListener('click', closeCurriculumModal);
document.getElementById('cancel-curriculum')?.addEventListener('click', closeCurriculumModal);

// Tabs in Curriculum modal
const tabUpload = document.getElementById('curriculum-tab-upload');
const tabManual = document.getElementById('curriculum-tab-manual');
const panelUpload = document.getElementById('curriculum-panel-upload');
const panelManual = document.getElementById('curriculum-panel-manual');

tabUpload?.addEventListener('click', () => {
  tabUpload.className = 'pb-2.5 border-b-2 border-emerald-600 text-emerald-700 dark:text-emerald-400';
  tabManual.className = 'pb-2.5 border-b-2 border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700';
  panelUpload.classList.remove('hidden');
  panelManual.classList.add('hidden');
});

tabManual?.addEventListener('click', () => {
  tabManual.className = 'pb-2.5 border-b-2 border-emerald-600 text-emerald-700 dark:text-emerald-400';
  tabUpload.className = 'pb-2.5 border-b-2 border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700';
  panelManual.classList.remove('hidden');
  panelUpload.classList.add('hidden');
});

document.getElementById('generate-manual-weeks-btn')?.addEventListener('click', () => {
  const name = document.getElementById('manual-sem-name').value.trim() || 'First Semester';
  const start = document.getElementById('manual-sem-start').value;
  const count = Number(document.getElementById('manual-sem-weeks').value) || 14;
  if (!start) {
    alert('Please choose a start date (Monday) for the semester.');
    return;
  }
  generateDefaultWeeks(name, start, count);
  window.showToast?.(`Generated ${count} academic weeks. Review and adjust below before saving.`, 'info');
});

async function handleExtractCurriculum() {
  const btn = document.getElementById('extract-curriculum-btn');
  const errEl = document.getElementById('curriculum-error');
  errEl.classList.add('hidden');
  errEl.textContent = '';

  const textVal = curriculumTextInput ? curriculumTextInput.value.trim() : '';
  if (!selectedCurriculumFile && !textVal) {
    errEl.textContent = 'Please select an academic calendar document or paste text.';
    errEl.classList.remove('hidden');
    return;
  }

  btn.disabled = true;
  btn.innerHTML = '<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> Extracting…';
  if (window.lucide) window.lucide.createIcons();

  try {
    let res;
    if (selectedCurriculumFile) {
      const fd = new FormData();
      fd.append('action', 'extract_document');
      fd.append('document', selectedCurriculumFile);
      fd.append('csrf_token', window.CSRF_TOKEN || '');
      res = await fetch(`${API}/curriculum.php`, {
        method: 'POST',
        credentials: 'same-origin',
        body: fd
      });
    } else {
      res = await fetch(`${API}/curriculum.php`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ action: 'extract_document', text: textVal, csrf_token: window.CSRF_TOKEN || '' })
      });
    }

    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.ok) {
      throw new Error(data.error || "We couldn't read this file. Try another document or enter the information manually.");
    }

    const ext = data.extracted || {};
    if (ext.semester_name) document.getElementById('curr-review-name').value = ext.semester_name;
    if (ext.start_date) document.getElementById('curr-review-start').value = ext.start_date;
    if (ext.end_date) document.getElementById('curr-review-end').value = ext.end_date;

    reviewedCurriculumWeeks = Array.isArray(ext.weeks) && ext.weeks.length ? ext.weeks : [];
    if (!reviewedCurriculumWeeks.length) {
      generateDefaultWeeks(ext.semester_name || 'First Semester', ext.start_date || document.getElementById('curr-review-start').value, 14);
    } else {
      renderCurriculumWeeksTable();
    }

    window.showToast?.('Calendar timeline extracted. Review the weeks below before saving.', 'info');
  } catch (err) {
    errEl.textContent = err.message;
    errEl.classList.remove('hidden');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i data-lucide="sparkles" class="w-3.5 h-3.5"></i> Extract & Review Calendar';
    if (window.lucide) window.lucide.createIcons();
  }
}

document.getElementById('extract-curriculum-btn')?.addEventListener('click', handleExtractCurriculum);

function renderCurriculumWeeksTable() {
  const tbody = document.getElementById('curriculum-weeks-tbody');
  if (!tbody) return;
  tbody.innerHTML = reviewedCurriculumWeeks.map((w, idx) => {
    const opts = WEEK_TYPE_OPTIONS.map(o => `<option value="${o.value}" ${w.week_type === o.value ? 'selected' : ''}>${o.label}</option>`).join('');
    return `
      <tr data-index="${idx}" class="hover:bg-gray-50/50 dark:hover:bg-white/[0.02]">
        <td class="p-1.5 font-bold text-center text-gray-500 dark:text-gray-400">
          <input type="number" min="1" max="52" class="form-control text-xs p-1 min-h-[30px] w-12 text-center" data-field="week_number" value="${w.week_number ?? (idx + 1)}">
        </td>
        <td class="p-1.5">
          <select class="form-control text-xs p-1 min-h-[30px]" data-field="week_type">
            ${opts}
          </select>
        </td>
        <td class="p-1.5">
          <input type="text" class="form-control text-xs p-1 min-h-[30px]" data-field="label" value="${esc(w.label || '')}" placeholder="Week description">
        </td>
        <td class="p-1.5">
          <input type="date" class="form-control text-xs p-1 min-h-[30px]" data-field="start_date" value="${w.start_date || ''}">
        </td>
        <td class="p-1.5">
          <input type="date" class="form-control text-xs p-1 min-h-[30px]" data-field="end_date" value="${w.end_date || ''}">
        </td>
        <td class="p-1.5 text-right">
          <button type="button" class="text-red-500 hover:text-red-700 p-1 remove-curriculum-week-btn" title="Remove week"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button>
        </td>
      </tr>
    `;
  }).join('');

  tbody.querySelectorAll('.remove-curriculum-week-btn').forEach(btn => {
    btn.addEventListener('click', e => {
      const tr = e.target.closest('tr');
      const idx = Number(tr.dataset.index);
      reviewedCurriculumWeeks.splice(idx, 1);
      renderCurriculumWeeksTable();
    });
  });

  if (window.lucide) window.lucide.createIcons();
}

document.getElementById('add-curriculum-week-btn')?.addEventListener('click', () => {
  const lastWeek = reviewedCurriculumWeeks[reviewedCurriculumWeeks.length - 1];
  const nextNum = (lastWeek ? Number(lastWeek.week_number) : 0) + 1;
  const nextStart = lastWeek && lastWeek.end_date ? addDays(lastWeek.end_date, 1) : document.getElementById('curr-review-start').value;
  const nextEnd = addDays(nextStart, 6);
  reviewedCurriculumWeeks.push({
    week_number: nextNum,
    week_type: 'teaching',
    label: `Week ${nextNum}`,
    start_date: nextStart,
    end_date: nextEnd,
    notes: ''
  });
  renderCurriculumWeeksTable();
});

async function handleSaveCurriculum() {
  const btn = document.getElementById('save-curriculum-btn');
  const errEl = document.getElementById('curriculum-error');
  errEl.classList.add('hidden');
  errEl.textContent = '';

  const name = document.getElementById('curr-review-name').value.trim();
  const startDate = document.getElementById('curr-review-start').value.trim();
  const endDate = document.getElementById('curr-review-end').value.trim();

  if (!name) {
    errEl.textContent = 'Please enter a semester name.';
    errEl.classList.remove('hidden');
    return;
  }
  if (!startDate || !endDate) {
    errEl.textContent = 'Please set valid start and end dates.';
    errEl.classList.remove('hidden');
    return;
  }
  if (endDate <= startDate) {
    errEl.textContent = 'Semester end date must be after the start date.';
    errEl.classList.remove('hidden');
    return;
  }

  const rows = document.querySelectorAll('#curriculum-weeks-tbody tr');
  const weeksPayload = [];
  rows.forEach(tr => {
    const wNum = Number(tr.querySelector('input[data-field="week_number"]').value) || (weeksPayload.length + 1);
    const type = tr.querySelector('select[data-field="week_type"]').value;
    const label = tr.querySelector('input[data-field="label"]').value.trim();
    const wStart = tr.querySelector('input[data-field="start_date"]').value.trim();
    const wEnd = tr.querySelector('input[data-field="end_date"]').value.trim();
    weeksPayload.push({
      week_number: wNum,
      week_type: type,
      label: label || `Week ${wNum}`,
      start_date: wStart || startDate,
      end_date: wEnd || endDate,
      notes: ''
    });
  });

  if (weeksPayload.length === 0) {
    errEl.textContent = 'Please add at least one week to the calendar.';
    errEl.classList.remove('hidden');
    return;
  }

  btn.disabled = true;
  btn.innerHTML = '<i data-lucide="loader-2" class="w-3.5 h-3.5 animate-spin"></i> Saving…';
  if (window.lucide) window.lucide.createIcons();

  try {
    const res = await fetch(`${API}/curriculum.php`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({
        action: 'save_curriculum',
        semester_name: name,
        start_date: startDate,
        end_date: endDate,
        weeks: weeksPayload,
        csrf_token: window.CSRF_TOKEN || ''
      })
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.ok) {
      throw new Error(data.error || 'Could not save semester calendar.');
    }

    closeCurriculumModal();
    window.showToast?.('Semester academic calendar saved successfully!', 'success');
    await loadSemesterBanner();
  } catch (err) {
    errEl.textContent = err.message;
    errEl.classList.remove('hidden');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i data-lucide="check" class="w-3.5 h-3.5"></i> Save Semester Calendar';
    if (window.lucide) window.lucide.createIcons();
  }
}

document.getElementById('save-curriculum-btn')?.addEventListener('click', handleSaveCurriculum);
document.getElementById('btn-edit-existing-curriculum')?.addEventListener('click', () => {
  document.getElementById('curriculum-review-section')?.scrollIntoView({ behavior: 'smooth' });
});

document.getElementById('btn-delete-curriculum')?.addEventListener('click', async () => {
  if (!currentSemesterData?.semester?.id) return;
  if (!confirm(`Delete semester "${currentSemesterData.semester.name}" and its academic calendar?`)) return;
  try {
    const res = await fetch(`${API}/curriculum.php`, {
      method: 'DELETE',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
      body: new URLSearchParams({ semester_id: String(currentSemesterData.semester.id), csrf_token: window.CSRF_TOKEN || '' }).toString()
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.ok) throw new Error(data.error || 'Could not delete semester calendar.');
    closeCurriculumModal();
    window.showToast?.('Semester calendar deleted', 'success');
    await loadSemesterBanner();
  } catch (e) {
    window.showToast?.(e.message, 'error');
  }
});

// Auto-open modals based on URL parameter
function checkUrlParams() {
  const p = new URLSearchParams(window.location.search);
  if (p.get('open_curriculum') === '1' || p.get('open_calendar') === '1') {
    openCurriculumModal();
  } else if (p.get('import_form') === '1' || p.get('open_form') === '1' || p.get('import') === '1') {
    openCourseImportModal();
  } else if (p.get('add') === '1' || p.get('new') === '1' || p.get('open_add') === '1') {
    openAddCourse();
  }
}

window.APP_READY.then(me => {
  if (me) {
    loadCourses();
    checkUrlParams();
  }
});

