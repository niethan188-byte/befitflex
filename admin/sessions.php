<?php
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
$u = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';

    if ($act === 'create') {
        $sid = next_id('sessions', 'session_id', 'SES', 5);
        $day = date('l', strtotime((string) $_POST['session_date']));
        db()->prepare(
            'INSERT INTO sessions (session_id,session_name,session_date,session_time,day_of_week,
                                   session_plan,member_id,session_status)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$sid, $_POST['session_name'], $_POST['session_date'], $_POST['session_time'], $day,
                    $_POST['session_plan'], $_POST['member_id'] ?: null, $_POST['session_status']]);

        if ($_POST['member_id']) {
            $m = row('SELECT user_id FROM members WHERE member_id = ?', [$_POST['member_id']]);
            if ($m) {
                notify((int) $m['user_id'], 'system', 'Session scheduled',
                    $_POST['session_name'] . ' on ' . dt($_POST['session_date']) . ' at ' . timeh($_POST['session_time']),
                    'calendar-check', 'info', 'member/dashboard.php');
            }
        }
        log_activity('Create session', 'Sessions', $sid);
        flash('Session ' . $sid . ' scheduled.');
    }

    if ($act === 'status') {
        db()->prepare('UPDATE sessions SET session_status = ? WHERE session_id = ?')
            ->execute([$_POST['session_status'], $_POST['session_id']]);
        log_activity('Update session', 'Sessions', (string) $_POST['session_id']);
        flash('Session marked ' . strtolower((string) $_POST['session_status']) . '.');
    }

    if ($act === 'delete') {
        db()->prepare('DELETE FROM sessions WHERE session_id = ?')->execute([$_POST['session_id']]);
        log_activity('Delete session', 'Sessions', (string) $_POST['session_id']);
        flash('Session deleted.');
    }

    redirect('sessions.php');
}

$page = 'sessions';
$title = 'Sessions';
$subtitle = 'Member sessions and scheduled check-ins.';
$bodyClass = 'admin-sessions';

$sessions = rows(
    'SELECT s.*, m.member_name
       FROM sessions s
       LEFT JOIN members  m ON m.member_id  = s.member_id
      ORDER BY s.session_date DESC, s.session_time DESC'
);
$members  = rows("SELECT member_id, member_name FROM members WHERE status='Active' ORDER BY member_name");

include __DIR__ . '/../includes/header.php';
?>

<div class="panel glass">
  <h2><i class="fa-solid fa-calendar-day"></i> <?= count($sessions) ?> session<?= count($sessions) === 1 ? '' : 's' ?>
    <span class="spacer"></span>
    <input type="search" placeholder="Filter…" data-filter-for="tblSes" style="width:200px">
    <button class="btn red sm" data-shortcut="new" onclick="openModal('mSes')"><i class="fa-solid fa-plus"></i> Schedule session</button>
  </h2>

  <?php if (!$sessions): ?>
    <div class="empty"><i class="fa-solid fa-calendar-xmark"></i>No sessions yet.</div>
  <?php else: ?>
  <div class="table-wrap"><table id="tblSes">
    <thead><tr><th>Session</th><th>Member</th><th>When</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($sessions as $s): ?>
      <tr>
        <td><b><?= e($s['session_name']) ?></b>
            <div class="note" style="max-width:340px"><?= e($s['session_plan']) ?></div></td>
        <td><?= e($s['member_name'] ?? '—') ?></td>
        <td><?= dt($s['session_date']) ?><div class="mono"><?= e($s['day_of_week']) ?> · <?= timeh($s['session_time']) ?></div></td>
        <td><?= badge($s['session_status']) ?></td>
        <td style="white-space:nowrap">
          <?php if ($s['session_status'] === 'Scheduled'): ?>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="status">
              <input type="hidden" name="session_id" value="<?= e($s['session_id']) ?>">
              <input type="hidden" name="session_status" value="Completed">
              <button class="btn sm" title="Mark completed"><i class="fa-solid fa-check"></i></button>
            </form>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="status">
              <input type="hidden" name="session_id" value="<?= e($s['session_id']) ?>">
              <input type="hidden" name="session_status" value="Cancelled">
              <button class="btn sm icon danger" title="Cancel"><i class="fa-solid fa-xmark"></i></button>
            </form>
          <?php endif; ?>
          <form method="post" style="display:inline" onsubmit="return confirm('Delete this session?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="session_id" value="<?= e($s['session_id']) ?>">
            <button class="btn sm icon danger"><i class="fa-solid fa-trash"></i></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="modal" id="mSes">
  <div class="modal-bg"></div>
  <div class="modal-box">
    <h3><i class="fa-solid fa-calendar-plus"></i> Schedule a session</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">

      <div class="field-row"><label>Session name</label>
        <input type="text" name="session_name" required placeholder="Anna — Squat technique"></div>

      <div class="form-grid">
        <div class="field-row member-picker" data-member-picker>
          <label for="sessionMemberSearch">Member</label>
          <input type="search" id="sessionMemberSearch" placeholder="Search members…"
                 autocomplete="off" aria-autocomplete="list" aria-controls="sessionMemberResults"
                 aria-expanded="false">
          <input type="hidden" name="member_id" id="sessionMemberId">
          <div class="member-picker-results" id="sessionMemberResults" role="listbox" hidden></div>
          <small class="member-picker-hint">Leave blank for an open session.</small>
          <div class="member-picker-options" hidden>
            <?php foreach ($members as $m): ?>
              <span data-member-id="<?= e($m['member_id']) ?>"
                    data-member-name="<?= e($m['member_name']) ?>"></span>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="form-grid">
        <div class="field-row"><label>Date</label>
          <input type="date" name="session_date" required value="<?= date('Y-m-d') ?>"></div>
        <div class="field-row"><label>Time</label>
          <input type="time" name="session_time" required value="07:00"></div>
      </div>

      <div class="field-row"><label>Session plan</label>
        <textarea name="session_plan" rows="3" required placeholder="Warm-up, squat progression 5x5, accessory lunges."></textarea></div>

      <div class="field-row"><label>Status</label>
        <select name="session_status"><option>Scheduled</option><option>Completed</option><option>Cancelled</option></select></div>

      <div class="modal-actions">
        <button type="button" class="btn" onclick="closeModal('mSes')">Cancel</button>
        <button class="btn red"><i class="fa-solid fa-check"></i> Schedule</button>
      </div>
    </form>
  </div>
</div>

<script>
(() => {
  const picker = document.querySelector('[data-member-picker]');
  if (!picker) return;

  const input = picker.querySelector('#sessionMemberSearch');
  const memberId = picker.querySelector('#sessionMemberId');
  const results = picker.querySelector('#sessionMemberResults');
  const members = Array.from(picker.querySelectorAll('.member-picker-options [data-member-id]'))
    .map(option => ({
      id: option.dataset.memberId,
      name: option.dataset.memberName,
      label: `${option.dataset.memberName} · ${option.dataset.memberId}`
    }));
  let matches = [];
  let activeIndex = -1;

  const closeResults = () => {
    results.hidden = true;
    input.setAttribute('aria-expanded', 'false');
    input.removeAttribute('aria-activedescendant');
    activeIndex = -1;
  };

  const chooseMember = (member) => {
    input.value = member.label;
    memberId.value = member.id;
    input.setCustomValidity('');
    closeResults();
  };

  const renderResults = () => {
    const query = input.value.trim().toLocaleLowerCase();
    memberId.value = '';
    input.setCustomValidity(query
      ? 'Choose a member from the results, or clear the field for an open session.'
      : '');
    results.replaceChildren();
    activeIndex = -1;

    if (!query) {
      closeResults();
      return;
    }

    matches = members.filter(member =>
      `${member.name} ${member.id}`.toLocaleLowerCase().includes(query)
    ).slice(0, 8);

    if (!matches.length) {
      const empty = document.createElement('div');
      empty.className = 'member-picker-empty';
      empty.textContent = 'No matching members.';
      results.appendChild(empty);
    } else {
      matches.forEach((member, index) => {
        const option = document.createElement('button');
        option.type = 'button';
        option.className = 'member-picker-option';
        option.id = `sessionMemberOption${index}`;
        option.setAttribute('role', 'option');
        option.setAttribute('aria-selected', 'false');

        const name = document.createElement('span');
        name.textContent = member.name;
        const id = document.createElement('small');
        id.textContent = member.id;
        option.append(name, id);
        option.addEventListener('click', () => chooseMember(member));
        results.appendChild(option);
      });
    }

    results.hidden = false;
    input.setAttribute('aria-expanded', 'true');
  };

  input.addEventListener('input', renderResults);
  input.addEventListener('keydown', event => {
    if (results.hidden) return;
    if (event.key === 'ArrowDown' && matches.length) {
      event.preventDefault();
      activeIndex = (activeIndex + 1) % matches.length;
    } else if (event.key === 'ArrowUp' && matches.length) {
      event.preventDefault();
      activeIndex = (activeIndex - 1 + matches.length) % matches.length;
    } else if (event.key === 'Enter' && activeIndex >= 0) {
      event.preventDefault();
      chooseMember(matches[activeIndex]);
      return;
    } else if (event.key === 'Escape') {
      closeResults();
      return;
    } else {
      return;
    }

    Array.from(results.querySelectorAll('[role="option"]')).forEach((option, index) => {
      const active = index === activeIndex;
      option.classList.toggle('active', active);
      option.setAttribute('aria-selected', String(active));
    });
    input.setAttribute('aria-activedescendant', `sessionMemberOption${activeIndex}`);
  });

  input.form.addEventListener('submit', event => {
    if (input.value.trim() && !memberId.value) {
      event.preventDefault();
      input.setCustomValidity('Choose a member from the results, or clear the field for an open session.');
      input.reportValidity();
    }
  });

  input.form.addEventListener('reset', () => {
    window.setTimeout(() => {
      memberId.value = '';
      input.setCustomValidity('');
      closeResults();
    });
  });

  document.addEventListener('click', event => {
    if (!picker.contains(event.target)) closeResults();
  });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
