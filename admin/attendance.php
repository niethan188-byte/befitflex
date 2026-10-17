<?php
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
$u = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';

    if ($act === 'check_in') {
        $memberId = trim((string) ($_POST['member_id'] ?? ''));
        $member = row("SELECT member_id FROM members WHERE member_id = ? AND status = 'Active'", [$memberId]);
        if (!$member) {
            flash('Choose an active member from the search results.', 'err');
        } else {
            $open = scalar('SELECT COUNT(*) FROM attendance WHERE member_id = ? AND check_out_time IS NULL', [$memberId]);
            if ($open) {
                flash('That member is already checked in.', 'err');
            } else {
                $aid = next_id('attendance', 'attendance_id', 'ATT', 6);
                db()->prepare(
                    'INSERT INTO attendance (attendance_id,member_id,check_in_time,attendance_date)
                     VALUES (?,?,NOW(),CURDATE())'
                )->execute([$aid, $memberId]);
                log_activity('Check in', 'Attendance', $memberId);
                flash('Checked in.');
            }
        }
    }

    if ($act === 'check_out') {
        db()->prepare('UPDATE attendance SET check_out_time = NOW() WHERE attendance_id = ?')
            ->execute([$_POST['attendance_id']]);
        flash('Checked out.');
    }

    redirect('attendance.php');
}

$page = 'attendance';
$bodyClass = 'admin-attendance';
$title = 'Attendance';
$subtitle = 'Gym floor check-ins and class attendance.';

$today   = rows(
     'SELECT a.*, COALESCE(m.member_name, d.visitor_name) member_name, d.pass_id
       FROM attendance a LEFT JOIN members m ON m.member_id = a.member_id
       LEFT JOIN day_passes d ON d.pass_id = a.day_pass_id
      WHERE a.attendance_date = CURDATE() ORDER BY a.check_in_time DESC'
);
$history = rows(
     'SELECT a.*, COALESCE(m.member_name, d.visitor_name) member_name, d.pass_id
       FROM attendance a LEFT JOIN members m ON m.member_id = a.member_id
       LEFT JOIN day_passes d ON d.pass_id = a.day_pass_id
      WHERE a.attendance_date < CURDATE() ORDER BY a.check_in_time DESC LIMIT 40'
);
$classAtt = rows(
    'SELECT ca.*, c.class_name, m.member_name
       FROM class_attendance ca
       JOIN classes c ON c.class_id = ca.class_id
       JOIN members m ON m.member_id = ca.member_id
      ORDER BY ca.attendance_date DESC LIMIT 30'
);
$members = rows("SELECT member_id, member_name FROM members WHERE status='Active' ORDER BY member_name");
$inside  = (int) scalar('SELECT COUNT(*) FROM attendance WHERE check_out_time IS NULL AND attendance_date = CURDATE()');

include __DIR__ . '/../includes/header.php';
?>

<div class="stats">
  <div class="stat glass hover"><div class="ico"><i class="fa-solid fa-door-open"></i></div>
    <div class="label">Check-ins today</div><div class="value"><?= count($today) ?></div>
    <div class="note"><?= $inside ?> still inside</div></div>
  <div class="stat glass hover"><div class="ico"><i class="fa-solid fa-calendar-week"></i></div>
    <div class="label">This week</div>
    <div class="value"><?= (int) scalar('SELECT COUNT(*) FROM attendance WHERE YEARWEEK(attendance_date,1) = YEARWEEK(CURDATE(),1)') ?></div>
    <div class="note">Total visits</div></div>
  <div class="stat glass hover"><div class="ico"><i class="fa-solid fa-people-group"></i></div>
    <div class="label">Class records</div>
    <div class="value"><?= (int) scalar('SELECT COUNT(*) FROM class_attendance') ?></div>
    <div class="note">All time</div></div>
</div>

<div class="panel glass">
  <h2><i class="fa-solid fa-clipboard-user"></i> Today · <?= date('l, M j') ?>
    <span class="spacer"></span>
    <a class="btn red sm" href="qr-attendance.php"><i class="fa-solid fa-qrcode"></i> Scan QR</a>
    <button class="btn sm" onclick="openCheckinModal()"><i class="fa-solid fa-right-to-bracket"></i> Check a member in</button>
  </h2>

  <?php if (!$today): ?>
    <div class="empty"><i class="fa-solid fa-door-closed"></i>Nobody has checked in today.</div>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Member</th><th>In</th><th>Out</th><th>Duration</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($today as $a):
      $mins = $a['check_out_time']
        ? round((strtotime($a['check_out_time']) - strtotime($a['check_in_time'])) / 60)
        : null; ?>
      <tr>
        <td><b><?= e($a['member_name']) ?></b><div class="mono"><?= e($a['pass_id'] ?: $a['member_id']) ?></div></td>
        <td><?= date('g:i A', strtotime($a['check_in_time'])) ?></td>
        <td><?= $a['check_out_time'] ? date('g:i A', strtotime($a['check_out_time'])) : '<span class="chip warn">inside</span>' ?></td>
        <td><?= $mins !== null ? $mins . ' min' : '—' ?></td>
        <td>
          <?php if (!$a['check_out_time']): ?>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="check_out">
              <input type="hidden" name="attendance_id" value="<?= e($a['attendance_id']) ?>">
              <button class="btn sm"><i class="fa-solid fa-right-from-bracket"></i> Check out</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="grid c2">
  <div class="panel glass">
    <h2><i class="fa-solid fa-clock-rotate-left"></i> Recent visits</h2>
    <?php if (!$history): ?><div class="empty"><i class="fa-solid fa-clock"></i>No earlier records.</div><?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Member</th><th>Date</th><th>In</th><th>Out</th></tr></thead>
      <tbody>
      <?php foreach ($history as $a): ?>
        <tr>
          <td><?= e($a['member_name']) ?></td>
          <td><?= dt($a['attendance_date']) ?></td>
          <td><?= date('g:i A', strtotime($a['check_in_time'])) ?></td>
          <td><?= $a['check_out_time'] ? date('g:i A', strtotime($a['check_out_time'])) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

  <div class="panel glass">
    <h2><i class="fa-solid fa-people-group"></i> Class attendance</h2>
    <?php if (!$classAtt): ?><div class="empty"><i class="fa-solid fa-people-group"></i>No class records.</div><?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Class</th><th>Member</th><th>Date</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($classAtt as $c): ?>
        <tr>
          <td><?= e($c['class_name']) ?></td>
          <td><?= e($c['member_name']) ?></td>
          <td><?= dt($c['attendance_date']) ?></td>
          <td><?= badge($c['attendance_status']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>

<div class="modal" id="mIn">
  <div class="modal-bg"></div>
  <div class="modal-box">
    <h3><i class="fa-solid fa-right-to-bracket"></i> Check a member in</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="check_in">
      <div class="field-row member-picker" data-member-picker>
        <label for="attendanceMemberSearch">Member</label>
        <input type="search" id="attendanceMemberSearch" placeholder="Search by name or member ID…"
               autocomplete="off" required aria-autocomplete="list"
               aria-controls="attendanceMemberResults" aria-expanded="false">
        <input type="hidden" name="member_id" id="attendanceMemberId">
        <div class="member-picker-results" id="attendanceMemberResults" role="listbox" hidden></div>
        <small class="member-picker-hint">Type a name or member ID, then choose a result.</small>
        <div class="member-picker-options" hidden>
          <?php foreach ($members as $m): ?>
            <span data-member-id="<?= e($m['member_id']) ?>"
                  data-member-name="<?= e($m['member_name']) ?>"></span>
          <?php endforeach; ?>
        </div>
      </div>
      <p class="note">The check-in time is stamped from the server clock.</p>
      <div class="modal-actions">
        <button type="button" class="btn" onclick="closeModal('mIn')">Cancel</button>
        <button class="btn red"><i class="fa-solid fa-check"></i> Check in</button>
      </div>
    </form>
  </div>
</div>

<script>
function openCheckinModal() {
  const form = document.querySelector('#mIn form');
  form.reset();
  document.getElementById('attendanceMemberId').value = '';
  openModal('mIn');
  window.setTimeout(() => document.getElementById('attendanceMemberSearch').focus(), 0);
}

(() => {
  const picker = document.querySelector('[data-member-picker]');
  if (!picker) return;

  const input = picker.querySelector('#attendanceMemberSearch');
  const memberId = picker.querySelector('#attendanceMemberId');
  const results = picker.querySelector('#attendanceMemberResults');
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

  const chooseMember = member => {
    input.value = member.label;
    memberId.value = member.id;
    input.setCustomValidity('');
    closeResults();
  };

  const renderResults = () => {
    const query = input.value.trim().toLocaleLowerCase();
    memberId.value = '';
    input.setCustomValidity(query ? 'Choose a member from the search results.' : '');
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
      empty.textContent = 'No matching active members.';
      results.appendChild(empty);
    } else {
      matches.forEach((member, index) => {
        const option = document.createElement('button');
        option.type = 'button';
        option.className = 'member-picker-option';
        option.id = `attendanceMemberOption${index}`;
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
    input.setAttribute('aria-activedescendant', `attendanceMemberOption${activeIndex}`);
  });

  input.form.addEventListener('submit', event => {
    if (!memberId.value) {
      event.preventDefault();
      input.setCustomValidity('Choose a member from the search results.');
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
