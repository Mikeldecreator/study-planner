/* =========================================================
   STUDY PLANNER — SETTINGS
   Full Settings Page Controller
   Includes:
   - Profile loading
   - Profile editing
   - Password change
   - Avatar upload
   - Academic goal
   - Notifications preference
   - Browser push controls
   - Dark mode
   - Week start
   - Data export
   - Clear cache
   - Settings statistics

   Browser push is linked to the main Notifications toggle.
   Test button intentionally removed.
========================================================= */

(function () {

    'use strict';

    const API = window.API || '../api';

    /* =====================================================
       ELEMENTS
    ===================================================== */

    const enableButton =
        document.getElementById('browser-push-enable');

    const pushStatus =
        document.getElementById('browser-push-status');

    const profileModal =
        document.getElementById('profile-modal');

    const passwordModal =
        document.getElementById('password-modal');

    const goalModal =
        document.getElementById('goal-modal');

    const profileForm =
        document.getElementById('profile-form');

    const passwordForm =
        document.getElementById('password-form');

    const goalForm =
        document.getElementById('goal-form');

    const avatarInput =
        document.getElementById('avatar-input');

    const profileAvatar =
        document.getElementById('profile-avatar');

    const profileAvatarFallback =
        document.getElementById('profile-avatar-fallback');

    const modalAvatarPreview =
        document.getElementById('modal-avatar-preview');


    /* =====================================================
       HELPERS
    ===================================================== */

    function $(id) {
        return document.getElementById(id);
    }


    function showToast(message, type) {

        if (
            typeof window.showToast === 'function'
        ) {

            window.showToast(
                message,
                type || 'info'
            );

        } else {

            console.log(message);

        }

    }


    function openModal(modal) {

        if (!modal) {
            return;
        }

        modal.classList.remove('hidden');
        modal.classList.add('flex');

    }


    function closeModal(modal) {

        if (!modal) {
            return;
        }

        modal.classList.add('hidden');
        modal.classList.remove('flex');

    }


    function initials(name) {

        const value =
            String(name || 'Student').trim();


        if (!value) {
            return 'S';
        }


        const parts =
            value
                .split(/\s+/)
                .filter(Boolean);


        if (parts.length === 1) {

            return parts[0]
                .charAt(0)
                .toUpperCase();

        }


        return (
            parts[0].charAt(0) +
            parts[parts.length - 1].charAt(0)
        ).toUpperCase();

    }


    function escapeHtml(value) {

        const div =
            document.createElement('div');


        div.textContent =
            value ?? '';


        return div.innerHTML;

    }


    async function responseJson(response) {

        const text =
            await response.text();


        let data = {};


        try {

            data =
                text
                    ? JSON.parse(text)
                    : {};

        } catch (error) {

            throw new Error(
                `Server returned invalid JSON (HTTP ${response.status}).`
            );

        }


        if (!response.ok) {

            throw new Error(
                data.error ||
                data.message ||
                `Request failed (HTTP ${response.status}).`
            );

        }


        return data;

    }


    async function fetchJson(url, options) {

        const response =
            await fetch(
                url,
                {
                    credentials: 'same-origin',
                    ...(options || {})
                }
            );


        return responseJson(response);

    }


    function csrfToken() {

        return (
            window.CSRF_TOKEN ||
            ''
        );

    }


    function setButtonBusy(
        button,
        busy,
        busyText,
        normalText
    ) {

        if (!button) {
            return;
        }


        button.disabled =
            busy;


        if (busy) {

            button.dataset.normalText =
                button.textContent;


            button.textContent =
                busyText || 'Saving…';

        } else {

            button.textContent =
                normalText ||
                button.dataset.normalText ||
                button.textContent;

        }

    }


    /* =====================================================
       AVATAR
    ===================================================== */

    function avatarPath(relativePath) {

        if (!relativePath) {
            return '';
        }


        try {

            return new URL(
                relativePath,
                window.location.origin +
                window.location.pathname.replace(
                    /\/public\/[^/]*$/,
                    '/public/'
                )
            ).pathname;

        } catch (error) {

            return relativePath;

        }

    }


    function applyAvatar(
        element,
        url,
        fallback
    ) {

        if (!element) {
            return;
        }


        element.innerHTML =
            '';


        const image =
            document.createElement('img');


        image.src =
            url;


        image.alt =
            'Profile photo';


        image.className =
            'w-full h-full object-cover';


        image.onerror =
            function () {

                clearAvatar(
                    element,
                    fallback
                );

            };


        element.appendChild(
            image
        );

    }


    function clearAvatar(
        element,
        fallback
    ) {

        if (!element) {
            return;
        }


        element.innerHTML =
            `<span>${escapeHtml(
                fallback || 'S'
            )}</span>`;

    }


    /* =====================================================
       PROFILE DISPLAY
    ===================================================== */

    function updateProfileDisplay(user) {

        if (!user) {
            return;
        }


        const name =
            user.name ||
            user.full_name ||
            'Student';


        const email =
            user.email ||
            '';


        const level =
            user.level ||
            'Level 400';


        const program =
            user.program ||
            'Computer Science';


        const profileName =
            $('profile-name');


        if (profileName) {

            profileName.textContent =
                name;

        }


        const profileLevel =
            $('profile-level');


        if (profileLevel) {

            profileLevel.textContent =
                level;

        }


        const profileProgram =
            $('profile-program');


        if (profileProgram) {

            profileProgram.textContent =
                program;

        }


        const emailValue =
            $('account-email-value');


        if (emailValue) {

            emailValue.textContent =
                email;

        }


        const fallback =
            initials(name);


        if (profileAvatarFallback) {

            profileAvatarFallback.textContent =
                fallback;

        }


        document
            .querySelectorAll(
                '[data-user-name]'
            )
            .forEach(
                function (element) {

                    element.textContent =
                        name;

                }
            );


        document
            .querySelectorAll(
                '[data-user-initial]'
            )
            .forEach(
                function (element) {

                    element.textContent =
                        name
                            .charAt(0)
                            .toUpperCase();

                }
            );


        if (user.avatar_path) {

            const avatarUrl =
                user.avatar_path.startsWith('http')
                    ? user.avatar_path
                    : user.avatar_path.startsWith('/')
                        ? user.avatar_path
                        : avatarPath(
                            user.avatar_path
                        );


            applyAvatar(
                profileAvatar,
                avatarUrl,
                fallback
            );


            applyAvatar(
                modalAvatarPreview,
                avatarUrl,
                fallback
            );

        } else {

            clearAvatar(
                profileAvatar,
                fallback
            );


            clearAvatar(
                modalAvatarPreview,
                fallback
            );

        }

    }


    /* =====================================================
       PROFILE FORM
    ===================================================== */

    function populateProfileForm(user) {

        if (!profileForm) {
            return;
        }


        const fullName =
            profileForm.elements.full_name;


        const email =
            profileForm.elements.email;


        const program =
            profileForm.elements.program;


        const level =
            profileForm.elements.level;


        if (fullName) {

            fullName.value =
                user.name ||
                user.full_name ||
                '';

        }


        if (email) {

            email.value =
                user.email ||
                '';

        }


        if (program) {

            program.value =
                user.program ||
                '';

        }


        if (level) {

            level.value =
                user.level ||
                '';

        }


        const fallback =
            initials(
                user.name ||
                user.full_name
            );


        if (modalAvatarPreview) {

            if (user.avatar_path) {

                const url =
                    user.avatar_path.startsWith('http')
                        ? user.avatar_path
                        : user.avatar_path.startsWith('/')
                            ? user.avatar_path
                            : avatarPath(
                                user.avatar_path
                            );


                applyAvatar(
                    modalAvatarPreview,
                    url,
                    fallback
                );

            } else {

                clearAvatar(
                    modalAvatarPreview,
                    fallback
                );

            }

        }

    }


    function showProfileError(message) {

        const errorBox =
            $('profile-form-error');


        if (!errorBox) {
            return;
        }


        errorBox.textContent =
            message;


        errorBox.classList.remove(
            'hidden'
        );

    }


    function clearProfileError() {

        const errorBox =
            $('profile-form-error');


        if (!errorBox) {
            return;
        }


        errorBox.textContent =
            '';


        errorBox.classList.add(
            'hidden'
        );

    }


    async function saveProfile(event) {

        event.preventDefault();


        clearProfileError();


        const button =
            $('save-profile-btn');


        const body = {

            full_name:
                String(
                    profileForm
                        .elements
                        .full_name
                        ?.value ||
                    ''
                ).trim(),

            email:
                String(
                    profileForm
                        .elements
                        .email
                        ?.value ||
                    ''
                ).trim(),

            program:
                String(
                    profileForm
                        .elements
                        .program
                        ?.value ||
                    ''
                ).trim(),

            level:
                String(
                    profileForm
                        .elements
                        .level
                        ?.value ||
                    ''
                ).trim(),

            csrf_token:
                csrfToken()

        };


        setButtonBusy(
            button,
            true,
            'Saving…',
            'Save Changes'
        );


        try {

            await fetchJson(
                `${API}/settings.php`,
                {
                    method: 'POST',

                    headers: {
                        'Content-Type':
                            'application/json'
                    },

                    body:
                        JSON.stringify(
                            body
                        )
                }
            );


            const me =
                await fetchJson(
                    `${API}/me.php`
                );


            window.CURRENT_USER =
                me;


            updateProfileDisplay(
                me
            );


            closeModal(
                profileModal
            );


            showToast(
                'Profile updated successfully.',
                'success'
            );


        } catch (error) {

            console.error(
                'Profile update failed:',
                error
            );


            showProfileError(
                error.message ||
                'Could not save your profile.'
            );


            showToast(
                error.message ||
                'Could not save your profile.',
                'error'
            );

        } finally {

            setButtonBusy(
                button,
                false,
                '',
                'Save Changes'
            );

        }

    }


    /* =====================================================
       PASSWORD
    ===================================================== */

    function showPasswordError(message) {

        const errorBox =
            $('password-form-error');


        if (!errorBox) {
            return;
        }


        errorBox.textContent =
            message;


        errorBox.classList.remove(
            'hidden'
        );

    }


    function clearPasswordError() {

        const errorBox =
            $('password-form-error');


        if (!errorBox) {
            return;
        }


        errorBox.textContent =
            '';


        errorBox.classList.add(
            'hidden'
        );

    }


    async function changePassword(event) {

        event.preventDefault();


        clearPasswordError();


        const current =
            String(
                passwordForm
                    .elements
                    .current_password
                    ?.value ||
                ''
            );


        const next =
            String(
                passwordForm
                    .elements
                    .new_password
                    ?.value ||
                ''
            );


        const confirm =
            String(
                passwordForm
                    .elements
                    .confirm_password
                    ?.value ||
                ''
            );


        if (
            !current ||
            !next ||
            !confirm
        ) {

            showPasswordError(
                'All password fields are required.'
            );

            return;

        }


        if (next.length < 8) {

            showPasswordError(
                'New password must be at least 8 characters.'
            );

            return;

        }


        if (next !== confirm) {

            showPasswordError(
                'The new passwords do not match.'
            );

            return;

        }


        const submitButton =
            passwordForm.querySelector(
                'button[type="submit"]'
            );


        setButtonBusy(
            submitButton,
            true,
            'Updating…',
            'Update Password'
        );


        try {

            await fetchJson(
                `${API}/settings.php`,
                {
                    method: 'POST',

                    headers: {
                        'Content-Type':
                            'application/json'
                    },

                    body:
                        JSON.stringify({

                            action:
                                'password',

                            current_password:
                                current,

                            new_password:
                                next,

                            confirm_password:
                                confirm,

                            csrf_token:
                                csrfToken()

                        })
                }
            );


            passwordForm.reset();


            closeModal(
                passwordModal
            );


            showToast(
                'Password updated successfully.',
                'success'
            );


        } catch (error) {

            console.error(
                'Password update failed:',
                error
            );


            showPasswordError(
                error.message ||
                'Could not update your password.'
            );


            showToast(
                error.message ||
                'Could not update your password.',
                'error'
            );


        } finally {

            setButtonBusy(
                submitButton,
                false,
                '',
                'Update Password'
            );

        }

    }


    /* =====================================================
       AVATAR UPLOAD
    ===================================================== */

    async function uploadAvatar(file) {

        if (!file) {
            return;
        }


        if (
            file.size >
            5 * 1024 * 1024
        ) {

            showToast(
                'Profile images must be 5MB or smaller.',
                'error'
            );

            return;

        }


        const formData =
            new FormData();


        formData.append(
            'action',
            'avatar'
        );


        formData.append(
            'avatar',
            file
        );


        formData.append(
            'csrf_token',
            csrfToken()
        );


        try {

            const response =
                await fetch(
                    `${API}/settings.php`,
                    {
                        method: 'POST',

                        credentials:
                            'same-origin',

                        body:
                            formData
                    }
                );


            await responseJson(
                response
            );


            const me =
                await fetchJson(
                    `${API}/me.php`
                );


            window.CURRENT_USER =
                me;


            updateProfileDisplay(
                me
            );

            if (
                typeof window.updateUserAvatars ===
                'function'
            ) {
                window.updateUserAvatars(
                    me
                );
            }


            showToast(
                'Profile photo updated.',
                'success'
            );


        } catch (error) {

            console.error(
                'Avatar upload failed:',
                error
            );


            showToast(
                error.message ||
                'Could not upload the profile photo.',
                'error'
            );


        } finally {

            if (avatarInput) {
                avatarInput.value = '';
            }

        }

    }


    /* =====================================================
       ACADEMIC GOAL
    ===================================================== */

    function getGoal() {

        try {

            const saved =
                localStorage.getItem(
                    'studyPlannerGoal'
                );


            if (saved) {

                const parsed =
                    JSON.parse(saved);


                if (
                    parsed &&
                    typeof parsed === 'object'
                ) {

                    return parsed;

                }

            }

        } catch (error) {

            console.warn(
                'Could not read saved goal:',
                error
            );

        }


        return {

            title:
                'Complete 5 courses this semester',

            target:
                5

        };

    }


    function saveGoal(goal) {

        localStorage.setItem(
            'studyPlannerGoal',
            JSON.stringify(goal)
        );

    }


    function renderGoal(
        goal,
        courses
    ) {

        const title =
            $('goal-title');


        const description =
            $('goal-description');


        const count =
            $('goal-count');


        const progress =
            $('goal-progress');


        const target =
            Math.max(
                1,
                Number(
                    goal.target || 1
                )
            );


        const completedCourses =
            Array.isArray(courses)

                ? courses.filter(
                    function (course) {

                        const taskCount =
                            Number(
                                course.task_count || 0
                            );


                        const completedCount =
                            Number(
                                course.completed_count || 0
                            );


                        return (
                            taskCount > 0 &&
                            completedCount >=
                                taskCount
                        );

                    }
                ).length

                : 0;


        const percentage =
            Math.min(
                100,
                Math.round(
                    completedCourses /
                    target *
                    100
                )
            );


        if (title) {

            title.textContent =
                goal.title ||
                'Current Goal';

        }


        if (description) {

            description.textContent =
                goal.title ||
                'Complete your academic target';

        }


        if (count) {

            count.textContent =
                `${completedCourses}/${target}`;

        }


        if (progress) {

            progress.style.width =
                `${percentage}%`;

        }

    }


    async function loadGoalData() {

        const goal =
            getGoal();


        let courses = [];


        try {

            const data =
                await fetchJson(
                    `${API}/courses.php`
                );


            courses =
                Array.isArray(
                    data.courses
                )
                    ? data.courses
                    : [];

        } catch (error) {

            console.warn(
                'Could not load course goal data:',
                error
            );

        }


        renderGoal(
            goal,
            courses
        );

    }


    async function submitGoal(event) {

        event.preventDefault();


        const input =
            $('goal-input');


        const targetInput =
            $('goal-target');


        const goal = {

            title:
                String(
                    input?.value ||
                    'Complete 5 courses this semester'
                ).trim(),

            target:
                Math.max(
                    1,
                    Number(
                        targetInput?.value ||
                        5
                    )
                )

        };


        saveGoal(
            goal
        );


        await loadGoalData();


        closeModal(
            goalModal
        );


        showToast(
            'Academic goal updated.',
            'success'
        );

    }


    /* =====================================================
       PREFERENCES
    ===================================================== */

    async function savePreference(data) {

        return fetchJson(
            `${API}/settings.php`,
            {
                method: 'POST',

                headers: {
                    'Content-Type':
                        'application/json'
                },

                body:
                    JSON.stringify({

                        ...data,

                        csrf_token:
                            csrfToken()

                    })
            }
        );

    }


    function updateToggle(
        element,
        state
    ) {

        if (!element) {
            return;
        }


        element.classList.toggle(
            'is-on',
            !!state
        );

    }


    async function loadPreferences(user) {

        if (!user) {
            return;
        }


        updateToggle(
            $('dark-mode-toggle'),
            !!user.dark_mode
        );


        updateToggle(
            $('notifications-toggle'),
            user.notifications_enabled !== false
        );


        const weekStart =
            $('week-start-select');


        if (weekStart) {

            weekStart.value =
                String(
                    user.week_start_day ?? 1
                );

        }

    }


    /* =====================================================
       MAIN NOTIFICATIONS TOGGLE
       LINKED TO BROWSER PUSH
    ===================================================== */

    const notificationsRow =
        $('pref-notifications');


    async function getBrowserPushStatus() {

        if (
            !window.StudyPlannerPush ||
            typeof window
                .StudyPlannerPush
                .status !== 'function'
        ) {

            return null;

        }


        try {

            return await window
                .StudyPlannerPush
                .status();

        } catch (error) {

            console.error(
                'Could not read browser push status:',
                error
            );

            return null;

        }

    }


    async function disableBrowserPush() {

        if (
            !window.StudyPlannerPush ||
            typeof window
                .StudyPlannerPush
                .disable !== 'function'
        ) {

            return;

        }


        await window
            .StudyPlannerPush
            .disable();

    }


    async function enableBrowserPushWithoutPrompt() {

        if (
            !window.StudyPlannerPush ||
            typeof window
                .StudyPlannerPush
                .status !== 'function'
        ) {

            return;

        }


        const status =
            await window
                .StudyPlannerPush
                .status();


        /*
         * We only automatically subscribe when the browser
         * has already granted notification permission.
         *
         * This avoids unexpectedly opening the browser
         * permission prompt.
         */

        if (
            status &&
            status.permission === 'granted' &&
            !status.subscribed &&
            typeof window
                .StudyPlannerPush
                .enable === 'function'
        ) {

            await window
                .StudyPlannerPush
                .enable();

        }

    }


    if (notificationsRow) {

        notificationsRow.addEventListener(
            'click',
            async function (event) {

                event.preventDefault();


                const toggle =
                    $('notifications-toggle');


                const current =
                    window.CURRENT_USER
                        ?.notifications_enabled !== false;


                const next =
                    !current;


                notificationsRow.disabled =
                    true;


                updateToggle(
                    toggle,
                    next
                );


                try {

                    if (!next) {

                        /*
                         * Turn browser push off first.
                         */

                        await disableBrowserPush();


                        /*
                         * Then turn the main
                         * notification preference off.
                         */

                        await savePreference({
                            notifications_enabled:
                                false
                        });

                    } else {

                        /*
                         * Turn the main notification
                         * preference back on.
                         */

                        await savePreference({
                            notifications_enabled:
                                true
                        });


                        /*
                         * Restore browser push when
                         * permission is already granted.
                         */

                        await enableBrowserPushWithoutPrompt();

                    }


                    if (
                        window.CURRENT_USER
                    ) {

                        window
                            .CURRENT_USER
                            .notifications_enabled =
                            next;

                    }


                    await refreshPushStatus();


                    showToast(
                        next
                            ? 'Notifications enabled.'
                            : 'Notifications disabled.',
                        'success'
                    );


                } catch (error) {

                    console.error(
                        'Notification setting failed:',
                        error
                    );


                    /*
                     * Roll back the UI.
                     */

                    updateToggle(
                        toggle,
                        current
                    );


                    /*
                     * Keep local state unchanged.
                     */

                    if (
                        window.CURRENT_USER
                    ) {

                        window
                            .CURRENT_USER
                            .notifications_enabled =
                            current;

                    }


                    showToast(
                        error.message ||
                        'Could not update notification settings.',
                        'error'
                    );

                } finally {

                    notificationsRow.disabled =
                        false;

                }

            }
        );

    }


    /* =====================================================
       DARK MODE
    ===================================================== */

    const darkModeRow =
        $('pref-dark-mode');


    if (darkModeRow) {

        darkModeRow.addEventListener(
            'click',
            async function (event) {

                event.preventDefault();


                const currentlyDark =
                    document.documentElement
                        .classList
                        .contains('dark');


                const next =
                    !currentlyDark;


                updateToggle(
                    $('dark-mode-toggle'),
                    next
                );


                try {

                    if (
                        typeof window.setDarkMode ===
                        'function'
                    ) {

                        await window.setDarkMode(
                            next
                        );

                    } else {

                        document.documentElement
                            .classList
                            .toggle(
                                'dark',
                                next
                            );


                        localStorage.setItem(
                            'darkMode',
                            next
                                ? 'true'
                                : 'false'
                        );


                        await savePreference({
                            dark_mode:
                                next
                        });

                    }


                    if (
                        window.CURRENT_USER
                    ) {

                        window.CURRENT_USER.dark_mode =
                            next;

                    }


                } catch (error) {

                    console.error(
                        'Dark mode update failed:',
                        error
                    );


                    updateToggle(
                        $('dark-mode-toggle'),
                        currentlyDark
                    );


                    showToast(
                        'Could not update dark mode.',
                        'error'
                    );

                }

            }
        );

    }


    /* =====================================================
       WEEK START
    ===================================================== */

    const weekStart =
        $('week-start-select');


    if (weekStart) {

        weekStart.addEventListener(
            'change',
            async function () {

                const value =
                    Number(
                        weekStart.value
                    );


                const normalized =
                    value === 0
                        ? 0
                        : 1;


                try {

                    await savePreference({
                        week_start_day:
                            normalized
                    });


                    if (
                        window.CURRENT_USER
                    ) {

                        window.CURRENT_USER
                            .week_start_day =
                            normalized;

                    }


                    localStorage.setItem(
                        'weekStartDay',
                        String(
                            normalized
                        )
                    );


                    showToast(
                        'Week start updated.',
                        'success'
                    );


                } catch (error) {

                    showToast(
                        error.message ||
                        'Could not update week start.',
                        'error'
                    );

                }

            }
        );

    }


    /* =====================================================
       LANGUAGE
    ===================================================== */

    const languageSelect =
        $('language-select');


    if (languageSelect) {

        languageSelect.addEventListener(
            'change',
            function () {

                const value =
                    languageSelect.value;


                localStorage.setItem(
                    'language',
                    value
                );


                showToast(
                    value === 'en'
                        ? 'English selected.'
                        : 'Language updated.',
                    'success'
                );

            }
        );

    }


    /* =====================================================
       PROFILE / PASSWORD / GOAL
    ===================================================== */

    $('open-edit-profile')
        ?.addEventListener(
            'click',
            function () {

                clearProfileError();


                populateProfileForm(
                    window.CURRENT_USER || {}
                );


                openModal(
                    profileModal
                );

            }
        );


    $('account-profile-row')
        ?.addEventListener(
            'click',
            function () {

                clearProfileError();


                populateProfileForm(
                    window.CURRENT_USER || {}
                );


                openModal(
                    profileModal
                );

            }
        );


    $('account-email-row')
        ?.addEventListener(
            'click',
            function () {

                clearProfileError();


                populateProfileForm(
                    window.CURRENT_USER || {}
                );


                openModal(
                    profileModal
                );

            }
        );


    $('account-password-row')
        ?.addEventListener(
            'click',
            function () {

                clearPasswordError();


                passwordForm?.reset();


                openModal(
                    passwordModal
                );

            }
        );


    function openGoalEditor() {

        const goal =
            getGoal();


        if ($('goal-input')) {

            $('goal-input').value =
                goal.title || '';

        }


        if ($('goal-target')) {

            $('goal-target').value =
                goal.target || 5;

        }


        openModal(
            goalModal
        );

    }


    $('current-goal-row')
        ?.addEventListener(
            'click',
            openGoalEditor
        );


    $('update-goals-row')
        ?.addEventListener(
            'click',
            openGoalEditor
        );


/* =====================================================
   TAGLINE
===================================================== */

const editTaglineButton =
    document.getElementById('edit-tagline');

const profileTagline =
    document.getElementById('profile-tagline');

function getSavedTagline() {

    return (
        localStorage.getItem(
            'studyPlannerTagline'
        ) ||
        'Better plans. Bigger goals.'
    );

}


function renderTagline() {

    if (!profileTagline) {
        return;
    }

    profileTagline.textContent =
        getSavedTagline();

}


if (editTaglineButton) {

    /*
     * Load the saved tagline when the page starts.
     */
    renderTagline();


    editTaglineButton.addEventListener(
        'click',
        function (event) {

            event.preventDefault();
            event.stopPropagation();


            const current =
                getSavedTagline();


            const value =
                window.prompt(
                    'Enter your personal tagline:',
                    current
                );


            /*
             * Cancel.
             */
            if (value === null) {
                return;
            }


            const cleaned =
                value.trim();


            /*
             * Do not allow a completely empty tagline.
             */
            if (!cleaned) {

                showToast(
                    'Please enter a tagline.',
                    'error'
                );

                return;
            }


            /*
             * Prevent very long text.
             */
            if (cleaned.length > 160) {

                showToast(
                    'Your tagline must be 160 characters or less.',
                    'error'
                );

                return;
            }


            /*
             * Save it in this browser.
             */
            localStorage.setItem(
                'studyPlannerTagline',
                cleaned
            );


            /*
             * Immediately display it.
             */
            renderTagline();


            showToast(
                'Tagline updated successfully.',
                'success'
            );

        }
    );

}



    /* =====================================================
       MODAL CLOSE BUTTONS
    ===================================================== */

    $('close-profile-modal')
        ?.addEventListener(
            'click',
            function () {

                closeModal(
                    profileModal
                );

            }
        );


    $('cancel-profile')
        ?.addEventListener(
            'click',
            function () {

                closeModal(
                    profileModal
                );

            }
        );


    $('close-password-modal')
        ?.addEventListener(
            'click',
            function () {

                closeModal(
                    passwordModal
                );

            }
        );


    $('cancel-password')
        ?.addEventListener(
            'click',
            function () {

                closeModal(
                    passwordModal
                );

            }
        );


    $('cancel-goal')
        ?.addEventListener(
            'click',
            function () {

                closeModal(
                    goalModal
                );

            }
        );


    /* =====================================================
       BACKDROP CLOSE
    ===================================================== */

    [
        profileModal,
        passwordModal,
        goalModal
    ].forEach(
        function (modal) {

            modal?.addEventListener(
                'click',
                function (event) {

                    if (
                        event.target ===
                        modal
                    ) {

                        closeModal(
                            modal
                        );

                    }

                }
            );

        }
    );


    /* =====================================================
       ESC CLOSE
    ===================================================== */

    document.addEventListener(
        'keydown',
        function (event) {

            if (
                event.key !== 'Escape'
            ) {
                return;
            }


            closeModal(
                profileModal
            );


            closeModal(
                passwordModal
            );


            closeModal(
                goalModal
            );

        }
    );


    /* =====================================================
       FORM EVENTS
    ===================================================== */

    profileForm?.addEventListener(
        'submit',
        saveProfile
    );


    passwordForm?.addEventListener(
        'submit',
        changePassword
    );


    goalForm?.addEventListener(
        'submit',
        submitGoal
    );


    /* =====================================================
       AVATAR EVENTS
    ===================================================== */

    $('change-avatar-btn')
        ?.addEventListener(
            'click',
            function () {

                avatarInput?.click();

            }
        );


    $('modal-change-avatar')
        ?.addEventListener(
            'click',
            function () {

                avatarInput?.click();

            }
        );


    avatarInput?.addEventListener(
        'change',
        function () {

            const file =
                avatarInput.files?.[0] ||
                null;


            uploadAvatar(
                file
            );

        }
    );


    /* =====================================================
       EXPORT DATA
    ===================================================== */

    $('export-data-row')
        ?.addEventListener(
            'click',
            async function () {

                const button =
                    $('export-data-row');


                if (button) {
                    button.disabled = true;
                }


                try {

                    const results =
                        await Promise.all([
                            fetchJson(
                                `${API}/me.php`
                            ),
                            fetchJson(
                                `${API}/courses.php`
                            ),
                            fetchJson(
                                `${API}/tasks.php`
                            )
                        ]);


                    const exportData = {

                        exported_at:
                            new Date()
                                .toISOString(),

                        profile:
                            results[0],

                        courses:
                            results[1],

                        tasks:
                            results[2],

                        preferences: {

                            dark_mode:
                                !!window
                                    .CURRENT_USER
                                    ?.dark_mode,

                            notifications_enabled:
                                window
                                    .CURRENT_USER
                                    ?.notifications_enabled !== false,

                            week_start_day:
                                window
                                    .CURRENT_USER
                                    ?.week_start_day ?? 1

                        },

                        academic_goal:
                            getGoal()

                    };


                    const blob =
                        new Blob(
                            [
                                JSON.stringify(
                                    exportData,
                                    null,
                                    2
                                )
                            ],
                            {
                                type:
                                    'application/json'
                            }
                        );


                    const url =
                        URL.createObjectURL(
                            blob
                        );


                    const link =
                        document.createElement(
                            'a'
                        );


                    link.href =
                        url;


                    link.download =
                        `study-planner-backup-${new Date()
                            .toISOString()
                            .slice(0, 10)}.json`;


                    document.body.appendChild(
                        link
                    );


                    link.click();


                    link.remove();


                    URL.revokeObjectURL(
                        url
                    );


                    showToast(
                        'Your Study Planner backup was exported.',
                        'success'
                    );


                } catch (error) {

                    console.error(
                        'Export failed:',
                        error
                    );


                    showToast(
                        error.message ||
                        'Could not export your data.',
                        'error'
                    );


                } finally {

                    if (button) {
                        button.disabled = false;
                    }

                }

            }
        );


    /* =====================================================
       CLEAR CACHE
    ===================================================== */

    $('clear-cache-row')
        ?.addEventListener(
            'click',
            async function () {

                const confirmed =
                    window.confirm(
                        'Clear cached Study Planner data from this browser? Your account data will not be deleted.'
                    );


                if (!confirmed) {
                    return;
                }


                try {

                    localStorage.removeItem(
                        'studyPlannerGoal'
                    );


                    localStorage.removeItem(
                        'studyPlannerTagline'
                    );


                    if (
                        'caches' in window
                    ) {

                        const keys =
                            await caches.keys();


                        await Promise.all(
                            keys.map(
                                function (key) {

                                    return caches.delete(
                                        key
                                    );

                                }
                            )
                        );

                    }


                    showToast(
                        'Browser cache cleared.',
                        'success'
                    );


                } catch (error) {

                    console.error(
                        'Cache clearing failed:',
                        error
                    );


                    showToast(
                        'Some browser cache could not be cleared.',
                        'error'
                    );

                }

            }
        );


    /* =====================================================
       SETTINGS STATS
    ===================================================== */

    async function loadStats() {

        try {

            const [
                stats,
                coursesData,
                tasksData
            ] =
                await Promise.all([
                    fetchJson(
                        `${API}/stats.php`
                    ),
                    fetchJson(
                        `${API}/courses.php`
                    ),
                    fetchJson(
                        `${API}/tasks.php`
                    )
                ]);


            const totalCourses =
                Array.isArray(
                    coursesData.courses
                )
                    ? coursesData.courses.length
                    : Number(
                        coursesData.summary
                            ?.total_courses ||
                        0
                    );


            const completedTasks =
                Number(
                    tasksData.summary
                        ?.completed ||
                    0
                );


            let studyHours = 0;


            if (
                Array.isArray(
                    tasksData.tasks
                )
            ) {

                studyHours =
                    tasksData.tasks.reduce(
                        function (
                            total,
                            task
                        ) {

                            return (
                                total +
                                Number(
                                    task.duration_hours ||
                                    0
                                )
                            );

                        },
                        0
                    );

            }


            const totalTasks =
                Number(
                    tasksData.summary
                        ?.total ||
                    0
                );


            const completionRate =
                Number(
                    stats.completion_rate ??
                    (
                        totalTasks
                            ? (
                                completedTasks /
                                totalTasks *
                                100
                            )
                            : 0
                    )
                );


            setCounter(
                'stat-total-courses',
                totalCourses,
                ''
            );


            setCounter(
                'stat-tasks-completed',
                completedTasks,
                ''
            );


            setCounter(
                'stat-study-hours',
                studyHours,
                'h'
            );


            setCounter(
                'stat-completion-rate',
                Math.round(
                    completionRate
                ),
                '%'
            );


        } catch (error) {

            console.error(
                'Settings stats failed:',
                error
            );

        }

    }


    function setCounter(
        id,
        value,
        suffix
    ) {

        const element =
            $(id);


        if (!element) {
            return;
        }


        element.textContent =
            `${value}${suffix}`;

    }


    /* =====================================================
       BROWSER PUSH
       NO TEST BUTTON
    ===================================================== */

    function setPushStatus(message) {

        if (!pushStatus) {
            return;
        }


        pushStatus.textContent =
            message;

    }


    function updatePushUI(status) {

        if (!status) {
            return;
        }


        /*
         * Main Notifications setting takes priority.
         */

        if (
            window.CURRENT_USER &&
            window.CURRENT_USER
                .notifications_enabled === false
        ) {

            setPushStatus(
                'Notifications are turned off in your Study Planner settings.'
            );


            if (enableButton) {

                enableButton.disabled =
                    true;

                enableButton.textContent =
                    'Disabled';

            }


            return;

        }


        if (!status.supported) {

            setPushStatus(
                'Browser push notifications are not supported here.'
            );


            if (enableButton) {

                enableButton.disabled =
                    true;

                enableButton.textContent =
                    'Not supported';

            }


            return;

        }


        if (
            status.permission ===
            'denied'
        ) {

            setPushStatus(
                'Notifications are blocked. Allow them in your browser site settings.'
            );


            if (enableButton) {

                enableButton.disabled =
                    false;

                enableButton.textContent =
                    'Enable';

            }


            return;

        }


        if (status.subscribed) {

            setPushStatus(
                'Enabled — this browser can receive Study Planner reminders.'
            );


            if (enableButton) {

                enableButton.disabled =
                    true;

                enableButton.textContent =
                    'Enabled';

            }


            return;

        }


        setPushStatus(

            status.permission ===
            'granted'

                ? 'Browser permission is allowed, but Study Planner push is not enabled yet.'

                : 'Click Enable to allow daily deadline reminders.'

        );


        if (enableButton) {

            enableButton.disabled =
                false;

            enableButton.textContent =
                'Enable';

        }

    }


    async function refreshPushStatus() {

        if (
            !window.StudyPlannerPush
        ) {

            setPushStatus(
                'Browser notification module is not loaded.'
            );


            return;

        }


        try {

            const status =
                await window
                    .StudyPlannerPush
                    .status();


            updatePushUI(
                status
            );


        } catch (error) {

            console.error(
                'Push status error:',
                error
            );


            setPushStatus(
                'Could not check browser notification status.'
            );

        }

    }


    if (enableButton) {

        enableButton.addEventListener(
            'click',
            async function (event) {

                event.preventDefault();
                event.stopPropagation();


                if (
                    !window.StudyPlannerPush
                ) {

                    setPushStatus(
                        'Browser notification module is unavailable.'
                    );


                    return;

                }


                /*
                 * Do not allow the Browser Push button
                 * to override the main Notifications switch.
                 */

                if (
                    window.CURRENT_USER &&
                    window.CURRENT_USER
                        .notifications_enabled === false
                ) {

                    setPushStatus(
                        'Turn on Notifications first.'
                    );


                    return;

                }


                enableButton.disabled =
                    true;


                enableButton.textContent =
                    'Enabling…';


                try {

                    await window
                        .StudyPlannerPush
                        .enable();


                    if (
                        window.CURRENT_USER
                    ) {

                        window.CURRENT_USER
                            .notifications_enabled =
                            true;

                    }


                    /*
                     * Keep the main preference synchronized.
                     */

                    await savePreference({
                        notifications_enabled:
                            true
                    });


                    await refreshPushStatus();


                    showToast(
                        'Browser notifications enabled.',
                        'success'
                    );


                } catch (error) {

                    console.error(
                        'Enable notification error:',
                        error
                    );


                    setPushStatus(
                        error.message ||
                        'Could not enable browser notifications.'
                    );


                    showToast(
                        error.message ||
                        'Could not enable browser notifications.',
                        'error'
                    );


                    enableButton.disabled =
                        false;


                    enableButton.textContent =
                        'Enable';

                }

            }
        );

    }


    /* =====================================================
       INITIALIZE
    ===================================================== */

    async function initializeSettings() {

        try {

            if (
                window.APP_READY &&
                typeof window.APP_READY.then ===
                    'function'
            ) {

                await window.APP_READY;

            }


            const user =
                window.CURRENT_USER;


            if (user) {

                updateProfileDisplay(
                    user
                );


                populateProfileForm(
                    user
                );


                await loadPreferences(
                    user
                );

            }


            const tagline =
                localStorage.getItem(
                    'studyPlannerTagline'
                );


            if (tagline) {

                const taglineContainer =
                    $('edit-tagline')
                        ?.parentElement;


                if (taglineContainer) {

                    const textNode =
                        Array.from(
                            taglineContainer.childNodes
                        ).find(
                            function (node) {

                                return (
                                    node.nodeType ===
                                    Node.TEXT_NODE &&
                                    node.textContent.trim()
                                );

                            }
                        );


                    if (textNode) {

                        textNode.textContent =
                            tagline;

                    }

                }

            }


            await Promise.all([
                loadStats(),
                loadGoalData(),
                refreshPushStatus()
            ]);


            if (
                window.lucide &&
                typeof window.lucide.createIcons ===
                    'function'
            ) {

                window.lucide.createIcons();

            }


        } catch (error) {

            console.error(
                'Settings initialization failed:',
                error
            );

        }

    }


    initializeSettings();

})();