<?php
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
$u = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = (string) ($_POST['action'] ?? '');

    if ($act === 'create') {
        $name = trim((string) ($_POST['visitor_name'] ?? ''));
        $contact = trim((string) ($_POST['contact_number'] ?? ''));
        $memberId = trim((string) ($_POST['member_id'] ?? ''));
        $date = (string) ($_POST['visit_date'] ?? date('Y-m-d'));
        $rate = (string) ($_POST['pass_rate'] ?? '');
        $amount = match ($rate) {
            'regular' => DAY_PASS_PRICE,
            'student' => STUDENT_DAY_PASS_PRICE,
            default => null,
        };
        $existingMember = $memberId !== ''
            ? row(
                "SELECT m.member_id, m.member_name, m.contact_number, m.contact_number_encrypted,
                        m.membership_type, m.status,
                        (SELECT p.payment_status FROM payments p
                          WHERE p.member_id = m.member_id
                          ORDER BY p.created_at DESC, p.payment_id DESC LIMIT 1) latest_payment_status
                   FROM members m WHERE m.member_id = ?",
                [$memberId]
            )
            : null;
        $memberIdentityValid = $memberId === ''
            || ($existingMember !== null
                && hash_equals(trim((string) $existingMember['member_name']), $name)
                && hash_equals(trim(member_contact($existingMember)), $contact));
        $linkedMemberId = $memberIdentityValid && $existingMember ? $existingMember['member_id'] : null;
        $eligibleMonthlyMember = $existingMember !== null
            && $existingMember['membership_type'] === 'Monthly'
            && $existingMember['status'] === 'Active'
            && $existingMember['latest_payment_status'] === 'Paid';
        if (!$eligibleMonthlyMember) {
            foreach (rows(
                "SELECT m.member_id, m.membership_type, m.status, m.contact_number, m.contact_number_encrypted,
                        (SELECT p.payment_status FROM payments p
                          WHERE p.member_id = m.member_id
                          ORDER BY p.created_at DESC, p.payment_id DESC LIMIT 1) latest_payment_status
                   FROM members m WHERE m.member_name = ?",
                [$name]
            ) as $candidate) {
                if (
                    $candidate['membership_type'] === 'Monthly'
                    && $candidate['status'] === 'Active'
                    && $candidate['latest_payment_status'] === 'Paid'
                    && hash_equals(trim(member_contact($candidate)), $contact)
                ) {
                    $eligibleMonthlyMember = true;
                    break;
                }
            }
        }

        if (!$memberIdentityValid) {
            flash('The selected member name or contact number does not match their account.', 'err');
        } elseif ($eligibleMonthlyMember) {
            flash('This member already has an active monthly membership and cannot buy a day pass.', 'err');
        } elseif ($name === '' || $contact === '' || $date < date('Y-m-d') || $amount === null) {
            flash('Enter a valid visitor, contact number, date and amount.', 'err');
        } else {
            $passId = next_id('day_passes', 'pass_id', 'DAY', 5);
            db()->prepare(
                'INSERT INTO day_passes (pass_id,member_id,visitor_name,contact_number,visit_date,amount,payment_method,pass_status,notes)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([$passId, $linkedMemberId, $name, $contact, $date, $amount, $_POST['payment_method'], $_POST['pass_status'], trim((string) ($_POST['notes'] ?? ''))]);
            log_activity('Create day pass', 'Day Passes', $passId . ' - ' . $name);
            flash('Day pass ' . $passId . ' created. Its QR code is ready to share.');
        }
    }

    if ($act === 'cancel') {
        db()->prepare("UPDATE day_passes SET pass_status='Cancelled' WHERE pass_id=? AND visit_date >= CURDATE()")
            ->execute([(string) $_POST['pass_id']]);
        log_activity('Cancel day pass', 'Day Passes', (string) $_POST['pass_id']);
        flash('Day pass cancelled.');
    }

    redirect('day-passes.php');
}

$page = 'day-passes';
$title = 'Day Passes';
$subtitle = 'One-day access for walk-in customers without a membership.';

$passes = rows(
    'SELECT d.*, a.check_in_time, a.check_out_time
       FROM day_passes d
       LEFT JOIN attendance a ON a.day_pass_id = d.pass_id
      WHERE d.visit_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
      ORDER BY d.visit_date DESC, d.created_at DESC'
);

include __DIR__ . '/../includes/header.php';
?>

<div class="panel glass">
  <h2><i class="fa-solid fa-ticket"></i> One-day access
    <span class="spacer"></span>
    <button class="btn red sm" onclick="openModal('mDayPass')"><i class="fa-solid fa-plus"></i> New day pass</button>
  </h2>
  <p class="note">After payment, open or download the QR below and share it with the visitor. Linked members can also use the day-pass QR displayed on their member card for the visit date.</p>
</div>

<div class="panel glass">
  <h2><i class="fa-solid fa-clock-rotate-left"></i> Recent passes</h2>
  <?php if (!$passes): ?>
    <div class="empty"><i class="fa-solid fa-ticket"></i>No day passes yet.</div>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Pass</th><th>Customer</th><th>Visit date</th><th>Amount</th><th>Payment</th><th>Access</th><th>QR code</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($passes as $pass): ?>
      <tr>
        <td><b><?= e($pass['pass_id']) ?></b><div class="mono">Kiosk: 9<?= e(substr($pass['pass_id'], 3)) ?></div></td>
        <td><?= e($pass['visitor_name']) ?><div class="mono"><?= e($pass['contact_number']) ?></div></td>
        <td><?= dt($pass['visit_date']) ?></td>
        <td><?= money($pass['amount']) ?></td>
        <td><?= e($pass['payment_method']) ?><div><?= badge($pass['pass_status']) ?></div></td>
        <td>
          <?php if ($pass['check_in_time']): ?>
            <span class="chip ok">checked in</span>
            <?php if ($pass['check_out_time']): ?><div class="note">completed</div><?php endif; ?>
          <?php else: ?><span class="chip mute">not used</span><?php endif; ?>
        </td>
        <td>
          <?php if ($pass['pass_status'] === 'Paid' && $pass['visit_date'] >= date('Y-m-d')): ?>
            <a href="../api/qr.php?type=day_pass&amp;id=<?= rawurlencode((string) $pass['pass_id']) ?>" target="_blank" rel="noopener" title="Open day-pass QR at full size">
              <img src="../api/qr.php?type=day_pass&amp;id=<?= rawurlencode((string) $pass['pass_id']) ?>" alt="QR code for <?= e($pass['pass_id']) ?>" width="88" height="88">
              <span class="note">Open full size</span>
            </a>
            <a class="note" href="../api/qr.php?type=day_pass&amp;id=<?= rawurlencode((string) $pass['pass_id']) ?>" download="<?= e($pass['pass_id']) ?>.svg">Download QR</a>
          <?php else: ?><span class="note">Available after payment</span><?php endif; ?>
        </td>
        <td>
          <?php if ($pass['pass_status'] !== 'Cancelled' && $pass['visit_date'] >= date('Y-m-d')): ?>
            <form method="post" onsubmit="return confirm('Cancel this day pass?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="cancel">
              <input type="hidden" name="pass_id" value="<?= e($pass['pass_id']) ?>">
              <button class="btn sm danger" title="Cancel"><i class="fa-solid fa-ban"></i></button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="modal" id="mDayPass">
  <div class="modal-bg"></div>
  <div class="modal-box">
    <h3><i class="fa-solid fa-ticket"></i> New day pass</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="member_id" id="dayPassMemberId" value="">
      <div class="field-row">
        <label for="dayPassName">Customer name</label>
        <input type="text" name="visitor_name" id="dayPassName" required autofocus autocomplete="off" aria-autocomplete="list" aria-controls="dayPassMemberSuggestions">
        <div id="dayPassMemberSuggestions" class="day-pass-member-suggestions" role="listbox" aria-label="Matching members" style="display:none"></div>
        <div id="dayPassMemberStatus" class="note" aria-live="polite"></div>
      </div>
      <div class="field-row"><label for="dayPassContact">Contact number</label><input type="tel" name="contact_number" id="dayPassContact" required></div>
      <div class="form-grid">
        <div class="field-row"><label>Visit date</label><input type="date" name="visit_date" value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>" required></div>
        <div class="field-row"><label>Rate</label>
          <select id="dayPassRate" name="pass_rate" onchange="suggestDayPassAmount()">
            <option value="regular">Regular — <?= money(DAY_PASS_PRICE) ?></option>
            <option value="student">Student — <?= money(STUDENT_DAY_PASS_PRICE) ?></option>
          </select>
        </div>
      </div>
      <div class="field-row"><label>Amount</label><input type="number" id="dayPassAmount" value="<?= DAY_PASS_PRICE ?>" readonly></div>
      <div class="form-grid">
        <div class="field-row"><label>Payment method</label><select name="payment_method"><option>Cash</option><option>Card</option><option>GCash</option><option>Bank Transfer</option><option>Maya</option></select></div>
        <div class="field-row"><label>Payment status</label><select name="pass_status"><option>Paid</option><option>Pending</option></select></div>
      </div>
      <div class="field-row"><label>Notes</label><input type="text" name="notes" placeholder="Walk-in customer"></div>
      <p class="note">The pass appears as <b>DAY00001</b>. At the numeric kiosk, enter <b>900001</b>.</p>
      <div class="modal-actions">
        <button type="button" class="btn" onclick="closeModal('mDayPass')">Cancel</button>
        <button type="submit" class="btn red"><i class="fa-solid fa-check"></i> Create pass</button>
      </div>
    </form>
  </div>
</div>

<script>
const dayPassName = document.getElementById('dayPassName');
const dayPassContact = document.getElementById('dayPassContact');
const dayPassMemberId = document.getElementById('dayPassMemberId');
const dayPassSuggestions = document.getElementById('dayPassMemberSuggestions');
const dayPassMemberStatus = document.getElementById('dayPassMemberStatus');
const dayPassSubmit = document.querySelector('#mDayPass button[type="submit"]');
let dayPassSearchTimer;
let dayPassSearchSequence = 0;

dayPassName.addEventListener('input', () => {
  clearTimeout(dayPassSearchTimer);
  dayPassSearchSequence++;
  dayPassSuggestions.replaceChildren();
  dayPassSuggestions.style.display = 'none';
  dayPassContact.value = '';
  dayPassMemberId.value = '';
  dayPassSubmit.disabled = false;
  dayPassSubmit.removeAttribute('title');

  const query = dayPassName.value.trim();
  if (query.length < 2) {
    dayPassMemberStatus.textContent = '';
    return;
  }

  dayPassMemberStatus.textContent = 'Searching members…';
  const sequence = dayPassSearchSequence;
  dayPassSearchTimer = setTimeout(async () => {
    try {
      const response = await fetch('../api/day-pass-members.php?q=' + encodeURIComponent(query), {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      });
      if (!response.ok) throw new Error('Member search failed.');
      const data = await response.json();
      if (sequence !== dayPassSearchSequence) return;

      dayPassSuggestions.replaceChildren();
      for (const member of data.members) {
        const option = document.createElement('button');
        option.type = 'button';
        option.setAttribute('role', 'option');
        option.setAttribute('aria-selected', 'false');
        option.className = 'day-pass-member-option';
        option.textContent = `${member.name}${member.contact ? ` · ${member.contact}` : ''}${member.active_monthly ? ' · Active monthly membership' : ''}`;
        option.addEventListener('click', () => {
          dayPassName.value = member.name;
          dayPassContact.value = member.contact;
          dayPassMemberId.value = member.id;
          dayPassSuggestions.replaceChildren();
          dayPassSuggestions.style.display = 'none';
          if (member.active_monthly) {
            dayPassMemberStatus.textContent = 'This member already has an active monthly membership and cannot buy a day pass.';
            dayPassSubmit.disabled = true;
            dayPassSubmit.title = 'Active monthly members cannot buy a day pass.';
          } else {
            dayPassMemberStatus.textContent = member.contact ? 'Member selected; contact number filled in.' : 'Member selected; enter their contact number.';
            dayPassSubmit.disabled = false;
            dayPassSubmit.removeAttribute('title');
          }
        });
        dayPassSuggestions.append(option);
      }

      dayPassSuggestions.style.display = data.members.length ? 'block' : 'none';
      dayPassMemberStatus.textContent = data.members.length ? 'Select a member to fill in their contact number.' : 'No matching members found.';
    } catch (error) {
      if (sequence !== dayPassSearchSequence) return;
      dayPassMemberStatus.textContent = 'Member search is unavailable. You can enter the visitor details manually.';
    }
  }, 250);
});

function suggestDayPassAmount() {
  const isStudent = document.getElementById('dayPassRate').value === 'student';
  document.getElementById('dayPassAmount').value = isStudent
    ? <?= STUDENT_DAY_PASS_PRICE ?>
    : <?= DAY_PASS_PRICE ?>;
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
