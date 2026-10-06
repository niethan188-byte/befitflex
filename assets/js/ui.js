/* ============================================================
   BE FIT FLEX GYM — interaction layer
   Command palette, shortcuts, table enhancement, theme, a11y.
   No framework, no build step, no dependencies.
   ============================================================ */

(function () {
  'use strict';

  const $  = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const BASE = document.body.dataset.base || '';

  /* ==========================================================
     Preferences — theme and density, remembered per browser
     ========================================================== */

  const prefs = {
    get(k, d) { try { return localStorage.getItem('bff_' + k) || d; } catch { return d; } },
    set(k, v) { try { localStorage.setItem('bff_' + k, v); } catch {} },
  };

  function applyTheme(t) {
    document.documentElement.dataset.theme = t;
    prefs.set('theme', t);
    const i = $('#themeIcon');
    if (i) i.className = t === 'light' ? 'fa-solid fa-moon' : 'fa-solid fa-sun';
    const m = $('meta[name="theme-color"]');
    if (m) m.content = t === 'light' ? '#F4F5F7' : '#121212';
  }

  function applyDensity(d) {
    document.documentElement.dataset.density = d;
    prefs.set('density', d);
    const i = $('#densityIcon');
    if (i) i.className = d === 'compact' ? 'fa-solid fa-bars-staggered' : 'fa-solid fa-bars';
  }

  applyTheme(prefs.get('theme', 'dark'));
  applyDensity(prefs.get('density', 'comfortable'));

  window.toggleTheme = () =>
    applyTheme(document.documentElement.dataset.theme === 'light' ? 'dark' : 'light');
  window.toggleDensity = () =>
    applyDensity(document.documentElement.dataset.density === 'compact' ? 'comfortable' : 'compact');

  /* ==========================================================
     Navigation progress bar
     ========================================================== */

  const prog = document.createElement('div');
  prog.id = 'nprog';
  document.body.appendChild(prog);

  let progTimer;
  function startProgress() {
    clearTimeout(progTimer);
    prog.style.opacity = '1';
    prog.style.width = '18%';
    let w = 18;
    progTimer = setInterval(() => {
      w = Math.min(w + (90 - w) * 0.12, 90);
      prog.style.width = w + '%';
    }, 180);
  }
  window.addEventListener('beforeunload', startProgress);
  window.addEventListener('pageshow', () => {
    clearInterval(progTimer);
    prog.style.width = '100%';
    setTimeout(() => { prog.style.opacity = '0'; prog.style.width = '0'; }, 320);
  });

  /* ==========================================================
     Toasts
     ========================================================== */

  function toastHost() {
    let h = $('.flash-stack');
    if (!h) {
      h = document.createElement('div');
      h.className = 'flash-stack';
      h.setAttribute('aria-live', 'polite');
      document.body.appendChild(h);
    }
    return h;
  }

  window.toast = function (msg, type = 'ok') {
    const el = document.createElement('div');
    el.className = 'flash ' + (type === 'err' ? 'err' : 'ok');
    el.innerHTML =
      '<i class="fa-solid fa-' + (type === 'err' ? 'circle-exclamation' : 'circle-check') + '"></i>' +
      '<span></span><button class="close" aria-label="Dismiss"><i class="fa-solid fa-xmark"></i></button>';
    el.querySelector('span').textContent = msg;
    toastHost().appendChild(el);
    wireToast(el);
    return el;
  };

  function wireToast(el) {
    if (!el.querySelector('.close')) {
      const b = document.createElement('button');
      b.className = 'close';
      b.setAttribute('aria-label', 'Dismiss');
      b.innerHTML = '<i class="fa-solid fa-xmark"></i>';
      el.appendChild(b);
    }
    const dismiss = () => {
      el.style.transition = 'opacity .3s, transform .3s';
      el.style.opacity = '0';
      el.style.transform = 'translateX(28px)';
      setTimeout(() => el.remove(), 320);
    };
    el.querySelector('.close').addEventListener('click', dismiss);
    let t = setTimeout(dismiss, 5200);
    el.addEventListener('mouseenter', () => clearTimeout(t));
    el.addEventListener('mouseleave', () => { t = setTimeout(dismiss, 2200); });
  }

  $$('.flash').forEach(wireToast);

  /* ==========================================================
     Accessible modals — focus trap, ESC, restore focus
     ========================================================== */

  const FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
  let lastFocus = null;

  window.openModal = function (id) {
    const m = document.getElementById(id);
    if (!m) return;
    lastFocus = document.activeElement;
    m.classList.add('open');
    m.setAttribute('role', 'dialog');
    m.setAttribute('aria-modal', 'true');
    document.body.style.overflow = 'hidden';
    const first = m.querySelector(FOCUSABLE);
    if (first) setTimeout(() => first.focus(), 60);
  };

  window.closeModal = function (id) {
    const m = document.getElementById(id);
    if (!m) return;
    m.classList.remove('open');
    m.removeAttribute('aria-modal');
    document.body.style.overflow = '';
    if (lastFocus) { lastFocus.focus(); lastFocus = null; }
  };

  document.addEventListener('keydown', (ev) => {
    const open = $('.modal.open');
    if (!open || ev.key !== 'Tab') return;
    const items = $$(FOCUSABLE, open).filter(el => el.offsetParent !== null);
    if (!items.length) return;
    const first = items[0], last = items[items.length - 1];
    if (ev.shiftKey && document.activeElement === first) { ev.preventDefault(); last.focus(); }
    else if (!ev.shiftKey && document.activeElement === last) { ev.preventDefault(); first.focus(); }
  });

  /* ==========================================================
     Confirm dialog — replaces window.confirm on destructive forms
     ========================================================== */

  function buildConfirm() {
    if ($('#uiConfirm')) return;
    const d = document.createElement('div');
    d.className = 'modal';
    d.id = 'uiConfirm';
    d.innerHTML =
      '<div class="modal-bg"></div><div class="modal-box confirm-box">' +
      '<div class="ci-big"><i class="fa-solid fa-triangle-exclamation"></i></div>' +
      '<h3 id="ucTitle" style="margin-bottom:8px">Are you sure?</h3>' +
      '<p class="note" id="ucBody"></p>' +
      '<div class="modal-actions">' +
      '<button type="button" class="btn" id="ucNo">Cancel</button>' +
      '<button type="button" class="btn red" id="ucYes">Confirm</button>' +
      '</div></div>';
    document.body.appendChild(d);
  }

  window.uiConfirm = function (title, body, onYes, yesLabel) {
    buildConfirm();
    $('#ucTitle').textContent = title;
    $('#ucBody').textContent = body || '';
    const yes = $('#ucYes');
    yes.textContent = yesLabel || 'Confirm';
    const fresh = yes.cloneNode(true);
    yes.replaceWith(fresh);
    fresh.addEventListener('click', () => { closeModal('uiConfirm'); onYes(); });
    $('#ucNo').onclick = () => closeModal('uiConfirm');
    openModal('uiConfirm');
  };

  /* Upgrade every form still relying on the browser's confirm dialog. */
  $$('form[onsubmit*="confirm("]').forEach((form) => {
    const raw = form.getAttribute('onsubmit') || '';
    const m = raw.match(/confirm\\((['"])([\\s\\S]*?)\\1\\)/);
    const msg = m ? m[2] : 'This cannot be undone.';
    form.removeAttribute('onsubmit');
    form.addEventListener('submit', (ev) => {
      if (form.dataset.ok === '1') return;
      ev.preventDefault();
      uiConfirm('Please confirm', msg, () => { form.dataset.ok = '1'; form.submit(); }, 'Yes, continue');
    });
  });

  /* ==========================================================
     Tables — sort, filter, paginate, stack on mobile
     ========================================================== */

  const PAGE = 25;

  function cellValue(tr, i) {
    const td = tr.children[i];
    if (!td) return '';
    const s = (td.dataset.sort ?? td.textContent).trim();
    const num = parseFloat(s.replace(/[^0-9.\\-]/g, ''));
    if (s && !isNaN(num) && /[0-9]/.test(s)) return num;
    const d = Date.parse(s);
    if (!isNaN(d) && /[a-z]/i.test(s) && s.length > 5) return d;
    return s.toLowerCase();
  }

  function enhance(table) {
    const tbody = table.tBodies[0];
    const head  = table.tHead;
    if (!tbody || !head || table.dataset.enhanced) return;
    table.dataset.enhanced = '1';

    const rows = Array.from(tbody.rows);
    const heads = Array.from(head.rows[0].cells);

    /* Stack into cards on a phone, using the header text as the label. */
    table.classList.add('stackable');
    rows.forEach((tr) => {
      Array.from(tr.cells).forEach((td, i) => {
        const label = (heads[i]?.textContent || '').trim();
        if (label) td.setAttribute('data-label', label);
      });
    });

    /* Click-to-sort on every column that carries a heading. */
    heads.forEach((th, i) => {
      if (!th.textContent.trim() || th.dataset.nosort) return;
      th.classList.add('sortable');
      th.tabIndex = 0;
      th.setAttribute('role', 'button');
      const run = () => {
        const asc = !th.classList.contains('asc');
        heads.forEach(h => h.classList.remove('asc', 'desc'));
        th.classList.add(asc ? 'asc' : 'desc');
        const sorted = Array.from(tbody.rows).sort((a, b) => {
          const x = cellValue(a, i), y = cellValue(b, i);
          if (x < y) return asc ? -1 : 1;
          if (x > y) return asc ? 1 : -1;
          return 0;
        });
        sorted.forEach(r => tbody.appendChild(r));
        paginate(table, 1);
      };
      th.addEventListener('click', run);
      th.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); run(); }
      });
    });

    if (rows.length > PAGE) buildFooter(table);
    paginate(table, 1);
  }

  function buildFooter(table) {
    const wrap = table.closest('.table-wrap') || table.parentElement;
    const foot = document.createElement('div');
    foot.className = 'table-foot';
    foot.innerHTML = '<span class="count"></span><span class="spacer"></span><div class="pager"></div>';
    wrap.after(foot);
    table._foot = foot;
  }

  function visibleRows(table) {
    return Array.from(table.tBodies[0].rows).filter(r => !r.classList.contains('filtered'));
  }

  function paginate(table, page) {
    const rows = visibleRows(table);
    const foot = table._foot;
    table._page = page;

    if (!foot) {
      rows.forEach(r => r.classList.remove('row-hidden'));
      return;
    }

    const pages = Math.max(1, Math.ceil(rows.length / PAGE));
    page = Math.min(page, pages);
    table._page = page;

    Array.from(table.tBodies[0].rows).forEach(r => r.classList.add('row-hidden'));
    rows.slice((page - 1) * PAGE, page * PAGE).forEach(r => r.classList.remove('row-hidden'));

    const from = rows.length ? (page - 1) * PAGE + 1 : 0;
    foot.querySelector('.count').textContent =
      'Showing ' + from + '–' + Math.min(page * PAGE, rows.length) + ' of ' + rows.length;

    const pager = foot.querySelector('.pager');
    pager.innerHTML = '';
    const btn = (label, target, opts = {}) => {
      const b = document.createElement('button');
      b.innerHTML = label;
      if (opts.on) b.classList.add('on');
      if (opts.dis) b.disabled = true;
      if (!opts.dis) b.addEventListener('click', () => paginate(table, target));
      pager.appendChild(b);
    };
    btn('<i class="fa-solid fa-angle-left"></i>', page - 1, { dis: page === 1 });
    const win = [];
    for (let p = 1; p <= pages; p++) {
      if (p === 1 || p === pages || Math.abs(p - page) <= 1) win.push(p);
      else if (win[win.length - 1] !== '…') win.push('…');
    }
    win.forEach(p => p === '…'
      ? btn('…', 0, { dis: true })
      : btn(String(p), p, { on: p === page }));
    btn('<i class="fa-solid fa-angle-right"></i>', page + 1, { dis: page === pages });
  }

  $$('.table-wrap table, table.grid').forEach(enhance);

  /* Live filter boxes — count matches and highlight hits. */
  $$('[data-filter-for]').forEach((input) => {
    const table = document.getElementById(input.dataset.filterFor);
    if (!table || !table.tBodies[0]) return;

    if (!input.closest('.search-wrap')) {
      const w = document.createElement('span');
      w.className = 'search-wrap';
      w.innerHTML = '<i class="fa-solid fa-magnifying-glass"></i>';
      input.replaceWith(w);
      w.appendChild(input);
    }
    input.setAttribute('aria-label', 'Filter this table');

    let t;
    input.addEventListener('input', () => {
      clearTimeout(t);
      t = setTimeout(() => {
        const q = input.value.toLowerCase().trim();
        Array.from(table.tBodies[0].rows).forEach((tr) => {
          const hit = !q || tr.textContent.toLowerCase().includes(q);
          tr.classList.toggle('filtered', !hit);
        });
        paginate(table, 1);
      }, 110);
    });
  });

  /* ==========================================================
     Bulk selection
     ========================================================== */

  const master = $('#pickAll');
  if (master) {
    const boxes = () => $$('input.pick:not(#pickAll)');
    const bar   = $('#bulkBar');
    const count = $('#bulkCount');
    const field = $('#bulkIds');

    const sync = () => {
      const picked = boxes().filter(b => b.checked);
      picked.forEach(b => b.closest('tr')?.classList.add('picked'));
      boxes().filter(b => !b.checked).forEach(b => b.closest('tr')?.classList.remove('picked'));
      if (bar) bar.classList.toggle('on', picked.length > 0);
      if (count) count.textContent = picked.length + ' selected';
      if (field) field.value = picked.map(b => b.value).join(',');
      master.checked = picked.length > 0 && picked.length === boxes().length;
      master.indeterminate = picked.length > 0 && picked.length < boxes().length;
    };

    master.addEventListener('change', () => {
      boxes().forEach(b => { if (!b.closest('tr').classList.contains('row-hidden')) b.checked = master.checked; });
      sync();
    });
    document.addEventListener('change', (e) => {
      if (e.target.classList?.contains('pick')) sync();
    });
    window.bulkSync = sync;
  }

  /* ==========================================================
     Forms — inline validation and an unsaved-changes guard
     ========================================================== */

  $$('form').forEach((form) => {
    if (form.dataset.noguard !== undefined) return;
    let dirty = false;
    form.addEventListener('input', () => { dirty = true; });
    form.addEventListener('submit', () => { dirty = false; });
    window.addEventListener('beforeunload', (e) => {
      if (dirty && form.closest('.modal.open')) { e.preventDefault(); e.returnValue = ''; }
    });
  });

  $$('input[required], select[required], textarea[required]').forEach((f) => {
    f.addEventListener('invalid', () => f.setAttribute('aria-invalid', 'true'));
    f.addEventListener('input', () => f.removeAttribute('aria-invalid'));
  });

  /* Stop a double-click from submitting twice. */
  $$('form').forEach((form) => {
    form.addEventListener('submit', () => {
      const b = form.querySelector('button[type=submit], button:not([type])');
      if (b && !b.dataset.keep) {
        setTimeout(() => { b.disabled = true; b.style.opacity = '.6'; }, 10);
        setTimeout(() => { b.disabled = false; b.style.opacity = ''; }, 6000);
      }
    });
  });

  /* ==========================================================
     Mobile drawer
     ========================================================== */

  const scrim = document.createElement('div');
  scrim.className = 'drawer-scrim';
  document.body.appendChild(scrim);

  window.toggleSidebar = function () {
    const side = $('.sidebar');
    if (!side) return;
    const open = side.classList.toggle('open');
    scrim.classList.toggle('on', open);
    document.body.style.overflow = open ? 'hidden' : '';
  };
  scrim.addEventListener('click', () => toggleSidebar());
  $$('.sidebar .nav a').forEach(a => a.addEventListener('click', () => {
    if (window.innerWidth <= 980) { $('.sidebar')?.classList.remove('open'); scrim.classList.remove('on'); }
  }));

  /* ==========================================================
     Command palette
     ========================================================== */

  const cmd   = $('#cmdk');
  const cmdIn = $('#cmdInput');
  const cmdLs = $('#cmdList');

  const NAV = (window.CMD_NAV || []).map(n => Object.assign({ kind: 'nav' }, n));
  const ACTIONS = [
    { kind: 'act', icon: 'moon',        label: 'Toggle light / dark theme', run: () => toggleTheme() },
    { kind: 'act', icon: 'bars-staggered', label: 'Toggle compact rows',    run: () => toggleDensity() },
    { kind: 'act', icon: 'print',       label: 'Print this page',           run: () => window.print() },
    { kind: 'act', icon: 'keyboard',    label: 'Keyboard shortcuts',        run: () => openModal('shortcutHelp') },
    { kind: 'act', icon: 'right-from-bracket', label: 'Sign out',           run: () => location.href = BASE + 'logout.php' },
  ];

  let items = [], sel = 0, reqId = 0;

  function mark(text, q) {
    const el = document.createElement('span');
    if (!q) { el.textContent = text; return el.innerHTML; }
    const i = text.toLowerCase().indexOf(q.toLowerCase());
    if (i < 0) { el.textContent = text; return el.innerHTML; }
    const a = document.createTextNode(text.slice(0, i));
    const m = document.createElement('mark');
    m.textContent = text.slice(i, i + q.length);
    const z = document.createTextNode(text.slice(i + q.length));
    el.append(a, m, z);
    return el.innerHTML;
  }

  function render(q) {
    cmdLs.innerHTML = '';
    if (!items.length) {
      cmdLs.innerHTML = '<div class="cmd-empty">Nothing matches “' +
        q.replace(/[<>&]/g, '') + '”.</div>';
      return;
    }
    let group = null;
    items.forEach((it, i) => {
      if (it.group !== group) {
        group = it.group;
        const g = document.createElement('div');
        g.className = 'cmd-group';
        g.textContent = group;
        cmdLs.appendChild(g);
      }
      const b = document.createElement(it.href ? 'a' : 'button');
      b.className = 'cmd-item' + (i === sel ? ' sel' : '');
      if (it.href) b.href = it.href;
      b.innerHTML =
        '<span class="ci"><i class="fa-solid fa-' + (it.icon || 'circle') + '"></i></span>' +
        '<span class="cm"><b>' + mark(it.label, q) + '</b>' +
        (it.sub ? '<span>' + mark(it.sub, q) + '</span>' : '') + '</span>';
      b.addEventListener('click', (e) => { if (!it.href) { e.preventDefault(); it.run(); closeCmd(); } });
      b.addEventListener('mouseenter', () => { sel = i; paint(); });
      cmdLs.appendChild(b);
    });
  }

  function paint() {
    $$('.cmd-item', cmdLs).forEach((el, i) => el.classList.toggle('sel', i === sel));
    $$('.cmd-item', cmdLs)[sel]?.scrollIntoView({ block: 'nearest' });
  }

  function localMatches(q) {
    const ql = q.toLowerCase();
    const out = [];
    NAV.filter(n => !q || n.label.toLowerCase().includes(ql))
       .forEach(n => out.push(Object.assign({ group: 'Go to', icon: n.icon, href: BASE + n.href }, n)));
    ACTIONS.filter(a => !q || a.label.toLowerCase().includes(ql))
       .forEach(a => out.push(Object.assign({ group: 'Actions' }, a)));
    return out;
  }

  let searchTimer;
  function search(q) {
    items = localMatches(q);
    sel = 0;
    render(q);

    clearTimeout(searchTimer);
    if (q.length < 2 || !window.CMD_SEARCH) return;

    searchTimer = setTimeout(() => {
      const mine = ++reqId;
      cmd.classList.add('loading');
      fetch(BASE + 'api/search.php?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
        .then(r => r.json())
        .then((d) => {
          if (mine !== reqId) return;
          cmd.classList.remove('loading');
          if (!d.ok) return;
          const found = (d.results || []).map(r => ({
            group: r.group, icon: r.icon, label: r.label, sub: r.sub, href: BASE + r.href,
          }));
          items = found.concat(localMatches(q));
          sel = 0;
          render(q);
        })
        .catch(() => cmd.classList.remove('loading'));
    }, 200);
  }

  function openCmd() {
    if (!cmd) return;
    lastFocus = document.activeElement;
    cmd.classList.add('open');
    document.body.style.overflow = 'hidden';
    cmdIn.value = '';
    search('');
    setTimeout(() => cmdIn.focus(), 40);
  }
  function closeCmd() {
    if (!cmd) return;
    cmd.classList.remove('open');
    document.body.style.overflow = '';
    if (lastFocus) lastFocus.focus();
  }
  window.openCmd = openCmd;

  if (cmd) {
    $('.cmd-bg', cmd).addEventListener('click', closeCmd);
    cmdIn.addEventListener('input', () => search(cmdIn.value.trim()));
    cmdIn.addEventListener('keydown', (ev) => {
      if (ev.key === 'ArrowDown') { ev.preventDefault(); sel = Math.min(sel + 1, items.length - 1); paint(); }
      else if (ev.key === 'ArrowUp') { ev.preventDefault(); sel = Math.max(sel - 1, 0); paint(); }
      else if (ev.key === 'Enter') { ev.preventDefault(); $$('.cmd-item', cmdLs)[sel]?.click(); }
      else if (ev.key === 'Escape') { closeCmd(); }
    });
  }

  /* ==========================================================
     Keyboard shortcuts
     ========================================================== */

  const typing = (el) => /^(INPUT|TEXTAREA|SELECT)$/.test(el?.tagName) || el?.isContentEditable;

  document.addEventListener('keydown', (ev) => {
    if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 'k') { ev.preventDefault(); openCmd(); return; }

    if (ev.key === 'Escape') {
      if ($('.cmd.open')) { closeCmd(); return; }
      const m = $('.modal.open');
      if (m) { closeModal(m.id); return; }
      if ($('.sidebar.open')) { toggleSidebar(); return; }
    }

    if (typing(document.activeElement) || ev.ctrlKey || ev.metaKey || ev.altKey) return;

    switch (ev.key) {
      case '/':
        ev.preventDefault();
        { const f = $('[data-filter-for]'); f ? f.focus() : openCmd(); }
        break;
      case '?': ev.preventDefault(); openModal('shortcutHelp'); break;
      case 'g': window._g = true; setTimeout(() => { window._g = false; }, 900); break;
      case 'n': {
        const add = $('[data-shortcut="new"]');
        if (add) { ev.preventDefault(); add.click(); }
        break;
      }
      case 't': ev.preventDefault(); toggleTheme(); break;
      default:
        if (window._g) {
          const map = window.CMD_GOTO || {};
          if (map[ev.key]) { ev.preventDefault(); location.href = BASE + map[ev.key]; }
          window._g = false;
        }
    }
  });

  /* ==========================================================
     Small polish
     ========================================================== */

  /* Copy any element marked data-copy to the clipboard. */
  document.addEventListener('click', (e) => {
    const t = e.target.closest('[data-copy]');
    if (!t) return;
    navigator.clipboard?.writeText(t.dataset.copy).then(
      () => toast('Copied ' + t.dataset.copy),
      () => toast('Could not copy', 'err')
    );
  });

  /* Relative timestamps: <time data-ts="...">. */
  $$('time[data-ts]').forEach((el) => {
    const then = new Date(el.dataset.ts).getTime();
    if (isNaN(then)) return;
    const secs = (Date.now() - then) / 1000;
    const step = [[31536000, 'y'], [2592000, 'mo'], [604800, 'w'], [86400, 'd'], [3600, 'h'], [60, 'm']]
      .find(([s]) => secs >= s);
    el.textContent = !step ? 'just now' : Math.floor(secs / step[0]) + step[1] + ' ago';
    el.title = new Date(then).toLocaleString();
  });

  /* Auto-focus the first field when a modal opens through markup. */
  new MutationObserver((muts) => {
    muts.forEach((m) => {
      if (m.attributeName === 'class' && m.target.classList.contains('open') && m.target.classList.contains('modal')) {
        const f = m.target.querySelector('input:not([type=hidden]), select, textarea');
        if (f) setTimeout(() => f.focus(), 60);
      }
    });
  }).observe(document.body, { attributes: true, subtree: true, attributeFilter: ['class'] });

})();
