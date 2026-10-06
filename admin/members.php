<?php
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
$u = require_role('admin');

/* ---------- write actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';
    $pdo = db();

    /* ---- bulk actions ---- */
    if (str_starts_with($act, 'bulk_')) {
        $ids = array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['ids'] ?? '')))));
        if (!$ids) {
            flash('Nothing was selected.', 'err');
            redirect('members.php');
        }
        $in = implode(',', array_fill(0, count($ids), '?'));

        if ($act === 'bulk_status') {
            $st = (string) $_POST['bulk_status'];
            $pdo->prepare("UPDATE members SET status = ? WHERE member_id IN ($in)")
                ->execute(array_merge([$st], $ids));
            log_activity('Bulk status', 'Members', count($ids) . ' members → ' . $st);
            flash(count($ids) . ' members set to ' . strtolower($st) . '.');
        }

        if ($act === 'bulk_notify') {
            $msg = trim((string) $_POST['bulk_message']);
            if ($msg === '') {
                flash('Write a message first.', 'err');
                redirect('members.php');
            }
            $users = rows("SELECT user_id FROM members WHERE member_id IN ($in)", $ids);
            foreach ($users as $row) {
              notify((int) $row['user_id'], 'system', 'A message from the front desk',
                $msg, 'bullhorn', 'primary');
            }
            log_activity('Bulk notify', 'Members', count($users) . ' members');
            flash('Message sent to ' . count($users) . ' members.');
        }

        redirect('members.php');
    }

    if ($act === 'create') {
      if (!encryption_available()) {
        flash('PII encryption is unavailable in the web server. Restart Laragon and try again.', 'err');
        redirect('members.php');
      }
        $email = trim((string) $_POST['email']);
        if (scalar('SELECT COUNT(*) FROM users WHERE email = ?', [$email])) {
            flash('That email is already registered.', 'err');
        } else {
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO users (email,password,user_type) VALUES (?,?,?)')
                ->execute([$email, password_hash($_POST['password'] ?: 'member123', PASSWORD_DEFAULT), 'member']);
            $uid = (int) $pdo->lastInsertId();
            $mid = next_id('members', 'member_id', 'MEM', 4);
            $pdo->prepare(
                 'INSERT INTO members (member_id,user_id,member_name,contact_number,contact_number_encrypted,email,membership_type,join_date,status)
                  VALUES (?,?,?,?,?,?,?,?,?)'
                )->execute([$mid, $uid, trim((string) $_POST['member_name']), encrypt_pii(trim((string) $_POST['contact_number'])), encrypt_pii(trim((string) $_POST['contact_number'])),
                        $email, $_POST['membership_type'], $_POST['join_date'] ?: date('Y-m-d'),
                        $_POST['status']]);
            $pdo->prepare('INSERT INTO notification_preferences (user_id) VALUES (?)')->execute([$uid]);
            $pdo->commit();
            log_activity('Create member', 'Members', $mid . ' — ' . $_POST['member_name']);
            flash('Member ' . $mid . ' added.');
        }
    }

    if ($act === 'update') {
      if (!encryption_available()) {
        flash('PII encryption is unavailable in the web server. Restart Laragon and try again.', 'err');
        redirect('members.php');
      }
        db()->prepare(
            'UPDATE members SET member_name=?, contact_number=?, contact_number_encrypted=?, email=?, membership_type=?,
                    join_date=?, out_date=?, status=? WHERE member_id=?'
        )->execute([
            trim((string) $_POST['member_name']), encrypt_pii(trim((string) $_POST['contact_number'])),
            encrypt_pii(trim((string) $_POST['contact_number'])), trim((string) $_POST['email']), $_POST['membership_type'],
            $_POST['join_date'] ?: null, $_POST['out_date'] ?: null,
            $_POST['status'], $_POST['member_id'],
        ]);
        log_activity('Update member', 'Members', (string) $_POST['member_id']);
        flash('Member updated.');
    }

    if ($act === 'delete') {
        $m = row('SELECT user_id, member_name FROM members WHERE member_id = ?', [$_POST['member_id']]);
        if ($m) {
            // Deleting the user cascades to members, payments, attendance and the rest.
            db()->prepare('DELETE FROM users WHERE user_id = ?')->execute([$m['user_id']]);
            log_activity('Delete member', 'Members', (string) $_POST['member_id'] . ' — ' . $m['member_name']);
            flash('Member removed along with their records.');
        }
    }

    redirect('members.php');
}

$page = 'members';
$title = 'Members';
$subtitle = 'Enrollment, membership type and status.';

$filter = $_GET['status'] ?? 'all';
$joinedWindow = (int) ($_GET['joined'] ?? 0);
$sql = 'SELECT m.* FROM members m';
$args = [];
$conditions = [];
if (in_array($filter, ['Active', 'Inactive', 'Expired'], true)) {
  $conditions[] = 'm.status = ?';
  $args[] = $filter;
}
if ($joinedWindow > 0 && $joinedWindow <= 3650) {
  $conditions[] = 'm.join_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)';
  $args[] = $joinedWindow;
}
if ($conditions) {
  $sql .= ' WHERE ' . implode(' AND ', $conditions);
}
$sql .= ' ORDER BY m.member_id';
$members = rows($sql, $args);

include __DIR__ . '/../includes/header.php';
?>

<div class="tabs">
  <?php foreach (['all' => 'All', 'Active' => 'Active', 'Inactive' => 'Inactive', 'Expired' => 'Expired'] as $k => $lbl): ?>
    <a class="<?= $filter === $k ? 'on' : '' ?>" href="?status=<?= $k ?>"><?= $lbl ?></a>
  <?php endforeach; ?>
</div>

<div class="panel glass">
  <h2><i class="fa-solid fa-users"></i> <?= count($members) ?> member<?= count($members) === 1 ? '' : 's' ?>
    <span class="spacer"></span>
    <input type="search" placeholder="Filter…" data-filter-for="tblMembers" style="width:210px">
    <button class="btn red sm" data-shortcut="new" onclick="newMember()"><i class="fa-solid fa-plus"></i> Add member</button>
  </h2>

  <form method="post" id="bulkForm" class="bulk-bar" data-noguard>
    <?= csrf_field() ?>
    <input type="hidden" name="ids" id="bulkIds">
    <span class="count" id="bulkCount">0 selected</span>
    <span id="bulkBar" hidden></span>

    <select name="bulk_status" onchange="runBulk('bulk_status')" aria-label="Set status">
      <option value="">Set status…</option>
      <option value="Active">Active</option>
      <option value="Inactive">Inactive</option>
      <option value="Expired">Expired</option>
    </select>

    <button type="button" class="btn sm" onclick="openModal('mNotify')">
      <i class="fa-solid fa-bullhorn"></i> Message them</button>
    <button type="button" class="btn sm" onclick="clearPicks()">Clear</button>
    <input type="hidden" name="action" id="bulkAction">
  </form>

  <?php if (!$members): ?>
    <?= empty_state('user-slash', 'No members match this filter',
          'Try another status tab, or enrol someone new.',
          ['label' => 'Add a member', 'href' => '#', 'icon' => 'plus']) ?>
  <?php else: ?>
  <div class="table-wrap"><table id="tblMembers">
    <thead><tr>
      <th data-nosort style="width:34px"><input type="checkbox" class="pick" id="pickAll" aria-label="Select all"></th>
      <th>Member</th><th>Contact</th><th>Membership</th><th>Joined</th><th>Status</th><th data-nosort></th>
    </tr></thead>
    <tbody>
    <?php foreach ($members as $m): ?>
      <tr>
        <td><input type="checkbox" class="pick" value="<?= e($m['member_id']) ?>"
                   aria-label="Select <?= e($m['member_name']) ?>"></td>
        <td><b><?= e($m['member_name']) ?></b><?= id_chip($m['member_id']) ?></td>
        <td><?= e($m['email']) ?><div class="mono"><?= e(member_contact($m)) ?></div></td>
        <td><?= e($m['membership_type']) ?></td>
        <td><?= dt($m['join_date']) ?></td>
        <td><?= badge($m['status']) ?></td>
        <td style="white-space:nowrap">
          <button class="btn sm icon" title="Edit" onclick='editMember(<?= json_encode(array_merge($m, ['contact_number' => member_contact($m)]), JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
            <i class="fa-solid fa-pen"></i>
          </button>
          <form method="post" style="display:inline"
                onsubmit="return confirm('Remove <?= e($m['member_name']) ?>? Their payments, attendance and plans are deleted too.')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="member_id" value="<?= e($m['member_id']) ?>">
            <button class="btn sm icon danger" title="Delete"><i class="fa-solid fa-trash"></i></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="modal" id="mMember">
  <div class="modal-bg"></div>
  <div class="modal-box">
    <h3><i class="fa-solid fa-user-pen"></i> <span id="mTitle">Add member</span></h3>
    <form method="post" id="fMember">
      <?= csrf_field() ?>
      <input type="hidden" name="action" id="fAction" value="create">
      <input type="hidden" name="member_id" id="fMemberId">

      <div class="field-row"><label>Full name</label>
        <input type="text" name="member_name" required></div>

      <div class="form-grid">
        <div class="field-row"><label>Email</label><input type="email" name="email" required></div>
        <div class="field-row"><label>Contact number</label><input type="tel" name="contact_number" required></div>
      </div>

      <div class="form-grid">
        <div class="field-row"><label>Membership</label>
          <select name="membership_type" required>
            <?php foreach (array_keys(MEMBERSHIP_PRICE) as $t): ?><option><?= $t ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-grid">
        <div class="field-row"><label>Join date</label>
          <input type="date" name="join_date" value="<?= date('Y-m-d') ?>"></div>
        <div class="field-row"><label>Out date</label><input type="date" name="out_date"></div>
      </div>

      <div class="form-grid">
        <div class="field-row"><label>Status</label>
          <select name="status"><option>Active</option><option>Inactive</option><option>Expired</option></select>
        </div>
        <div class="field-row" id="pwRow"><label>Initial password</label>
          <input type="text" name="password" value="member123" placeholder="member123"></div>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn" onclick="closeModal('mMember')">Cancel</button>
        <button class="btn red"><i class="fa-solid fa-check"></i> Save member</button>
      </div>
    </form>
  </div>
</div>

<div class="modal" id="mNotify">
  <div class="modal-bg"></div>
  <div class="modal-box">
    <h3><i class="fa-solid fa-bullhorn"></i> Message the selected members</h3>
    <form method="post" data-noguard>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="bulk_notify">
      <input type="hidden" name="ids" id="notifyIds">
      <div class="field-row">
        <label>Message</label>
        <textarea name="bulk_message" rows="3" required
                  placeholder="Your membership renews next week — settle at the front desk any time."></textarea>
      </div>
      <p class="note">This appears in their notifications straight away.</p>
      <div class="modal-actions">
        <button type="button" class="btn" onclick="closeModal('mNotify')">Cancel</button>
        <button class="btn red"><i class="fa-solid fa-paper-plane"></i> Send</button>
      </div>
    </form>
  </div>
</div>

<script>
/* The bulk bar sits outside the table; JS carries the ticked ids across. */
function runBulk(action) {
  const ids = document.getElementById('bulkIds').value;
  if (!ids) return;
  const form = document.getElementById('bulkForm');
  const n = ids.split(',').length;
  uiConfirm('Apply to ' + n + ' member' + (n === 1 ? '' : 's') + '?',
    'This updates every selected record at once.',
    () => { document.getElementById('bulkAction').value = action; form.submit(); },
    'Apply');
}
function clearPicks() {
  document.querySelectorAll('input.pick').forEach(b => { b.checked = false; });
  window.bulkSync?.();
}
document.getElementById('mNotify')?.addEventListener('submit', () => {
  document.getElementById('notifyIds').value = document.getElementById('bulkIds').value;
}, true);

function newMember() {
  document.getElementById('fMember').reset();
  document.getElementById('mTitle').textContent = 'Add member';
  document.getElementById('fAction').value = 'create';
  document.getElementById('pwRow').style.display = '';
  openModal('mMember');
}
function editMember(m) {
  document.getElementById('mTitle').textContent = 'Edit ' + m.member_name;
  document.getElementById('fAction').value = 'update';
  document.getElementById('fMemberId').value = m.member_id;
  document.getElementById('pwRow').style.display = 'none';
  fillForm('fMember', {
    member_name: m.member_name, email: m.email, contact_number: m.contact_number,
    membership_type: m.membership_type,
    join_date: m.join_date, out_date: m.out_date || '', status: m.status
  });
  openModal('mMember');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
