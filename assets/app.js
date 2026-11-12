/**
 * HR panel entry (an ES module, so strict mode applies to the whole file): self-hosted Onest font, Bootstrap styles/components, Bootstrap Icons,
 * and small panel behaviours (copy buttons, double-submit guard, colour mode).
 */
import '@fontsource-variable/onest';
import 'bootstrap-icons/font/bootstrap-icons.css';
import './app.scss';
import 'bootstrap';

/**
 * Copy buttons: `<button data-copy="#input-id">` copies the input's value with the Clipboard
 * API and shows «Скопировано» for a moment. If the browser refuses (no permission, insecure
 * context), the text is selected so the user can copy it with Ctrl+C.
 */
document.addEventListener('click', async (event) => {
  const button = event.target instanceof Element ? event.target.closest('[data-copy]') : null;
  if (!(button instanceof HTMLButtonElement)) return;
  const source = document.querySelector(button.dataset.copy ?? '');
  if (!(source instanceof HTMLInputElement)) return;

  const label = button.querySelector('.copy-label');
  const icon = button.querySelector('.bi');
  const before = label?.textContent ?? '';
  try {
    await navigator.clipboard.writeText(source.value);
  } catch {
    source.focus();
    source.select();
    if (label) label.textContent = 'Нажмите Ctrl+C';
    return;
  }
  if (label) label.textContent = 'Скопировано';
  icon?.classList.replace('bi-clipboard', 'bi-check2');
  button.classList.replace('btn-outline-primary', 'btn-success');
  setTimeout(() => {
    if (label) label.textContent = before;
    icon?.classList.replace('bi-check2', 'bi-clipboard');
    button.classList.replace('btn-success', 'btn-outline-primary');
  }, 1800);
});

/**
 * Prevents double submission of forms that create things in Bitrix24
 * (e.g. «Принять нового сотрудника» clicked twice would create two hires).
 */
document.addEventListener('submit', (event) => {
  const form = event.target;
  if (!(form instanceof HTMLFormElement)) return;
  form.querySelectorAll('button[type="submit"]').forEach((btn) => {
    btn.disabled = true;
    btn.classList.add('disabled');
  });
});

/** Follows the OS light/dark preference with Bootstrap's colour modes. */
const media = window.matchMedia('(prefers-color-scheme: dark)');
const applyTheme = () =>
  document.documentElement.setAttribute('data-bs-theme', media.matches ? 'dark' : 'light');
applyTheme();
media.addEventListener('change', applyTheme);
