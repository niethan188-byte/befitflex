<?php
/**
 * Front-desk check-in station.
 *
 * Meant for a tablet at the entrance: large keypad, no chrome, one action.
 * Typing a member ID toggles them in or out, so staff never choose between
 * two buttons under pressure. Admin sign-in is required to open it.
 */
require __DIR__ . '/includes/auth.php';
$u = require_role('admin');

$inside = rows(
    'SELECT a.attendance_id, a.check_in_time, m.member_id, m.member_name
       FROM attendance a JOIN members m ON m.member_id = a.member_id
      WHERE a.check_out_time IS NULL AND a.attendance_date = CURDATE()
      ORDER BY a.check_in_time DESC'
);
$todayCount = (int) scalar('SELECT COUNT(*) FROM attendance WHERE attendance_date = CURDATE()');
$hasLogo = is_file(__DIR__ . '/assets/img/logo.png');
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#121212">
<title>Check-in · <?= APP_NAME ?></title>
<link rel="stylesheet" href="assets/css/glass.css">
<link rel="stylesheet" href="assets/css/ui.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body data-base="">
<div class="field"><div class="blob a"></div><div class="blob b"></div><div class="blob c"></div></div>

<div class="kiosk-wrap">
  <div class="kiosk-card glass">
    <div class="auth-logo" style="margin-bottom:14px">
      <?php if ($hasLogo): ?><img src="assets/img/logo.png" alt="" style="width:64px;height:64px">
      <?php else: ?><svg viewBox="0 0 100 100" fill="none" style="width:64px;height:64px"><path d="M50 4 92 28v48L50 100 8 76V28z" stroke="#E53935" stroke-width="6"/><path d="M34 30h26M34 50h22M34 70h26" stroke="#E53935" stroke-width="7" stroke-linecap="round"/></svg><?php endif; ?>
    </div>

    <h1>Check in</h1>
    <p class="note">Scan the QR on a member's phone or day pass. Numeric entry is also available.</p>

    <div class="field-row" style="text-align:left">
      <label for="scanCode">Scan member or day-pass QR</label>
      <input id="scanCode" type="text" autocomplete="off" autocapitalize="characters" placeholder="Ready for QR scanner" autofocus>
    </div>

    <div class="kiosk-display empty-state" id="disp" aria-live="polite">MEM____</div>

    <div class="keypad">
      <?php foreach ([1,2,3,4,5,6,7,8,9] as $n): ?>
        <button type="button" onclick="tap('<?= $n ?>')"><?= $n ?></button>
      <?php endforeach; ?>
      <button type="button" onclick="back()" aria-label="Delete"><i class="fa-solid fa-delete-left"></i></button>
      <button type="button" onclick="tap('0')">0</button>
      <button type="button" class="go" onclick="submit()" aria-label="Confirm"><i class="fa-solid fa-arrow-right"></i></button>
    </div>

    <div id="result"></div>

    <div style="margin-top:26px;padding-top:18px;border-top:1px solid var(--stroke)">
      <div class="note"><b id="todayN"><?= $todayCount ?></b> check-ins today ·
        <b id="insideN"><?= count($inside) ?></b> on the floor now</div>
      <div class="kiosk-inside" id="insideList">
        <?php foreach ($inside as $p): ?>
          <span class="who"><?= e($p['member_name']) ?> · <?= date('g:i A', strtotime($p['check_in_time'])) ?></span>
        <?php endforeach; ?>
      </div>
    </div>

    <p style="margin-top:22px">
      <a class="btn ghost" href="admin/dashboard.php">
        <i class="fa-solid fa-arrow-left"></i> Leave kiosk mode</a>
    </p>
  </div>
</div>

<script>
let buf = '';
const disp = document.getElementById('disp');
const res  = document.getElementById('result');
const scanField = document.getElementById('scanCode');

function paint() {
  if (!buf) { disp.textContent = 'MEM____ / DAY______'; disp.classList.add('empty-state'); return; }
  disp.classList.remove('empty-state');
  disp.textContent = buf.startsWith('9') && buf.length > 4
    ? 'DAY' + buf.slice(1).padEnd(5, '_')
    : 'MEM' + buf.padEnd(4, '_');
}
function tap(d) {
  const max = buf.startsWith('9') ? 6 : 4;
  if (buf.length < max) { buf += d; paint(); }
}
function back() { buf = buf.slice(0, -1); paint(); }

/* Keyboard-wedge scanners send the full QR payload and finish with Enter. */
let scan = '', scanAt = 0;
document.addEventListener('keydown', (e) => {
  const now = Date.now();
  if (now - scanAt > 120) scan = '';
  scanAt = now;
  if (e.key === 'Enter') {
    e.preventDefault();
    const scanned = scanField.value.trim() || scan;
    scanField.value = '';
    scan = '';
    if (/^(?:BEFITFLEX:)?(?:MEM\d{4,}|DAY\d{5,}|\d{4,})$/i.test(scanned)) {
      buf = '';
      paint();
      submit(scanned);
    } else {
      submit();
    }
  } else if (e.key === 'Backspace') {
    scan = scan.slice(0, -1);
    if (e.target !== scanField) back();
  } else if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
    scan += e.key;
    if (e.target !== scanField && /^[0-9]$/.test(e.key)) tap(e.key);
  }
});

function parseScannedCode(raw) {
  const code = raw.trim().toUpperCase().replace(/^BEFITFLEX:/, '');
  if (/^MEM\d{4,}$/.test(code)) return { id: code, payload: { member_id: code } };
  if (/^DAY\d{5,}$/.test(code)) return { id: code, payload: { pass_id: code } };
  if (/^9\d{5}$/.test(code)) return { id: 'DAY' + code.slice(1), payload: { pass_id: 'DAY' + code.slice(1) } };
  if (/^\d{4,}$/.test(code)) {
    const id = 'MEM' + code.padStart(4, '0');
    return { id, payload: { member_id: id } };
  }
  return null;
}

async function submit(scannedCode = '') {
  if (!scannedCode && buf.length < 1) return;
  const scanned = scannedCode !== '';
  const isDayPass = !scanned && buf.startsWith('9') && buf.length === 6;
  const parsed = scanned
    ? parseScannedCode(scannedCode)
    : {
        id: isDayPass ? 'DAY' + buf.slice(1).padStart(5, '0') : 'MEM' + buf.padStart(4, '0'),
        payload: isDayPass
          ? { pass_id: 'DAY' + buf.slice(1).padStart(5, '0') }
          : { member_id: 'MEM' + buf.padStart(4, '0') }
      };
  if (!parsed) {
    show('err', 'qrcode', 'QR not recognized', 'Please show a valid member or day-pass QR to the scanner.');
    buf = ''; paint();
    setTimeout(() => { res.innerHTML = ''; location.reload(); }, 4200);
    return;
  }
  const id = parsed.id;
  res.innerHTML = '';

  try {
    const r = await fetch('api/check-in.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ ...parsed.payload, ...(scanned ? { scan_only: true } : {}) })
    });
    const d = await r.json();

    if (d.ok && d.action === 'check_in') {
      show('in', 'circle-check', 'Welcome, ' + d.member, 'Checked in at ' + d.at + '. Have a good session.');
    } else if (d.ok && d.action === 'already_checked_in') {
      show('out', 'circle-info', 'Already checked in: ' + d.member, 'This QR has already been checked in for this session.');
    } else if (d.ok) {
      const h = Math.floor(d.minutes / 60), m = d.minutes % 60;
      show('out', 'hand-peace', 'See you next time, ' + d.member,
           'Trained for ' + (h ? h + 'h ' : '') + m + ' minutes.');
    } else {
      const msg = {
        member_not_found: 'No member with that number. Please see the front desk.',
        day_pass_not_found: 'No day pass with that code. Please see the front desk.',
        day_pass_wrong_date: 'That day pass is not valid today.',
        day_pass_unpaid: 'That day pass has not been paid yet.',
        membership_expired: 'That membership has expired. Please see the front desk.',
        membership_inactive: 'That membership is not active. Please see the front desk.'
      }[d.error] || 'Something went wrong. Please see the front desk.';
      show('err', 'circle-exclamation', id, msg);
    }
  } catch {
    show('err', 'wifi', 'No connection', 'The station cannot reach the server.');
  }

  buf = ''; paint();
  setTimeout(() => { res.innerHTML = ''; location.reload(); }, 4200);
}

function show(kind, icon, big, sub) {
  res.innerHTML = '<div class="kiosk-result ' + kind + '">' +
    '<i class="fa-solid fa-' + icon + '"></i>' +
    '<div class="big"></div><div class="note"></div></div>';
  res.querySelector('.big').textContent = big;
  res.querySelector('.note').textContent = sub;
}

paint();
</script>
</body>
</html>
