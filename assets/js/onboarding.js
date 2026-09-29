/**
 * Student Study Planner — Academic Onboarding Controller
 * Manages guided 7-step onboarding state machine, document parsing, and persistence.
 */

(function () {
  'use strict';

  const API = '../api';
  let csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

  const STEPS = [
    { num: 1, label: 'About You', short: 'Profile' },
    { num: 2, label: 'Studies', short: 'Studies' },
    { num: 3, label: 'Courses', short: 'Courses' },
    { num: 4, label: 'Timetable', short: 'Timetable' },
    { num: 5, label: 'Curriculum', short: 'Calendar' },
    { num: 6, label: 'Review', short: 'Review' },
    { num: 7, label: 'Goals', short: 'Goals' },
  ];

  let currentStep = 1;
  let userData = {
    full_name: '',
    email: '',
    program: '',
    level: 'Level 400',
    current_semester: 'First Semester',
    academic_session: '',
    weekly_goal_hours: 15,
    preferred_study_days: '1,2,3,4,5',
    preferred_study_time: 'morning',
    onboarding_completed: false,
    onboarding_step: 1,
  };

  let coursesList = [];
  let timetableList = [];
  let curriculumData = {
    semester_name: 'First Semester',
    start_date: '',
    end_date: '',
    weeks: []
  };

  // Toast / Notice Helper
  function showNotice(message, type = 'error') {
    const el = document.getElementById('onboarding-notice');
    const textEl = document.getElementById('notice-text');
    const iconEl = document.getElementById('notice-icon');
    if (!el || !textEl) return;

    textEl.textContent = message;
    el.className = 'mb-6 p-4 rounded-xl text-sm font-medium transition flex items-center justify-between ';

    if (type === 'error') {
      el.className += 'bg-red-50 dark:bg-red-950/40 text-red-800 dark:text-red-300 border border-red-200 dark:border-red-900/50';
      if (iconEl) iconEl.setAttribute('data-lucide', 'alert-circle');
    } else if (type === 'success') {
      el.className += 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-900/50';
      if (iconEl) iconEl.setAttribute('data-lucide', 'check-circle-2');
    } else {
      el.className += 'bg-blue-50 dark:bg-blue-950/40 text-blue-800 dark:text-blue-300 border border-blue-200 dark:border-blue-900/50';
      if (iconEl) iconEl.setAttribute('data-lucide', 'info');
    }

    el.classList.remove('hidden');
    if (window.lucide) window.lucide.createIcons();
    el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function hideNotice() {
    const el = document.getElementById('onboarding-notice');
    if (el) el.classList.add('hidden');
  }

  // Render Step Progress Indicators
  function renderStepIndicators() {
    const container = document.getElementById('step-indicators');
    if (!container) return;

    container.innerHTML = STEPS.map(s => {
      let badgeClass = 'step-badge-pending';
      let icon = s.num;

      if (s.num === currentStep) {
        badgeClass = 'step-badge-active';
      } else if (s.num < currentStep) {
        badgeClass = 'step-badge-completed';
        icon = '✓';
      }

      return `
        <button type="button" onclick="window.goToStep(${s.num})" 
          class="flex items-center gap-2 px-3 py-1.5 rounded-xl border text-xs font-semibold whitespace-nowrap transition cursor-pointer ${badgeClass}">
          <span class="w-5 h-5 rounded-full flex items-center justify-center text-[10px] font-bold border border-current">${icon}</span>
          <span>${s.label}</span>
        </button>
      `;
    }).join('');

    const fillPercent = Math.min(100, Math.round(((currentStep - 1) / (STEPS.length - 1)) * 100));
    const fillEl = document.getElementById('progress-bar-fill');
    if (fillEl) {
      fillEl.style.width = `${Math.max(14, fillPercent)}%`;
    }

    if (window.lucide) window.lucide.createIcons();
  }

  // Step Navigation
  window.goToStep = function (stepNum) {
    if (stepNum < 1 || stepNum > 7) return;
    hideNotice();

    currentStep = stepNum;

    // Toggle panes
    for (let i = 1; i <= 7; i++) {
      const pane = document.getElementById(`step-pane-${i}`);
      if (pane) {
        if (i === currentStep) {
          pane.classList.remove('hidden');
        } else {
          pane.classList.add('hidden');
        }
      }
    }

    const readyPane = document.getElementById('step-pane-ready');
    if (readyPane) readyPane.classList.add('hidden');

    renderStepIndicators();

    if (currentStep === 6) {
      populateReview();
    }

    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  // Initialize Data from Backend
  async function initOnboarding() {
    try {
      const res = await fetch(`${API}/onboarding.php`, { credentials: 'same-origin' });
      if (!res.ok) {
        if (res.status === 401) {
          window.location.href = 'login.php';
          return;
        }
        throw new Error('Failed to load onboarding state.');
      }

      const data = await res.json();
      if (data.csrf_token) {
        csrfToken = data.csrf_token;
      }

      if (data.user) {
        userData = Object.assign(userData, data.user);
        
        // If user is already completed and no edit flag, redirect to dashboard
        const urlParams = new URLSearchParams(window.location.search);
        if (userData.onboarding_completed && !urlParams.get('edit') && !urlParams.get('force')) {
          window.location.replace('./dashboard.php');
          return;
        }

        // Prepopulate Profile fields
        const nameInput = document.getElementById('input-fullname');
        if (nameInput) nameInput.value = userData.full_name || '';

        const emailText = document.getElementById('email-text');
        if (emailText) emailText.textContent = userData.email || '';

        const progInput = document.getElementById('input-programme');
        if (progInput) progInput.value = userData.program || '';

        const levelInput = document.getElementById('input-level');
        if (levelInput && userData.level) levelInput.value = userData.level;

        const semInput = document.getElementById('input-semester');
        if (semInput && userData.current_semester) semInput.value = userData.current_semester;

        const sessInput = document.getElementById('input-session');
        if (sessInput && userData.academic_session) sessInput.value = userData.academic_session;
      }

      // Populate courses if already existing
      if (Array.isArray(data.courses) && data.courses.length > 0) {
        coursesList = data.courses.map(c => ({
          id: c.id,
          code: c.code,
          name: c.name,
          credits: Number(c.credits || 3),
          semester: c.semester || userData.current_semester
        }));
        renderCoursesTable();
      }

      // Populate timetable if already existing
      if (Array.isArray(data.timetable) && data.timetable.length > 0) {
        timetableList = data.timetable.map(t => ({
          day_of_week: Number(t.day_of_week || 1),
          course_id: t.course_id,
          course_code: t.course_code || '',
          title: t.title || '',
          start_time: t.start_time || '09:00:00',
          end_time: t.end_time || '11:00:00',
        }));
        renderTimetableTable();
      }

      // Populate curriculum if already existing
      if (data.curriculum && data.curriculum.semester) {
        const sem = data.curriculum.semester;
        curriculumData.semester_name = sem.name || userData.current_semester;
        curriculumData.start_date = sem.start_date || '';
        curriculumData.end_date = sem.end_date || '';
        curriculumData.weeks = Array.isArray(data.curriculum.weeks) ? data.curriculum.weeks : [];

        const startInp = document.getElementById('input-sem-start');
        if (startInp) startInp.value = curriculumData.start_date;
        const endInp = document.getElementById('input-sem-end');
        if (endInp) endInp.value = curriculumData.end_date;
      }

      // Set initial step based on saved progress or query param
      const urlParams = new URLSearchParams(window.location.search);
      const queryStep = parseInt(urlParams.get('step') || '0', 10);
      if (queryStep >= 1 && queryStep <= 7) {
        window.goToStep(queryStep);
      } else {
        const initialStep = Math.min(7, Math.max(1, userData.onboarding_step || 1));
        window.goToStep(initialStep);
      }

    } catch (err) {
      console.error('[Onboarding Init Error]', err);
      showNotice(err.message || 'Unable to connect to the server. Please refresh.');
    }
  }

  // =========================================================================
  // STEP 1 & 2: PROFILE & STUDIES
  // =========================================================================
  const btnStep1Next = document.getElementById('btn-step1-next');
  if (btnStep1Next) {
    btnStep1Next.addEventListener('click', () => {
      const name = document.getElementById('input-fullname')?.value.trim();
      if (!name) {
        showNotice('Please enter your full name.', 'error');
        return;
      }
      userData.full_name = name;
      window.goToStep(2);
    });
  }

  const btnStep2Next = document.getElementById('btn-step2-next');
  if (btnStep2Next) {
    btnStep2Next.addEventListener('click', async () => {
      const prog = document.getElementById('input-programme')?.value.trim();
      const level = document.getElementById('input-level')?.value.trim();
      const sem = document.getElementById('input-semester')?.value.trim();
      const sess = document.getElementById('input-session')?.value.trim();

      if (!prog) {
        showNotice('Please specify your programme or department.', 'error');
        return;
      }
      if (!level) {
        showNotice('Please select your current level.', 'error');
        return;
      }
      if (!sem) {
        showNotice('Please select your current semester.', 'error');
        return;
      }

      btnStep2Next.disabled = true;
      btnStep2Next.innerHTML = `<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> Saving...`;
      if (window.lucide) window.lucide.createIcons();

      try {
        const res = await fetch(`${API}/onboarding.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({
            csrf_token: csrfToken,
            action: 'save_profile',
            full_name: userData.full_name,
            program: prog,
            level: level,
            current_semester: sem,
            academic_session: sess,
          })
        });

        const data = await res.json();
        if (!res.ok || !data.ok) {
          throw new Error(data.error || 'Failed to save profile.');
        }

        userData.program = prog;
        userData.level = level;
        userData.current_semester = sem;
        userData.academic_session = sess;

        showNotice('Profile saved successfully!', 'success');
        setTimeout(() => {
          hideNotice();
          window.goToStep(3);
        }, 350);

      } catch (err) {
        showNotice(err.message, 'error');
      } finally {
        btnStep2Next.disabled = false;
        btnStep2Next.innerHTML = `<span>Next: Courses Registration</span><i data-lucide="arrow-right" class="w-4 h-4"></i>`;
        if (window.lucide) window.lucide.createIcons();
      }
    });
  }

  // =========================================================================
  // STEP 3: COURSES REGISTRATION IMPORT
  // =========================================================================
  window.switchCourseInputMode = function (mode) {
    const fileMode = document.getElementById('course-mode-file');
    const textMode = document.getElementById('course-mode-text');
    const tabFile = document.getElementById('course-tab-file');
    const tabText = document.getElementById('course-tab-text');
    const tabManual = document.getElementById('course-tab-manual');

    const activeClass = 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300';
    const inactiveClass = 'text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5';

    tabFile.className = `px-3.5 py-1.5 rounded-lg text-xs font-semibold transition flex items-center gap-1.5 ${mode === 'file' ? activeClass : inactiveClass}`;
    tabText.className = `px-3.5 py-1.5 rounded-lg text-xs font-semibold transition flex items-center gap-1.5 ${mode === 'text' ? activeClass : inactiveClass}`;
    tabManual.className = `px-3.5 py-1.5 rounded-lg text-xs font-semibold transition flex items-center gap-1.5 ${mode === 'manual' ? activeClass : inactiveClass}`;

    if (mode === 'file') {
      fileMode.classList.remove('hidden');
      textMode.classList.add('hidden');
    } else if (mode === 'text') {
      fileMode.classList.add('hidden');
      textMode.classList.remove('hidden');
    } else {
      fileMode.classList.add('hidden');
      textMode.classList.add('hidden');
      addNewCourseRow();
    }
  };

  // Course file dropzone
  const courseDropzone = document.getElementById('course-dropzone');
  const courseFileInput = document.getElementById('course-file-input');
  const btnParseCourseFile = document.getElementById('btn-parse-course-file');

  if (courseDropzone && courseFileInput) {
    courseDropzone.addEventListener('click', () => courseFileInput.click());
    courseDropzone.addEventListener('dragover', (e) => {
      e.preventDefault();
      courseDropzone.classList.add('border-emerald-500', 'bg-emerald-50/20');
    });
    courseDropzone.addEventListener('dragleave', () => {
      courseDropzone.classList.remove('border-emerald-500', 'bg-emerald-50/20');
    });
    courseDropzone.addEventListener('drop', (e) => {
      e.preventDefault();
      courseDropzone.classList.remove('border-emerald-500', 'bg-emerald-50/20');
      if (e.dataTransfer.files.length) {
        courseFileInput.files = e.dataTransfer.files;
        handleCourseFileSelected(e.dataTransfer.files[0]);
      }
    });

    courseFileInput.addEventListener('change', () => {
      if (courseFileInput.files.length) {
        handleCourseFileSelected(courseFileInput.files[0]);
      }
    });
  }

  function handleCourseFileSelected(file) {
    const nameEl = document.getElementById('course-file-name');
    const displayEl = document.getElementById('course-filename-display');
    if (nameEl) nameEl.textContent = `${file.name} (${Math.round(file.size / 1024)} KB)`;
    if (displayEl) displayEl.classList.remove('hidden');
    if (btnParseCourseFile) {
      btnParseCourseFile.classList.remove('hidden');
      btnParseCourseFile.click(); // Auto-parse on selection
    }
  }

  if (btnParseCourseFile) {
    btnParseCourseFile.addEventListener('click', async () => {
      const file = courseFileInput?.files[0];
      if (!file) return;

      btnParseCourseFile.disabled = true;
      btnParseCourseFile.innerHTML = `<i data-lucide="loader-2" class="w-3.5 h-3.5 animate-spin"></i> Parsing document...`;
      if (window.lucide) window.lucide.createIcons();

      const formData = new FormData();
      formData.append('csrf_token', csrfToken);
      formData.append('action', 'parse_document');
      formData.append('domain', 'courses');
      formData.append('document', file);

      try {
        const res = await fetch(`${API}/onboarding.php`, {
          method: 'POST',
          credentials: 'same-origin',
          body: formData,
        });
        const data = await res.json();
        if (!res.ok || !data.ok) {
          throw new Error(data.error || 'Could not extract courses.');
        }

        if (Array.isArray(data.items) && data.items.length > 0) {
          mergeExtractedCourses(data.items);
          showNotice(`Successfully extracted ${data.items.length} courses! Review and edit them below.`, 'success');
        } else {
          showNotice('No recognizable course codes found in this file. Try another document or add manually.', 'info');
        }
      } catch (err) {
        showNotice(err.message, 'error');
      } finally {
        btnParseCourseFile.disabled = false;
        btnParseCourseFile.innerHTML = `<i data-lucide="cpu" class="w-3.5 h-3.5"></i> Extract Courses`;
        if (window.lucide) window.lucide.createIcons();
      }
    });
  }

  const btnParseCourseText = document.getElementById('btn-parse-course-text');
  if (btnParseCourseText) {
    btnParseCourseText.addEventListener('click', async () => {
      const text = document.getElementById('course-text-input')?.value.trim();
      if (!text) {
        showNotice('Please paste your course list text first.', 'error');
        return;
      }

      btnParseCourseText.disabled = true;
      btnParseCourseText.innerHTML = `<i data-lucide="loader-2" class="w-3.5 h-3.5 animate-spin"></i> Extracting...`;
      if (window.lucide) window.lucide.createIcons();

      try {
        const res = await fetch(`${API}/onboarding.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({
            csrf_token: csrfToken,
            action: 'parse_document',
            domain: 'courses',
            text: text,
          })
        });
        const data = await res.json();
        if (!res.ok || !data.ok) {
          throw new Error(data.error || 'Could not extract courses from text.');
        }

        if (Array.isArray(data.items) && data.items.length > 0) {
          mergeExtractedCourses(data.items);
          showNotice(`Extracted ${data.items.length} courses! Review and edit them below.`, 'success');
        } else {
          showNotice('No recognizable course codes found in pasted text. Example: CSC 411 Computer Networks 3 Units', 'info');
        }
      } catch (err) {
        showNotice(err.message, 'error');
      } finally {
        btnParseCourseText.disabled = false;
        btnParseCourseText.innerHTML = `<i data-lucide="cpu" class="w-3.5 h-3.5"></i> Extract from Text`;
        if (window.lucide) window.lucide.createIcons();
      }
    });
  }

  function mergeExtractedCourses(extracted) {
    const existingMap = new Map();
    coursesList.forEach(c => existingMap.set(c.code.toUpperCase(), c));

    extracted.forEach(item => {
      const code = (item.code || '').trim().toUpperCase();
      if (!code) return;
      if (!existingMap.has(code)) {
        const newCourse = {
          code: code,
          name: (item.name || item.title || code).trim(),
          credits: maxMinInt(item.credits || item.units || 3, 1, 6),
          semester: userData.current_semester,
        };
        coursesList.push(newCourse);
        existingMap.set(code, newCourse);
      }
    });

    renderCoursesTable();
  }

  window.addNewCourseRow = function () {
    coursesList.push({
      code: '',
      name: '',
      credits: 3,
      semester: userData.current_semester,
    });
    renderCoursesTable();
    // Focus new code input
    const inputs = document.querySelectorAll('#courses-tbody input.course-code-input');
    if (inputs.length) {
      inputs[inputs.length - 1].focus();
    }
  };

  window.removeCourseRow = function (idx) {
    coursesList.splice(idx, 1);
    renderCoursesTable();
  };

  function renderCoursesTable() {
    const tbody = document.getElementById('courses-tbody');
    const badge = document.getElementById('courses-count-badge');
    if (!tbody) return;

    if (badge) {
      const totalUnits = coursesList.reduce((acc, c) => acc + (Number(c.credits) || 0), 0);
      badge.textContent = `${coursesList.length} course${coursesList.length === 1 ? '' : 's'} • ${totalUnits} units`;
    }

    if (!coursesList.length) {
      tbody.innerHTML = `
        <tr id="courses-empty-row">
          <td colspan="4" class="p-6 text-center text-gray-400 dark:text-gray-500">
            No courses added yet. Upload your form, paste text, or click "Add Course".
          </td>
        </tr>
      `;
      return;
    }

    tbody.innerHTML = coursesList.map((c, i) => `
      <tr class="hover:bg-gray-50/50 dark:hover:bg-white/[0.02] transition">
        <td class="p-2.5">
          <input type="text" value="${escapeHtml(c.code)}" onchange="updateCourseField(${i}, 'code', this.value)"
            placeholder="e.g. CSC 411" class="course-code-input w-full px-2.5 py-1.5 rounded-lg border border-gray-200 dark:border-white/10 bg-transparent text-xs font-bold uppercase tracking-wider outline-none focus:border-emerald-600">
        </td>
        <td class="p-2.5">
          <input type="text" value="${escapeHtml(c.name)}" onchange="updateCourseField(${i}, 'name', this.value)"
            placeholder="e.g. Computer Networks" class="w-full px-2.5 py-1.5 rounded-lg border border-gray-200 dark:border-white/10 bg-transparent text-xs font-medium outline-none focus:border-emerald-600">
        </td>
        <td class="p-2.5 text-center">
          <input type="number" min="1" max="6" value="${c.credits}" onchange="updateCourseField(${i}, 'credits', this.value)"
            class="w-14 mx-auto text-center px-1.5 py-1.5 rounded-lg border border-gray-200 dark:border-white/10 bg-transparent text-xs font-bold outline-none focus:border-emerald-600">
        </td>
        <td class="p-2.5 text-center">
          <button type="button" onclick="removeCourseRow(${i})" class="text-gray-400 hover:text-red-500 p-1.5 rounded-lg transition" title="Remove course">
            <i data-lucide="trash-2" class="w-4 h-4"></i>
          </button>
        </td>
      </tr>
    `).join('');

    if (window.lucide) window.lucide.createIcons();
  }

  window.updateCourseField = function (idx, field, val) {
    if (!coursesList[idx]) return;
    if (field === 'code') {
      coursesList[idx].code = val.trim().toUpperCase();
    } else if (field === 'credits') {
      coursesList[idx].credits = maxMinInt(val, 1, 6);
    } else {
      coursesList[idx][field] = val.trim();
    }
    const badge = document.getElementById('courses-count-badge');
    if (badge) {
      const totalUnits = coursesList.reduce((acc, c) => acc + (Number(c.credits) || 0), 0);
      badge.textContent = `${coursesList.length} course${coursesList.length === 1 ? '' : 's'} • ${totalUnits} units`;
    }
  };

  const btnStep3Next = document.getElementById('btn-step3-next');
  if (btnStep3Next) {
    btnStep3Next.addEventListener('click', async () => {
      const validCourses = coursesList.filter(c => c.code.trim() && c.name.trim());
      if (!validCourses.length) {
        showNotice('Please add at least one valid course with a course code and title.', 'error');
        return;
      }

      btnStep3Next.disabled = true;
      btnStep3Next.innerHTML = `<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> Saving Courses...`;
      if (window.lucide) window.lucide.createIcons();

      try {
        const res = await fetch(`${API}/onboarding.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({
            csrf_token: csrfToken,
            action: 'save_courses',
            courses: validCourses,
          })
        });
        const data = await res.json();
        if (!res.ok || !data.ok) {
          throw new Error(data.error || 'Failed to save courses.');
        }

        if (Array.isArray(data.courses)) {
          coursesList = data.courses;
        }

        showNotice(data.message || 'Courses saved successfully!', 'success');
        setTimeout(() => {
          hideNotice();
          window.goToStep(4);
        }, 350);

      } catch (err) {
        showNotice(err.message, 'error');
      } finally {
        btnStep3Next.disabled = false;
        btnStep3Next.innerHTML = `<span>Save Courses & Next: Timetable</span><i data-lucide="arrow-right" class="w-4 h-4"></i>`;
        if (window.lucide) window.lucide.createIcons();
      }
    });
  }

  // =========================================================================
  // STEP 4: TIMETABLE IMPORT (Optional)
  // =========================================================================
  window.switchTimetableInputMode = function (mode) {
    const fileMode = document.getElementById('tt-mode-file');
    const textMode = document.getElementById('tt-mode-text');
    const tabFile = document.getElementById('tt-tab-file');
    const tabText = document.getElementById('tt-tab-text');
    const tabManual = document.getElementById('tt-tab-manual');

    const activeClass = 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300';
    const inactiveClass = 'text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5';

    tabFile.className = `px-3.5 py-1.5 rounded-lg text-xs font-semibold transition flex items-center gap-1.5 ${mode === 'file' ? activeClass : inactiveClass}`;
    tabText.className = `px-3.5 py-1.5 rounded-lg text-xs font-semibold transition flex items-center gap-1.5 ${mode === 'text' ? activeClass : inactiveClass}`;
    tabManual.className = `px-3.5 py-1.5 rounded-lg text-xs font-semibold transition flex items-center gap-1.5 ${mode === 'manual' ? activeClass : inactiveClass}`;

    if (mode === 'file') {
      fileMode.classList.remove('hidden');
      textMode.classList.add('hidden');
    } else if (mode === 'text') {
      fileMode.classList.add('hidden');
      textMode.classList.remove('hidden');
    } else {
      fileMode.classList.add('hidden');
      textMode.classList.add('hidden');
      addNewTimetableRow();
    }
  };

  const ttDropzone = document.getElementById('tt-dropzone');
  const ttFileInput = document.getElementById('tt-file-input');
  const btnParseTtFile = document.getElementById('btn-parse-tt-file');

  if (ttDropzone && ttFileInput) {
    ttDropzone.addEventListener('click', () => ttFileInput.click());
    ttDropzone.addEventListener('dragover', (e) => {
      e.preventDefault();
      ttDropzone.classList.add('border-emerald-500');
    });
    ttDropzone.addEventListener('dragleave', () => {
      ttDropzone.classList.remove('border-emerald-500');
    });
    ttDropzone.addEventListener('drop', (e) => {
      e.preventDefault();
      ttDropzone.classList.remove('border-emerald-500');
      if (e.dataTransfer.files.length) {
        ttFileInput.files = e.dataTransfer.files;
        handleTtFileSelected(e.dataTransfer.files[0]);
      }
    });

    ttFileInput.addEventListener('change', () => {
      if (ttFileInput.files.length) {
        handleTtFileSelected(ttFileInput.files[0]);
      }
    });
  }

  function handleTtFileSelected(file) {
    const nameEl = document.getElementById('tt-file-name');
    const displayEl = document.getElementById('tt-filename-display');
    if (nameEl) nameEl.textContent = `${file.name} (${Math.round(file.size / 1024)} KB)`;
    if (displayEl) displayEl.classList.remove('hidden');
    if (btnParseTtFile) {
      btnParseTtFile.classList.remove('hidden');
      btnParseTtFile.click();
    }
  }

  if (btnParseTtFile) {
    btnParseTtFile.addEventListener('click', async () => {
      const file = ttFileInput?.files[0];
      if (!file) return;

      btnParseTtFile.disabled = true;
      btnParseTtFile.innerHTML = `<i data-lucide="loader-2" class="w-3.5 h-3.5 animate-spin"></i> Parsing timetable...`;
      if (window.lucide) window.lucide.createIcons();

      const formData = new FormData();
      formData.append('csrf_token', csrfToken);
      formData.append('action', 'parse_document');
      formData.append('domain', 'timetable');
      formData.append('document', file);

      try {
        const res = await fetch(`${API}/onboarding.php`, {
          method: 'POST',
          credentials: 'same-origin',
          body: formData,
        });
        const data = await res.json();
        if (!res.ok || !data.ok) {
          throw new Error(data.error || 'Could not extract timetable slots.');
        }

        if (Array.isArray(data.items) && data.items.length > 0) {
          mergeExtractedTimetable(data.items);
          showNotice(`Extracted ${data.items.length} classes from your timetable!`, 'success');
        } else {
          showNotice('No class timetable slots identified. You can paste text or skip.', 'info');
        }
      } catch (err) {
        showNotice(err.message, 'error');
      } finally {
        btnParseTtFile.disabled = false;
        btnParseTtFile.innerHTML = `<i data-lucide="cpu" class="w-3.5 h-3.5"></i> Extract Timetable`;
        if (window.lucide) window.lucide.createIcons();
      }
    });
  }

  const btnParseTtText = document.getElementById('btn-parse-tt-text');
  if (btnParseTtText) {
    btnParseTtText.addEventListener('click', async () => {
      const text = document.getElementById('tt-text-input')?.value.trim();
      if (!text) {
        showNotice('Please paste your timetable text first.', 'error');
        return;
      }

      btnParseTtText.disabled = true;
      btnParseTtText.innerHTML = `<i data-lucide="loader-2" class="w-3.5 h-3.5 animate-spin"></i> Extracting...`;
      if (window.lucide) window.lucide.createIcons();

      try {
        const res = await fetch(`${API}/onboarding.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({
            csrf_token: csrfToken,
            action: 'parse_document',
            domain: 'timetable',
            text: text,
          })
        });
        const data = await res.json();
        if (!res.ok || !data.ok) {
          throw new Error(data.error || 'Could not extract timetable from text.');
        }

        if (Array.isArray(data.items) && data.items.length > 0) {
          mergeExtractedTimetable(data.items);
          showNotice(`Extracted ${data.items.length} class slots!`, 'success');
        } else {
          showNotice('No class timetable slots identified in text.', 'info');
        }
      } catch (err) {
        showNotice(err.message, 'error');
      } finally {
        btnParseTtText.disabled = false;
        btnParseTtText.innerHTML = `<i data-lucide="cpu" class="w-3.5 h-3.5"></i> Extract from Text`;
        if (window.lucide) window.lucide.createIcons();
      }
    });
  }

  const dayMapNames = { 1: 'Monday', 2: 'Tuesday', 3: 'Wednesday', 4: 'Thursday', 5: 'Friday', 6: 'Saturday', 0: 'Sunday' };

  function mergeExtractedTimetable(extracted) {
    extracted.forEach(item => {
      timetableList.push({
        day_of_week: Number(item.day_of_week || item.day || 1),
        course_code: (item.course_code || '').trim().toUpperCase(),
        title: (item.title || `${item.course_code || 'Class'} Lecture`).trim(),
        start_time: item.start_time || '09:00:00',
        end_time: item.end_time || '11:00:00',
        location: item.location || '',
      });
    });
    renderTimetableTable();
  }

  window.addNewTimetableRow = function () {
    timetableList.push({
      day_of_week: 1,
      course_code: coursesList[0]?.code || '',
      title: 'Class Lecture',
      start_time: '09:00:00',
      end_time: '11:00:00',
      location: '',
    });
    renderTimetableTable();
  };

  window.removeTimetableRow = function (idx) {
    timetableList.splice(idx, 1);
    renderTimetableTable();
  };

  function renderTimetableTable() {
    const tbody = document.getElementById('tt-tbody');
    const badge = document.getElementById('tt-count-badge');
    if (!tbody) return;

    if (badge) {
      badge.textContent = `${timetableList.length} class${timetableList.length === 1 ? '' : 'es'}`;
    }

    if (!timetableList.length) {
      tbody.innerHTML = `
        <tr id="tt-empty-row">
          <td colspan="5" class="p-6 text-center text-gray-400 dark:text-gray-500">
            No timetable slots added yet. You can upload a timetable or skip this step.
          </td>
        </tr>
      `;
      return;
    }

    tbody.innerHTML = timetableList.map((t, i) => `
      <tr class="hover:bg-gray-50/50 dark:hover:bg-white/[0.02] transition">
        <td class="p-2.5">
          <select onchange="updateTtField(${i}, 'day_of_week', this.value)" class="w-full px-2 py-1.5 rounded-lg border border-gray-200 dark:border-white/10 bg-transparent text-xs font-semibold outline-none focus:border-emerald-600">
            ${[1, 2, 3, 4, 5, 6, 0].map(d => `<option value="${d}" ${Number(t.day_of_week) === d ? 'selected' : ''}>${dayMapNames[d]}</option>`).join('')}
          </select>
        </td>
        <td class="p-2.5">
          <input type="text" value="${escapeHtml(t.course_code || '')}" onchange="updateTtField(${i}, 'course_code', this.value)"
            placeholder="CSC 411" class="w-full px-2 py-1.5 rounded-lg border border-gray-200 dark:border-white/10 bg-transparent text-xs font-bold uppercase outline-none focus:border-emerald-600">
        </td>
        <td class="p-2.5">
          <input type="text" value="${escapeHtml(t.title || '')}" onchange="updateTtField(${i}, 'title', this.value)"
            placeholder="Lecture (Room 4)" class="w-full px-2 py-1.5 rounded-lg border border-gray-200 dark:border-white/10 bg-transparent text-xs outline-none focus:border-emerald-600">
        </td>
        <td class="p-2.5">
          <div class="flex items-center gap-1">
            <input type="time" value="${t.start_time.slice(0, 5)}" onchange="updateTtField(${i}, 'start_time', this.value + ':00')" class="w-16 px-1 py-1 rounded border border-gray-200 dark:border-white/10 text-[11px] bg-transparent outline-none">
            <span class="text-gray-400 text-xs">-</span>
            <input type="time" value="${t.end_time.slice(0, 5)}" onchange="updateTtField(${i}, 'end_time', this.value + ':00')" class="w-16 px-1 py-1 rounded border border-gray-200 dark:border-white/10 text-[11px] bg-transparent outline-none">
          </div>
        </td>
        <td class="p-2.5 text-center">
          <button type="button" onclick="removeTimetableRow(${i})" class="text-gray-400 hover:text-red-500 p-1.5 rounded-lg transition" title="Remove class">
            <i data-lucide="trash-2" class="w-4 h-4"></i>
          </button>
        </td>
      </tr>
    `).join('');

    if (window.lucide) window.lucide.createIcons();
  }

  window.updateTtField = function (idx, field, val) {
    if (!timetableList[idx]) return;
    timetableList[idx][field] = val;
  };

  window.skipStep = async function (stepNum) {
    try {
      await fetch(`${API}/onboarding.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
          csrf_token: csrfToken,
          action: 'skip_step',
          step: stepNum,
        })
      });
    } catch (e) {}
    window.goToStep(stepNum + 1);
  };

  const btnStep4Next = document.getElementById('btn-step4-next');
  if (btnStep4Next) {
    btnStep4Next.addEventListener('click', async () => {
      if (!timetableList.length) {
        window.skipStep(4);
        return;
      }

      btnStep4Next.disabled = true;
      btnStep4Next.innerHTML = `<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> Saving Timetable...`;
      if (window.lucide) window.lucide.createIcons();

      try {
        const res = await fetch(`${API}/onboarding.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({
            csrf_token: csrfToken,
            action: 'save_timetable',
            classes: timetableList,
          })
        });
        const data = await res.json();
        if (!res.ok || !data.ok) {
          throw new Error(data.error || 'Failed to save timetable.');
        }

        showNotice('Timetable saved successfully!', 'success');
        setTimeout(() => {
          hideNotice();
          window.goToStep(5);
        }, 350);

      } catch (err) {
        showNotice(err.message, 'error');
      } finally {
        btnStep4Next.disabled = false;
        btnStep4Next.innerHTML = `<span>Save & Next: Curriculum</span><i data-lucide="arrow-right" class="w-4 h-4"></i>`;
        if (window.lucide) window.lucide.createIcons();
      }
    });
  }

  // =========================================================================
  // STEP 5: CURRICULUM IMPORT (Optional)
  // =========================================================================
  const currFileInput = document.getElementById('curriculum-file-input');
  if (currFileInput) {
    currFileInput.addEventListener('change', async () => {
      const file = currFileInput.files[0];
      if (!file) return;

      const statusEl = document.getElementById('curriculum-extracted-status');
      if (statusEl) {
        statusEl.classList.remove('hidden');
        statusEl.textContent = `Extracting calendar from ${file.name}...`;
      }

      const formData = new FormData();
      formData.append('csrf_token', csrfToken);
      formData.append('action', 'parse_document');
      formData.append('domain', 'curriculum');
      formData.append('document', file);

      try {
        const res = await fetch(`${API}/onboarding.php`, {
          method: 'POST',
          credentials: 'same-origin',
          body: formData,
        });
        const data = await res.json();
        if (!res.ok || !data.ok) {
          throw new Error(data.error || 'Could not extract curriculum.');
        }

        if (data.extracted) {
          const ext = data.extracted;
          if (ext.start_date) {
            curriculumData.start_date = ext.start_date;
            const startInp = document.getElementById('input-sem-start');
            if (startInp) startInp.value = ext.start_date;
          }
          if (ext.end_date) {
            curriculumData.end_date = ext.end_date;
            const endInp = document.getElementById('input-sem-end');
            if (endInp) endInp.value = ext.end_date;
          }
          if (Array.isArray(ext.weeks)) {
            curriculumData.weeks = ext.weeks;
          }
          if (statusEl) {
            statusEl.textContent = `Extracted ${curriculumData.weeks.length || 0} academic weeks from ${file.name}! Dates: ${curriculumData.start_date} to ${curriculumData.end_date}`;
          }
        }
      } catch (err) {
        showNotice(err.message, 'error');
      }
    });
  }

  const btnStep5Next = document.getElementById('btn-step5-next');
  if (btnStep5Next) {
    btnStep5Next.addEventListener('click', async () => {
      const start = document.getElementById('input-sem-start')?.value.trim();
      const end = document.getElementById('input-sem-end')?.value.trim();

      if (!start || !end) {
        window.skipStep(5);
        return;
      }

      btnStep5Next.disabled = true;
      btnStep5Next.innerHTML = `<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> Saving Calendar...`;
      if (window.lucide) window.lucide.createIcons();

      try {
        const res = await fetch(`${API}/onboarding.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({
            csrf_token: csrfToken,
            action: 'save_curriculum',
            semester_name: userData.current_semester,
            start_date: start,
            end_date: end,
            weeks: curriculumData.weeks,
          })
        });
        const data = await res.json();
        if (!res.ok || !data.ok) {
          throw new Error(data.error || 'Failed to save calendar.');
        }

        curriculumData.start_date = start;
        curriculumData.end_date = end;

        showNotice('Calendar saved successfully!', 'success');
        setTimeout(() => {
          hideNotice();
          window.goToStep(6);
        }, 350);

      } catch (err) {
        showNotice(err.message, 'error');
      } finally {
        btnStep5Next.disabled = false;
        btnStep5Next.innerHTML = `<span>Save & Next: Review</span><i data-lucide="arrow-right" class="w-4 h-4"></i>`;
        if (window.lucide) window.lucide.createIcons();
      }
    });
  }

  // =========================================================================
  // STEP 6: REAL DATA REVIEW
  // =========================================================================
  function populateReview() {
    // 1. Profile
    const nameEl = document.getElementById('rev-name');
    if (nameEl) nameEl.textContent = userData.full_name || 'Not provided';
    const progEl = document.getElementById('rev-program');
    if (progEl) progEl.textContent = userData.program || 'Not provided';
    const levelEl = document.getElementById('rev-level');
    if (levelEl) levelEl.textContent = userData.level || 'Not provided';
    const semEl = document.getElementById('rev-semester');
    if (semEl) semEl.textContent = userData.current_semester || 'First Semester';

    // 2. Courses
    const cCountEl = document.getElementById('rev-courses-count');
    const cListEl = document.getElementById('rev-courses-list');
    if (cCountEl) {
      cCountEl.textContent = `${coursesList.length} course${coursesList.length === 1 ? '' : 's'}`;
    }
    if (cListEl) {
      if (!coursesList.length) {
        cListEl.innerHTML = `<span class="text-xs text-amber-600 dark:text-amber-400">No courses committed yet.</span>`;
      } else {
        cListEl.innerHTML = coursesList.map(c => `
          <div class="px-3 py-1.5 rounded-lg border border-emerald-200 dark:border-emerald-800/40 bg-white dark:bg-white/5 text-xs flex items-center gap-2">
            <span class="font-bold text-emerald-700 dark:text-emerald-400">${escapeHtml(c.code)}</span>
            <span class="text-gray-600 dark:text-gray-300 truncate max-w-[200px]">${escapeHtml(c.name)}</span>
            <span class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-white/10 text-[10px] font-bold text-gray-500">${c.credits}U</span>
          </div>
        `).join('');
      }
    }

    // 3. Timetable
    const ttCountEl = document.getElementById('rev-tt-count');
    const ttSumEl = document.getElementById('rev-tt-summary');
    if (ttCountEl) {
      ttCountEl.textContent = `${timetableList.length} class${timetableList.length === 1 ? '' : 'es'}`;
    }
    if (ttSumEl) {
      if (!timetableList.length) {
        ttSumEl.innerHTML = `<span class="text-gray-400">Timetable skipped (you can add classes anytime in Schedule).</span>`;
      } else {
        const daysPresent = new Set(timetableList.map(t => dayMapNames[t.day_of_week] || 'Day'));
        ttSumEl.innerHTML = `<strong>${timetableList.length} weekly classes</strong> scheduled across <strong>${Array.from(daysPresent).join(', ')}</strong>.`;
      }
    }

    // 4. Curriculum
    const currSumEl = document.getElementById('rev-curriculum-summary');
    if (currSumEl) {
      if (!curriculumData.start_date || !curriculumData.end_date) {
        currSumEl.innerHTML = `<span class="text-gray-400">Calendar skipped (dates can be set anytime).</span>`;
      } else {
        currSumEl.innerHTML = `Semester active from <strong>${curriculumData.start_date}</strong> to <strong>${curriculumData.end_date}</strong> (${curriculumData.weeks.length || 0} academic weeks).`;
      }
    }
  }

  // =========================================================================
  // STEP 7: FIRST-USE GOALS (Required)
  // =========================================================================
  window.selectGoalHours = function (hours, btnEl) {
    document.getElementById('selected-goal-hours').value = hours;
    const customInp = document.getElementById('input-custom-goal');
    if (customInp) customInp.value = '';

    document.querySelectorAll('.goal-hour-card').forEach(card => {
      card.className = 'goal-hour-card p-4 rounded-xl border border-gray-200 dark:border-white/10 hover:border-emerald-500 text-left transition bg-white dark:bg-white/[0.02]';
    });

    if (btnEl) {
      btnEl.className = 'goal-hour-card border-2 border-emerald-600 bg-emerald-50/50 dark:bg-emerald-950/20 p-4 rounded-xl text-left transition';
    }
  };

  const customGoalInp = document.getElementById('input-custom-goal');
  if (customGoalInp) {
    customGoalInp.addEventListener('input', (e) => {
      const val = parseFloat(e.target.value);
      if (!isNaN(val) && val > 0) {
        document.getElementById('selected-goal-hours').value = val;
        document.querySelectorAll('.goal-hour-card').forEach(card => {
          card.className = 'goal-hour-card p-4 rounded-xl border border-gray-200 dark:border-white/10 hover:border-emerald-500 text-left transition bg-white dark:bg-white/[0.02]';
        });
      }
    });
  }

  window.setQuickDays = function (mode) {
    const dayButtons = document.querySelectorAll('#day-toggles .day-btn');
    let targetDays = [];
    if (mode === 'weekdays') targetDays = [1, 2, 3, 4, 5];
    else if (mode === 'all') targetDays = [0, 1, 2, 3, 4, 5, 6];
    else if (mode === 'weekends') targetDays = [0, 6];

    dayButtons.forEach(btn => {
      const d = parseInt(btn.getAttribute('data-day'), 10);
      if (targetDays.includes(d)) {
        btn.className = 'day-btn px-3.5 py-2 rounded-xl text-xs font-bold border border-emerald-500 bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300';
      } else {
        btn.className = 'day-btn px-3.5 py-2 rounded-xl text-xs font-bold border border-gray-200 dark:border-white/10 text-gray-600 dark:text-gray-400';
      }
    });
  };

  // Toggle individual day button
  document.querySelectorAll('#day-toggles .day-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const isActive = btn.classList.contains('border-emerald-500');
      if (isActive) {
        btn.className = 'day-btn px-3.5 py-2 rounded-xl text-xs font-bold border border-gray-200 dark:border-white/10 text-gray-600 dark:text-gray-400';
      } else {
        btn.className = 'day-btn px-3.5 py-2 rounded-xl text-xs font-bold border border-emerald-500 bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300';
      }
    });
  });

  window.selectStudyTime = function (time, btnEl) {
    document.getElementById('selected-study-time').value = time;
    document.querySelectorAll('.study-time-btn').forEach(b => {
      b.className = 'study-time-btn border border-gray-200 dark:border-white/10 p-3 rounded-xl text-center transition';
    });
    if (btnEl) {
      btnEl.className = 'study-time-btn border-2 border-emerald-600 bg-emerald-50/50 dark:bg-emerald-950/20 p-3 rounded-xl text-center transition';
    }
  };

  const btnComplete = document.getElementById('btn-complete-onboarding');
  if (btnComplete) {
    btnComplete.addEventListener('click', async () => {
      const hours = parseFloat(document.getElementById('selected-goal-hours')?.value || '15');
      const time = document.getElementById('selected-study-time')?.value || 'morning';

      const activeDays = [];
      document.querySelectorAll('#day-toggles .day-btn').forEach(btn => {
        if (btn.classList.contains('border-emerald-500')) {
          activeDays.push(btn.getAttribute('data-day'));
        }
      });
      const daysStr = activeDays.length ? activeDays.join(',') : '1,2,3,4,5';

      btnComplete.disabled = true;
      btnComplete.innerHTML = `<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> Finalizing Setup...`;
      if (window.lucide) window.lucide.createIcons();

      try {
        // 1. Save Goals
        const gRes = await fetch(`${API}/onboarding.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({
            csrf_token: csrfToken,
            action: 'save_goals',
            weekly_goal_hours: hours,
            preferred_study_days: daysStr,
            preferred_study_time: time,
          })
        });
        const gData = await gRes.json();
        if (!gRes.ok || !gData.ok) {
          throw new Error(gData.error || 'Failed to save goals.');
        }

        // 2. Complete Transaction
        const cRes = await fetch(`${API}/onboarding.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({
            csrf_token: csrfToken,
            action: 'complete',
          })
        });
        const cData = await cRes.json();
        if (!cRes.ok || !cData.ok) {
          throw new Error(cData.error || 'Failed to complete onboarding.');
        }

        // Show Ready State
        for (let i = 1; i <= 7; i++) {
          const pane = document.getElementById(`step-pane-${i}`);
          if (pane) pane.classList.add('hidden');
        }
        const readyPane = document.getElementById('step-pane-ready');
        if (readyPane) readyPane.classList.remove('hidden');
        if (window.lucide) window.lucide.createIcons();

        // Redirect to dashboard
        setTimeout(() => {
          window.location.replace(cData.redirect || './dashboard.php');
        }, 1200);

      } catch (err) {
        showNotice(err.message, 'error');
        btnComplete.disabled = false;
        btnComplete.innerHTML = `<span>Complete Setup & Go to Dashboard</span><i data-lucide="check" class="w-4 h-4"></i>`;
        if (window.lucide) window.lucide.createIcons();
      }
    });
  }

  // Utilities
  function escapeHtml(str) {
    return String(str ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function maxMinInt(val, min, max) {
    const n = parseInt(val, 10);
    return isNaN(n) ? min : Math.max(min, Math.min(max, n));
  }

  // Dark Mode Toggle Support
  const darkToggle = document.getElementById('dark-toggle');
  if (darkToggle) {
    darkToggle.addEventListener('click', () => {
      const isDark = document.documentElement.classList.toggle('dark');
      try {
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
      } catch (e) {}
    });
  }

  // Initialize
  document.addEventListener('DOMContentLoaded', () => {
    initOnboarding();
  });

})();
