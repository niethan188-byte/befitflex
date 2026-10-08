<?php
declare(strict_types=1);

define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
$u = require_role('admin');

$page = 'attendance';
$title = 'QR Attendance';
$subtitle = 'Scan a member QR code to record check-in.';

$inside = rows(
    'SELECT a.attendance_id, a.check_in_time, m.member_id, m.member_name
     FROM attendance a JOIN members m ON m.member_id = a.member_id
     WHERE a.check_out_time IS NULL AND a.attendance_date = CURDATE()
     ORDER BY a.check_in_time DESC'
);
$todayCount = (int) scalar('SELECT COUNT(*) FROM attendance WHERE attendance_date = CURDATE()');
$hasLogo = is_file(__DIR__ . '/../assets/img/logo.png');
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#121212">
<title><?= e($title) ?> · <?= APP_NAME ?></title>
<link rel="stylesheet" href="../assets/css/glass.css">
<link rel="stylesheet" href="../assets/css/ui.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body data-base="">
<div class="field"><div class="blob a"></div><div class="blob b"></div><div class="blob c"></div></div>

<div class="kiosk-wrap">
  <div class="kiosk-card glass">
    <div class="auth-logo" style="margin-bottom:14px">
      <?php if ($hasLogo): ?><img src="../assets/img/logo.png" alt="">
      <?php else: ?><svg viewBox="0 0 100 100" fill="none" style="width:64px;height:64px"><path d="M50 4 92 28v48L50 100 8 76V28z" stroke="#E53935" stroke-width="6"/><path d="M34 30h26M34 50h22M34 70h26" stroke="#E53935" stroke-width="7" stroke-linecap="round"/></svg><?php endif; ?>
    </div>

    <h1>Scan member QR</h1>
    <p class="note">Position a member's QR inside the frame. The scan is validated and recorded on the server.</p>

    <div id="cameraWrap" style="position:relative;width:100%;aspect-ratio:4/3;overflow:hidden;border-radius:16px;background:#080808;border:1px solid var(--stroke)">
      <video id="camera" autoplay playsinline muted style="width:100%;height:100%;object-fit:cover"></video>
      <div id="cameraFrame" style="position:absolute;inset:20%;border:2px solid rgba(229,57,53,.8);box-shadow:0 0 0 9999px rgba(0,0,0,.72);border-radius:8px"></div>
      <div id="cameraStatus" style="position:absolute;left:12px;right:12px;bottom:12px;padding:9px 12px;border-radius:10px;background:rgba(0,0,0,.76);font-size:12px;text-align:center">Camera is ready to start.</div>
    </div>

    <div class="field-row" style="margin-top:18px;text-align:left">
      <label for="scanCode">Or enter the QR value</label>
      <input id="scanCode" type="text" autocomplete="off" autocapitalize="characters" placeholder="BEFITFLEX:MEM0001" autofocus>
    </div>

    <div id="result"></div>

    <div style="margin-top:26px;padding-top:18px;border-top:1px solid var(--stroke)">
      <div class="note"><b id="todayN"><?= $todayCount ?></b> check-ins today · <b id="insideN"><?= count($inside) ?></b> on the floor now</div>
      <div class="kiosk-inside" id="insideList">
        <?php foreach ($inside as $p): ?>
          <span class="who"><?= e($p['member_name']) ?> · <?= date('g:i A', strtotime($p['check_in_time'])) ?></span>
        <?php endforeach; ?>
      </div>
    </div>

    <div style="margin-top:22px;display:flex;gap:10px;flex-wrap:wrap">
      <button id="startCamera" class="btn red"><i class="fa-solid fa-camera"></i> Start camera</button>
      <button id="scanManual" class="btn ghost"><i class="fa-solid fa-keyboard"></i> Scan entered code</button>
      <a class="btn ghost" href="attendance.php"><i class="fa-solid fa-arrow-left"></i> Attendance</a>
    </div>
  </div>
</div>

<script>
let detector = null;
let cameraStream = null;
let scanTimer = null;
let scanning = false;
const video = document.getElementById('camera');
const status = document.getElementById('cameraStatus');
const scanField = document.getElementById('scanCode');
const result = document.getElementById('result');
const startButton = document.getElementById('startCamera');

function showResult(kind, icon, big, sub) {
  result.innerHTML = '<div class="kiosk-result ' + kind + '">' +
    '<i class="fa-solid fa-' + icon + '"></i>' +
    '<div class="big"></div><div class="note"></div></div>';
  result.querySelector('.big').textContent = big;
  result.querySelector('.note').textContent = sub;
}

function setStatus(message) {
  status.textContent = message;
}

async function startCamera() {
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    setStatus('Camera access is not supported in this browser. Use the code field below.');
    return;
  }

  try {
    cameraStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
    video.srcObject = cameraStream;
    await video.play();
    detector = 'BarcodeDetector' in window ? new window.BarcodeDetector({ formats: ['qr_code'] }) : null;
    scanning = true;
    startButton.innerHTML = '<i class="fa-solid fa-stop"></i> Stop camera';
    setStatus(detector ? 'Camera active. Hold the QR inside the frame.' : 'Camera active. Browser QR decoding is unavailable; use the code field.');
    scanLoop();
  } catch (error) {
    setStatus('Camera permission was denied. Enter the QR value manually.');
  }
}

function stopCamera() {
  scanning = false;
  clearTimeout(scanTimer);
  if (cameraStream) {
    cameraStream.getTracks().forEach(track => track.stop());
    cameraStream = null;
  }
  video.srcObject = null;
  detector = null;
  startButton.innerHTML = '<i class="fa-solid fa-camera"></i> Start camera';
  setStatus('Camera stopped. Enter the QR value manually.');
}

async function scanLoop() {
  if (!scanning) return;
  try {
    if (detector && video.readyState >= 2) {
      const decoded = await detector.detect(video);
      if (decoded.length) {
        const code = decoded[0].rawValue.trim();
        await submit(code);
        return;
      }
    }
  } catch (error) {
    setStatus('The camera could not read the QR. Try again.');
  }
  scanTimer = setTimeout(scanLoop, 350);
}

startButton.addEventListener('click', () => cameraStream ? stopCamera() : startCamera());

async function submit(rawCode) {
  const code = rawCode.trim().toUpperCase().replace(/^BEFITFLEX:/, '');
  if (!/^MEM\d{4,}$/.test(code)) {
    showResult('err', 'circle-exclamation', 'QR not recognized', 'Show a valid member QR code.');
    return;
  }

  showResult('in', 'circle-check', 'Reading QR…', code);
  try {
    const response = await fetch('../api/check-in.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ member_id: code, scan_only: true })
    });
    const data = await response.json();

    if (!response.ok || !data.ok) {
      throw new Error(data.error || 'Unable to process the QR code.');
    }

    if (data.action === 'check_in') {
      showResult('in', 'circle-check', 'Welcome, ' + data.member, 'Checked in at ' + data.at + '.');
    } else {
      showResult('out', 'circle-info', 'Already checked in', data.member + ' is already recorded for this session.');
    }
    scanField.value = '';
    setTimeout(() => { location.reload(); }, 3600);
  } catch (error) {
    showResult('err', 'wifi', 'Scan failed', error.message || 'The attendance service is unavailable.');
  }
}

document.getElementById('scanManual').addEventListener('click', () => submit(scanField.value));
scanField.addEventListener('keydown', event => {
  if (event.key === 'Enter') {
    event.preventDefault();
    submit(scanField.value);
  }
});

window.addEventListener('beforeunload', stopCamera);
</script>
</body>
</html>
