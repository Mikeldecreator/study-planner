// Tailwind's Play CDN defaults to OS-preference dark mode; we want a manual
// toggle instead, so switch it to class-based and apply the saved theme
// before the body paints (this script is loaded synchronously in <head>).
tailwind.config = {
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        surface: { DEFAULT: '#F7F5F0', dark: '#0B0B0C' },
      },
    },
  },
};

(function applyStoredTheme() {
  const stored = localStorage.getItem('darkMode');
  const isDark = stored === 'true';
  if (isDark) {
    document.documentElement.classList.add('dark');
  }
})();
