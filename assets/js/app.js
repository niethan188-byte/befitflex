/* Small helpers shared by every page. No framework, no build step. */

/* openModal / closeModal / toggleSidebar and the table filters now live in
   ui.js, which adds focus trapping, ESC handling and focus restoration.
   These fallbacks only run if ui.js has not loaded. */
if (typeof window.openModal !== 'function') {
  window.openModal = function (id) {
    const m = document.getElementById(id);
    if (m) { m.classList.add('open'); document.body.style.overflow = 'hidden'; }
  };
  window.closeModal = function (id) {
    const m = document.getElementById(id);
    if (m) { m.classList.remove('open'); document.body.style.overflow = ''; }
  };
}

document.addEventListener('click', (ev) => {
  if (ev.target.classList.contains('modal-bg')) {
    ev.target.closest('.modal').classList.remove('open');
    document.body.style.overflow = '';
  }
});

document.addEventListener('keydown', (ev) => {
  if (ev.key === 'Escape') {
    document.querySelectorAll('.modal.open').forEach(m => m.classList.remove('open'));
    document.body.style.overflow = '';
  }
});

/** Fill an edit modal from a row's data-* attributes. */
function fillForm(formId, data) {
  const form = document.getElementById(formId);
  if (!form) return;
  for (const [k, v] of Object.entries(data)) {
    const el = form.elements[k];
    if (el) el.value = v ?? '';
  }
}

if (typeof window.toggleSidebar !== 'function') {
  window.toggleSidebar = function () {
    document.querySelector('.sidebar')?.classList.toggle('open');
  };
}

/* Toast dismissal and table filtering are handled by ui.js, which also
   keeps filtering and pagination in step with each other. */

/* Cursor-tracked sheen on glass cards — the light follows the pointer. */
document.querySelectorAll('.glass.hover').forEach(card => {
  card.addEventListener('pointermove', (ev) => {
    const r = card.getBoundingClientRect();
    card.style.setProperty('--mx', ((ev.clientX - r.left) / r.width * 100) + '%');
    card.style.setProperty('--my', ((ev.clientY - r.top) / r.height * 100) + '%');
  });
});
