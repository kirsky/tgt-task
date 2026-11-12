/**
 * HR panel entry: Bootstrap styles and components, plus small panel behaviours.
 */
import './app.scss';
import 'bootstrap';

/**
 * Prevents double submission of forms that create things in Bitrix24
 * (e.g. «Принять нового сотрудника» clicked twice creates two hires).
 */
document.addEventListener('submit', (event) => {
  const form = event.target;
  if (!(form instanceof HTMLFormElement)) return;
  form.querySelectorAll('button[type="submit"], button:not([type])').forEach((btn) => {
    btn.disabled = true;
    btn.classList.add('disabled');
  });
});

/** Follows the OS light/dark preference with Bootstrap's colour modes. */
const applyTheme = () => {
  const dark = window.matchMedia('(prefers-color-scheme: dark)').matches;
  document.documentElement.setAttribute('data-bs-theme', dark ? 'dark' : 'light');
};
applyTheme();
window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', applyTheme);
