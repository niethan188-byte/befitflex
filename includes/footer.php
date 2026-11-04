  </main>
</div>

<?php
/* Navigation targets the command palette can jump to, built from the same
   menu the sidebar renders — one source of truth for both. */
$cmdNav = [];
foreach (($menus[$u['type']] ?? []) as $links) {
    foreach ($links as [$k, $label, $icon, $href]) {
        $cmdNav[] = ['label' => $label, 'icon' => ltrim($icon, 'fa-'), 'href' => $href, 'key' => $k];
    }
}
$root = $u['type'] === 'admin' ? 'admin/' : 'member/';
$goto = [
    'd' => $root . 'dashboard.php',
    'n' => $root . 'notifications.php',
    'a' => $u['type'] === 'member' ? 'member/attendance.php' : 'admin/analytics.php',
    'm' => $u['type'] === 'admin' ? 'admin/members.php' : 'member/attendance.php',
    'p' => $u['type'] === 'member' ? 'member/payments.php' : 'admin/payments.php',
];

/* Bottom tab bar for phones — the four things each role reaches for most. */
$tabs = match ($u['type']) {
    'admin' => [
        ['admin/dashboard.php', 'Home',      'gauge-high', 'dashboard'],
        ['admin/members.php',   'Members',   'users',      'members'],
        ['admin/analytics.php', 'Insights',  'chart-pie',  'analytics'],
        ['kiosk.php',           'Check-in',  'door-open',  'kiosk'],
    ],
    default => [
        ['member/dashboard.php',   'Home',     'gauge-high',      'dashboard'],
        ['member/classes.php',     'Classes',  'people-group',    'classes'],
        ['member/payments.php',    'Payments', 'money-bill-wave', 'payments'],
        ['member/attendance.php',  'Visits',   'clipboard-user',  'attendance'],
    ],
};
?>

<nav class="tabbar" aria-label="Primary">
  <?php foreach ($tabs as [$href, $label, $icon, $key]): ?>
    <a href="<?= base_url() . $href ?>" class="<?= ($page ?? '') === $key ? 'on' : '' ?>">
      <i class="fa-solid fa-<?= $icon ?>"></i><span><?= $label ?></span>
    </a>
  <?php endforeach; ?>
</nav>

<div class="cmd" id="cmdk" role="dialog" aria-modal="true" aria-label="Search and commands">
  <div class="cmd-bg"></div>
  <div class="cmd-box">
    <div class="cmd-input-row">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input id="cmdInput" type="text" placeholder="Search members, classes, payments — or type a command"
             autocomplete="off" spellcheck="false" aria-label="Search">
      <div class="cmd-spin"></div>
      <kbd>Esc</kbd>
    </div>
    <div class="cmd-list" id="cmdList" role="listbox"></div>
    <div class="cmd-foot">
      <span><kbd>↑</kbd><kbd>↓</kbd> move</span>
      <span><kbd>↵</kbd> open</span>
      <span><kbd>?</kbd> shortcuts</span>
    </div>
  </div>
</div>

<div class="modal" id="shortcutHelp">
  <div class="modal-bg"></div>
  <div class="modal-box" style="width:min(480px,100%)">
    <h3><i class="fa-solid fa-keyboard"></i> Keyboard shortcuts</h3>
    <table class="kv" style="width:100%">
      <tr><th><kbd>Ctrl</kbd><kbd>K</kbd></th><td>Search everything</td></tr>
      <tr><th><kbd>/</kbd></th><td>Jump to the filter box on this page</td></tr>
      <tr><th><kbd>n</kbd></th><td>Add a new record here</td></tr>
      <tr><th><kbd>t</kbd></th><td>Switch light or dark</td></tr>
      <tr><th><kbd>g</kbd> then <kbd>d</kbd></th><td>Go to the dashboard</td></tr>
      <tr><th><kbd>g</kbd> then <kbd>m</kbd></th><td>Go to members</td></tr>
      <tr><th><kbd>g</kbd> then <kbd>a</kbd></th><td>Go to analytics</td></tr>
      <tr><th><kbd>g</kbd> then <kbd>p</kbd></th><td>Go to payments</td></tr>
      <tr><th><kbd>g</kbd> then <kbd>n</kbd></th><td>Go to notifications</td></tr>
      <tr><th><kbd>Esc</kbd></th><td>Close whatever is open</td></tr>
      <tr><th><kbd>?</kbd></th><td>Show this list</td></tr>
    </table>
    <div class="modal-actions">
      <button type="button" class="btn red" onclick="closeModal('shortcutHelp')">Got it</button>
    </div>
  </div>
</div>

<script>
window.CMD_NAV    = <?= json_encode($cmdNav, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
window.CMD_GOTO   = <?= json_encode($goto, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
window.CMD_SEARCH = true;
</script>
<script src="<?= base_url() ?>assets/js/app.js?v=<?= $jsV ?? 1 ?>"></script>
<script src="<?= base_url() ?>assets/js/ui.js?v=<?= $uiJsV ?? 1 ?>"></script>
<script>
const offlineNotice = document.getElementById('offlineNotice');
const offlineNoticeText = document.getElementById('offlineNoticeText');
const offlineRefresh = document.getElementById('offlineRefresh');
let reconnecting = false;
let cachedPage = false;

function updateOfflineState() {
  const offline = !navigator.onLine;
  const readOnly = offline || reconnecting || cachedPage;
  document.body.classList.toggle('offline-readonly', readOnly);
  offlineNotice.hidden = !readOnly;
  offlineRefresh.hidden = offline;

  if (offline) {
    offlineNoticeText.textContent = 'Offline: showing saved data from your last online visit. Changes are disabled.';
  } else if (reconnecting || cachedPage) {
    offlineNoticeText.textContent = 'Showing saved data. Refresh this page to load the latest data and re-enable changes.';
  }

  document.querySelectorAll('form[method="post" i] button, form[method="post" i] input, form[method="post" i] select, form[method="post" i] textarea')
    .forEach((control) => {
      if (control.dataset.bffOfflineDisabled === undefined) {
        control.dataset.bffOfflineDisabled = control.disabled ? 'true' : 'false';
      }
      control.disabled = readOnly || control.dataset.bffOfflineDisabled === 'true';
    });
}

offlineRefresh.addEventListener('click', () => window.location.reload());
window.addEventListener('offline', () => {
  reconnecting = false;
  updateOfflineState();
});
window.addEventListener('online', () => {
  reconnecting = true;
  updateOfflineState();
});
document.addEventListener('submit', (event) => {
  if (document.body.classList.contains('offline-readonly')
      && event.target.matches('form[method="post" i]')) {
    event.preventDefault();
    offlineNotice.hidden = false;
    offlineNoticeText.textContent = 'Changes cannot be saved offline. Reconnect and refresh this page before trying again.';
  }
}, true);
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'BFF_OFFLINE_PAGE') {
      cachedPage = true;
      updateOfflineState();
    }
  });
}
updateOfflineState();

if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('<?= base_url() ?>sw.js', { scope: '<?= base_url() ?>' })
      .catch((error) => console.error('Be Fit Flex offline support could not be enabled.', error));
  });
}
</script>
</body>
</html>
