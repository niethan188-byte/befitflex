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
    'm' => $u['type'] === 'admin' ? 'admin/members.php' : 'member/workout-plan.php',
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
        ['member/workout-plan.php','Plan',     'list-check',      'plan'],
        ['member/classes.php',     'Classes',  'people-group',    'classes'],
        ['member/progress.php',    'Progress', 'chart-line',      'progress'],
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
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('<?= base_url() ?>sw.js', { scope: '<?= base_url() ?>' })
      .catch(() => {});
  });
}
</script>
</body>
</html>
