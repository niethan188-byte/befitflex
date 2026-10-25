<?php
require __DIR__ . '/includes/auth.php';

header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');

$user = current_user();
$member = null;
if ($user && $user['type'] === 'member') {
    $member = row(
        "SELECT m.member_id, m.member_name, m.status, m.membership_type,
                (SELECT p.payment_status FROM payments p
                  WHERE p.member_id = m.member_id
                  ORDER BY p.created_at DESC, p.payment_id DESC LIMIT 1) latest_payment_status
           FROM members m WHERE m.user_id = ?",
        [$user['id']]
    );
}
$activeMonthlyMember = $member
    && $member['membership_type'] === 'Monthly'
    && $member['status'] === 'Active'
    && $member['latest_payment_status'] === 'Paid';
$mayaBaseUrl = null;

$pass = null;
$passId = trim((string) ($_GET['pass_id'] ?? ''));
$passToken = (string) ($_GET['token'] ?? '');
if ($passId !== '' && preg_match('/^DAY\d{5,}$/', $passId)
    && preg_match('/^[a-f0-9]{64}$/', $passToken)) {
    $candidate = row(
        'SELECT pass_id, visitor_name, visit_date, amount, payment_method, pass_status, public_token
           FROM day_passes WHERE pass_id = ?',
        [$passId]
    );
    if ($candidate && is_string($candidate['public_token'])
        && hash_equals($candidate['public_token'], $passToken)) {
        $pass = $candidate;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $hasAccount = (string) ($_POST['has_account'] ?? '') === '1';
    $accountMember = null;
    if (!$member && $hasAccount) {
        $accountEmail = trim((string) ($_POST['account_email'] ?? ''));
        if ($accountEmail === '' || mb_strlen($accountEmail) > 255
            || filter_var($accountEmail, FILTER_VALIDATE_EMAIL) === false) {
            flash('Enter a valid email address for your BeFitFlex member account.', 'err');
            redirect('day-pass.php');
        }
        $accountMember = row(
            "SELECT m.member_id, m.member_name, m.membership_type, m.status,
                    (SELECT p.payment_status FROM payments p
                      WHERE p.member_id = m.member_id
                      ORDER BY p.created_at DESC, p.payment_id DESC LIMIT 1) latest_payment_status
               FROM users u
               JOIN members m ON m.user_id = u.user_id
              WHERE u.email = ? AND u.user_type = 'member'",
            [$accountEmail]
        );
        if (!$accountMember) {
            flash('We could not verify a BeFitFlex member account with that email. Check the address and try again.', 'err');
            redirect('day-pass.php');
        }
    }
    $name = $member
        ? trim((string) $member['member_name'])
        : ($accountMember
            ? trim((string) $accountMember['member_name'])
            : trim((string) ($_POST['visitor_name'] ?? '')));
    $visitDate = date('Y-m-d');
    $rate = (string) ($_POST['pass_rate'] ?? '');
    $method = (string) ($_POST['payment_method'] ?? '');
    $amount = match ($rate) {
        'regular' => DAY_PASS_PRICE,
        'student' => STUDENT_DAY_PASS_PRICE,
        default => null,
    };
    if ($name === '' || mb_strlen($name) > 255 || $amount === null
        || !in_array($method, ['Cash', 'Maya'], true)) {
        flash('Enter your name if you are a new visitor, choose a pass rate and a supported payment method.', 'err');
        redirect('day-pass.php');
    }
    $verifiedActiveMonthlyMember = $accountMember
        && $accountMember['membership_type'] === 'Monthly'
        && $accountMember['status'] === 'Active'
        && $accountMember['latest_payment_status'] === 'Paid';
    if ($activeMonthlyMember || $verifiedActiveMonthlyMember) {
        flash('Your active paid monthly membership already includes gym access. You do not need a day pass.', 'err');
        redirect($member ? 'member/id-card.php' : 'day-pass.php');
    }
    if ($user && $user['type'] === 'member' && !$member) {
        flash('Your member record could not be found. Please contact the gym before purchasing.', 'err');
        redirect('day-pass.php');
    }
    if ($method === 'Maya' && !maya_payment_flow_configured()) {
        flash('Maya checkout is unavailable. Please choose cash and pay at the front desk, or try again later.', 'err');
        redirect('day-pass.php');
    }
    if ($method === 'Maya') {
        $configuredBase = getenv('BEFITFLEX_APP_URL');
        $baseParts = is_string($configuredBase) ? parse_url(trim($configuredBase)) : false;
        if (!is_string($configuredBase) || filter_var(trim($configuredBase), FILTER_VALIDATE_URL) === false
            || !is_array($baseParts) || strtolower((string) ($baseParts['scheme'] ?? '')) !== 'https'
            || empty($baseParts['host']) || isset($baseParts['user']) || isset($baseParts['pass'])
            || isset($baseParts['query']) || isset($baseParts['fragment'])) {
            flash('Maya day-pass checkout requires a valid HTTPS BEFITFLEX_APP_URL setting. Please choose cash or contact the gym.', 'err');
            redirect('day-pass.php');
        }
        $mayaBaseUrl = rtrim(trim($configuredBase), '/');
    }

    $passId = next_id('day_passes', 'pass_id', 'DAY', 5);
    $passToken = bin2hex(random_bytes(32));
    $memberId = $member['member_id'] ?? $accountMember['member_id'] ?? null;
    db()->prepare(
        'INSERT INTO day_passes
            (pass_id, member_id, public_token, visitor_name, contact_number, visit_date, amount,
             payment_method, pass_status, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, "Pending", ?)'
    )->execute([
        $passId, $memberId, $passToken, $name, '', $visitDate, $amount, $method,
        'Self-service day pass',
    ]);

    if ($method === 'Maya') {
        $returnUrl = $mayaBaseUrl . '/api/day-pass-maya-return.php?pass_id='
            . rawurlencode($passId) . '&token=' . rawurlencode($passToken);
        $checkout = maya_create_checkout([
            'payment_id' => $passId,
            'visitor_name' => $name,
            'amount' => $amount,
            'notes' => 'Day pass — ' . $visitDate,
        ], $returnUrl, $returnUrl . '&result=failed');
        $redirectUrl = $checkout['data']['redirectUrl'] ?? $checkout['data']['checkoutUrl'] ?? null;
        $checkoutId = $checkout['data']['checkoutId'] ?? null;
        if (!$checkout['ok'] || !is_string($redirectUrl)
            || filter_var($redirectUrl, FILTER_VALIDATE_URL) === false
            || strtolower((string) parse_url($redirectUrl, PHP_URL_SCHEME)) !== 'https'
            || !is_string($checkoutId) || trim($checkoutId) === '') {
            db()->prepare("UPDATE day_passes SET pass_status = 'Cancelled' WHERE pass_id = ? AND pass_status = 'Pending'")
                ->execute([$passId]);
            $error = $checkout['error'] ?? 'Maya did not return a valid checkout reference.';
            flash($error, 'err');
            redirect('day-pass.php');
        }

        $stmt = db()->prepare(
            "UPDATE day_passes SET maya_checkout_id = ?
              WHERE pass_id = ? AND payment_method = 'Maya' AND pass_status = 'Pending'
                AND (maya_checkout_id IS NULL OR maya_checkout_id = '')"
        );
        $stmt->execute([trim($checkoutId), $passId]);
        if ($stmt->rowCount() !== 1) {
            db()->prepare("UPDATE day_passes SET pass_status = 'Cancelled' WHERE pass_id = ? AND pass_status = 'Pending'")
                ->execute([$passId]);
            flash('The Maya checkout could not be safely linked to this pass. It was not opened; contact the gym if you were charged.', 'err');
            redirect('day-pass.php');
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    redirect('day-pass.php?pass_id=' . rawurlencode($passId) . '&token=' . rawurlencode($passToken));
}

$flashes = take_flash();
$hasLogo = is_file(__DIR__ . '/assets/img/logo.png');
$mayaAvailable = maya_payment_flow_configured();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#121212">
<meta name="referrer" content="no-referrer">
<title>Day Pass · <?= e(APP_NAME) ?></title>
<?php if ($hasLogo): ?><link rel="icon" href="assets/img/logo.png"><?php endif; ?>
<link rel="stylesheet" href="assets/css/glass.css">
<link rel="stylesheet" href="assets/css/login.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="auth-page day-pass-page">
<div class="field"><div class="blob a"></div><div class="blob b"></div><div class="blob c"></div></div>

<?php if ($flashes): ?>
<div class="flash-stack">
  <?php foreach ($flashes as $flash): ?>
    <div class="flash <?= $flash['type'] === 'err' ? 'err' : 'ok' ?>">
      <i class="fa-solid <?= $flash['type'] === 'err' ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
      <span><?= e($flash['msg']) ?></span>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="auth-wrap">
  <main class="auth-card glass">
    <div class="auth-logo">
      <?php if ($hasLogo): ?><img src="assets/img/logo.png" alt="<?= e(APP_NAME) ?>">
      <?php else: ?><i class="fa-solid fa-ticket" aria-hidden="true"></i><?php endif; ?>
    </div>
    <h1>DAY PASS</h1>
    <p class="tag"><?= e(APP_NAME) ?> · <?= e(APP_TAGLINE) ?></p>

    <?php if ($pass): ?>
      <section class="day-pass-proof">
      <h2>
        <?php if ($pass['pass_status'] === 'Paid' && $pass['visit_date'] < date('Y-m-d')): ?>This pass has expired
        <?php elseif ($pass['pass_status'] === 'Cancelled'): ?>This pass was cancelled
        <?php elseif ($pass['pass_status'] === 'Paid'): ?>Your pass is ready
        <?php else: ?>Pass payment pending<?php endif; ?>
      </h2>
      <p class="day-pass-proof-details"><?= e($pass['visitor_name']) ?> · <?= dt($pass['visit_date']) ?> · <?= money($pass['amount']) ?></p>
      <div class="day-pass-reference">
        <span>Pass reference</span>
        <strong><?= e($pass['pass_id']) ?></strong>
        <span class="chip <?= $pass['pass_status'] === 'Paid' ? 'ok' : ($pass['pass_status'] === 'Cancelled' ? 'bad' : 'warn') ?>"><?= e($pass['pass_status']) ?></span>
      </div>
      <p class="note">Show this pass page or its reference to the front desk. The admin can look up this record by its pass ID.</p>
      <?php if ($pass['payment_method'] === 'Cash' && $pass['pass_status'] === 'Pending'): ?>
        <p class="note">Show this at the front desk; staff will activate the QR only after receiving your cash payment.</p>
      <?php endif; ?>
      <?php if ($pass['pass_status'] === 'Paid' && $pass['visit_date'] >= date('Y-m-d')): ?>
        <img src="api/qr.php?type=day_pass&amp;id=<?= rawurlencode($pass['pass_id']) ?>&amp;token=<?= rawurlencode($passToken) ?>"
             alt="Your day-pass check-in QR code" width="180" height="180">
        <p class="note">Show this QR at the gym entrance on your visit date.</p>
      <?php elseif ($pass['payment_method'] === 'Cash' && $pass['pass_status'] === 'Pending'): ?>
        <p class="note">Your QR stays inactive until staff confirm receipt of payment. Reload this page afterward.</p>
      <?php elseif ($pass['pass_status'] === 'Cancelled'): ?>
        <p class="note">This pass is inactive. Contact the gym if you believe it was cancelled by mistake.</p>
      <?php elseif ($pass['pass_status'] === 'Paid'): ?>
        <p class="note">This pass is paid but its visit date has passed.</p>
      <?php else: ?>
        <p class="note">Maya has not confirmed this payment yet. Reload in a moment. Your pass will only be activated after verified payment.</p>
      <?php endif; ?>
      <a class="btn red full" href="day-pass.php">Purchase another day pass</a>
      </section>
    <?php elseif ($activeMonthlyMember): ?>
      <h2>Your membership includes access</h2>
      <p>Your active, paid monthly membership is already valid for gym visits. No day pass is needed.</p>
      <a class="btn red full" href="member/id-card.php">Open my member card</a>
    <?php else: ?>
      <p>Choose a pass rate and payment method. New visitors enter their name; existing members can use their account email to link the pass. This pass is valid only today.</p>
      <form method="post" autocomplete="on" id="dayPassPurchaseForm">
        <?= csrf_field() ?>
        <div class="field-row">
          <label class="day-pass-account-option">
            <input type="checkbox" id="hasAccount" name="has_account" value="1"
              <?= $member ? 'checked disabled' : '' ?>>
            I already have a BeFitFlex account
          </label>
          <p class="note" id="accountNote" aria-live="polite" role="status">
            <?php if ($member): ?>
              Your signed-in account will be linked to this pass.
            <?php else: ?>
              New visitors can continue without an account. If you have an account, enter its email to link this pass.
            <?php endif; ?>
          </p>
        </div>
        <?php if (!$member): ?>
        <div class="field-row day-pass-account-verification" id="accountVerification" hidden>
          <label for="accountEmail">BeFitFlex account email</label>
          <input type="email" id="accountEmail" name="account_email" maxlength="255" autocomplete="email" autocapitalize="none" spellcheck="false">
          <p class="note" id="accountVerificationStatus" aria-live="polite" role="status"></p>
        </div>
        <?php endif; ?>
        <div class="field-row day-pass-name-field" id="visitorNameField">
          <label for="visitorName">Your name</label>
          <input type="text" id="visitorName" name="visitor_name" maxlength="255" required autocomplete="name"
            value="<?= e((string) ($member['member_name'] ?? '')) ?>" <?= $member ? 'readonly' : '' ?>>
        </div>
        <div class="field-row">
          <label for="passRate">Pass rate</label>
          <select id="passRate" name="pass_rate" required>
            <option value="regular">Regular — <?= money(DAY_PASS_PRICE) ?></option>
            <option value="student">Student — <?= money(STUDENT_DAY_PASS_PRICE) ?></option>
          </select>
        </div>
        <div class="field-row">
          <label for="paymentMethod">Payment method</label>
          <select id="paymentMethod" name="payment_method" required>
            <option value="Cash">Cash at the front desk</option>
            <option value="Maya" <?= $mayaAvailable ? '' : 'disabled' ?>>Maya<?= $mayaAvailable ? '' : ' (currently unavailable)' ?></option>
          </select>
        </div>
        <button class="btn red full" type="submit"><i class="fa-solid fa-arrow-right"></i> Continue to payment</button>
      </form>
      <?php if ($user && $user['type'] === 'member' && !$member): ?>
        <p class="note">Your member record could not be found. Please contact the gym before purchasing.</p>
      <?php endif; ?>
    <?php endif; ?>
  </main>
</div>
<?php if (!$member && !$pass && !$activeMonthlyMember): ?>
<script>
const accountCheckbox = document.getElementById('hasAccount');
const accountNote = document.getElementById('accountNote');
const purchaseButton = document.querySelector('form button[type="submit"]');
const accountVerification = document.getElementById('accountVerification');
const accountEmail = document.getElementById('accountEmail');
const accountVerificationStatus = document.getElementById('accountVerificationStatus');
const visitorNameField = document.getElementById('visitorNameField');
const visitorName = document.getElementById('visitorName');
let verifiedAccountEmail = '';
let accountVerificationTimer;
let accountVerificationSequence = 0;

function updateAccountVerification() {
  const needsVerification = accountCheckbox.checked;
  accountVerification.hidden = !needsVerification;
  visitorNameField.hidden = needsVerification;
  accountEmail.required = needsVerification;
  visitorName.required = !needsVerification;
  purchaseButton.disabled = needsVerification && verifiedAccountEmail !== accountEmail.value.trim().toLowerCase();
  if (!needsVerification) {
    clearTimeout(accountVerificationTimer);
    accountVerificationSequence++;
    verifiedAccountEmail = '';
    accountVerificationStatus.textContent = '';
    accountVerificationStatus.classList.remove('error', 'success');
    accountNote.textContent = 'New visitors can continue without an account.';
  } else {
    accountNote.textContent = 'Enter the email associated with your BeFitFlex member account. We will check it automatically.';
    checkAccountEmail();
  }
}

accountCheckbox.addEventListener('change', updateAccountVerification);
accountEmail.addEventListener('input', () => {
  verifiedAccountEmail = '';
  purchaseButton.disabled = accountCheckbox.checked;
  clearTimeout(accountVerificationTimer);
  accountVerificationSequence++;
  accountVerificationStatus.classList.remove('error', 'success');
  if (accountCheckbox.checked) accountVerificationTimer = setTimeout(checkAccountEmail, 350);
});

async function checkAccountEmail() {
  const email = accountEmail.value.trim();
  const sequence = accountVerificationSequence;
  if (!accountCheckbox.checked) return;
  if (!email) {
    accountVerificationStatus.textContent = 'Enter your account email to check if it exists.';
    return;
  }
  if (!accountEmail.validity.valid) {
    accountVerificationStatus.textContent = 'Enter a valid email address.';
    accountVerificationStatus.classList.add('error');
    return;
  }

  try {
    const requestedEmail = email.toLowerCase();
    const formData = new FormData(document.getElementById('dayPassPurchaseForm'));
    formData.set('email', email);
    accountVerificationStatus.textContent = 'Checking for your account…';
    const response = await fetch('api/day-pass-verify-account.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      body: formData
    });
    const result = await response.json();
    if (!response.ok || !result.ok) throw new Error(result.error || 'Account verification failed. Please try again.');
    if (sequence !== accountVerificationSequence
      || accountEmail.value.trim().toLowerCase() !== requestedEmail) return;
    if (!result.exists) {
      accountVerificationStatus.textContent = 'No BeFitFlex member account was found for this email. Check it and try again.';
      accountVerificationStatus.classList.add('error');
      return;
    }

    verifiedAccountEmail = requestedEmail;
    accountVerificationStatus.textContent = 'Account found. You can continue to payment.';
    accountVerificationStatus.classList.add('success');
    purchaseButton.disabled = false;
  } catch (error) {
    if (sequence !== accountVerificationSequence) return;
    accountVerificationStatus.textContent = error instanceof Error
      ? error.message
      : 'Account verification failed. Please try again.';
    accountVerificationStatus.classList.add('error');
  }
}

document.getElementById('dayPassPurchaseForm').addEventListener('submit', event => {
  if (accountCheckbox.checked
    && verifiedAccountEmail !== accountEmail.value.trim().toLowerCase()) {
    event.preventDefault();
    checkAccountEmail();
  }
});

updateAccountVerification();
</script>
<?php endif; ?>
</body>
</html>
