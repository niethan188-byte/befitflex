(function () {
  'use strict';

  function getTheme() {
    try {
      return localStorage.getItem('bff_theme') || 'dark';
    } catch (error) {
      return 'dark';
    }
  }

  function applyTheme(theme) {
    const selected = theme === 'light' ? 'light' : 'dark';
    document.documentElement.dataset.theme = selected;
    try {
      localStorage.setItem('bff_theme', selected);
    } catch (error) {
      // The page still changes theme when browser storage is unavailable.
    }
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.content = selected === 'light' ? '#F4F5F7' : '#121212';
    updateThemeIcon();
  }

  function updateThemeIcon() {
    const icon = document.getElementById('themeIcon');
    if (icon) icon.className = document.documentElement.dataset.theme === 'light'
      ? 'fa-solid fa-moon'
      : 'fa-solid fa-sun';
  }

  applyTheme(getTheme());
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', updateThemeIcon, { once: true });
  }
  window.toggleTheme = function () {
    applyTheme(document.documentElement.dataset.theme === 'light' ? 'dark' : 'light');
  };
})();
