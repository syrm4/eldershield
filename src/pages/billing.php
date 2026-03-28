<?php
// ============================================================
// pages/billing.php — Billing summary for caregivers / free notice for elders
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/billing_helper.php';

requireLogin();
$user   = currentUser();
$errors = [];

// ── Handle retry payment POST ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user['role'] === 'caregiver') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        $errors[] = 'Invalid request. Please try again.';
    } else {
        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        if ($invoiceId) {
            // retryPayment() verifies ownership internally — safe against IDOR
            $ok = retryPayment($invoiceId, (int)$user['user_id']);
            setFlash($ok ? 'success' : 'danger',
                     $ok ? 'Payment successful! Your account is now active.'
                         : 'Payment failed again. Please check your payment details.');
            header('Location: ' . APP_URL . '/pages/billing.php');
            exit;
        }
    }
}

// ── Load data ─────────────────────────────────────────────────
$summary    = [];
$failedInv  = null;
$restricted = false;
$links      = [];

if ($user['role'] === 'caregiver') {
    $summary    = getCaregiverBillingSummary((int)$user['user_id']);
    $failedInv  = getFailedInvoice((int)$user['user_id']);
    $restricted = caregiverAccessRestricted((int)$user['user_id']);
    $links      = getLinksForCaregiver((int)$user['user_id']);
}

$pageTitle = 'Billing';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-container">
    <div class="page-header">
        <h1>Billing</h1>
    </div>

    <?php foreach ($errors as $err): ?>
        <div class="alert alert-danger"><?= e($err) ?></div>
    <?php endforeach; ?>

    <?php if ($user['role'] === 'elder' || $user['role'] === 'admin'): ?>
    <!-- ── ELDER / ADMIN: free notice ──────────────────────────── -->
    <div class="card">
        <p style="font-size:1.1rem;">
            <?= $user['role'] === 'elder'
                ? '🎉 Your elder account is <strong>free forever</strong>. No charges will ever apply to your account.'
                : '🔒 Admin accounts are not billed.' ?>
        </p>
    </div>

    <?php else: ?>
    <!-- ── CAREGIVER BILLING ───────────────────────────────────── -->

    <?php if ($restricted && $failedInv): ?>
    <!-- Payment failure banner -->
    <div class="alert alert-danger billing-alert">
        <strong>⚠️ Payment Failed — Access Restricted</strong><br>
        Your <?= e(formatBillingMonth($failedInv['billing_month'])) ?> invoice of
        <strong><?= e(formatCents((int)$failedInv['amount_cents'])) ?></strong> could not be processed.
        Please retry below to restore full access.
        <form method="POST" style="margin-top:.75rem;">
            <?= csrfField() ?>
            <input type="hidden" name="invoice_id" value="<?= (int)$failedInv['invoice_id'] ?>">
            <button type="submit" class="btn btn-danger">Retry Payment</button>
        </form>
    </div>
    <?php endif; ?>

    <!-- Current billing summary -->
    <div class="card">
        <h2>Current Month</h2>
        <div class="billing-summary">
            <div class="billing-stat">
                <span class="billing-stat-number"><?= (int)$summary['active_elders'] ?></span>
                <span class="billing-stat-label">Linked Elder<?= $summary['active_elders'] !== 1 ? 's' : '' ?></span>
            </div>
            <div class="billing-stat">
                <span class="billing-stat-number"><?= e($summary['monthly_fmt']) ?></span>
                <span class="billing-stat-label">Monthly Charge</span>
            </div>
            <div class="billing-stat">
                <span class="billing-stat-number">$0.99</span>
                <span class="billing-stat-label">Per Elder / Month</span>
            </div>
        </div>
        <p style="color:var(--color-muted); font-size:.9rem; margin-top:1rem;">
            Billing runs automatically on the 1st of each month based on your active linked elders at that time.
            Prorated charges apply when links are added or removed mid-month.
        </p>
    </div>

    <!-- Linked elders -->
    <?php if (!empty($links)): ?>
    <div class="card">
        <h2>Your Linked Elders</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Elder</th>
                    <th>Linked Since</th>
                    <th>Monthly Charge</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($links as $link): ?>
                <tr>
                    <td>
                        <?= e($link['full_name']) ?><br>
                        <small style="color:var(--color-muted)"><?= e($link['email']) ?></small>
                    </td>
                    <td><?= date("M j, Y", strtotime($link["created_at"])) ?></td>
                    <td><?= formatCents(PREMIUM_MONTHLY_CENTS) ?>/mo</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state">
        <h2>No Linked Elders</h2>
        <p>You have no active linked elder accounts. You will not be charged until you link an elder.</p>
        <a href="<?= APP_URL ?>/pages/admin_users.php" class="btn btn-primary">Link an Elder</a>
    </div>
    <?php endif; ?>

    <div style="margin-top:1rem;">
        <a href="<?= APP_URL ?>/pages/invoice_history.php" class="btn btn-outline">
            📄 View Invoice History
        </a>
    </div>

    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
