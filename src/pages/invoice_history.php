<?php
// pages/invoice_history.php — Caregiver invoice history
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/billing_helper.php';

requireLogin();
requireRole('caregiver');

$user   = currentUser();
$errors = [];

// ── Handle retry payment ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        $errors[] = 'Invalid request. Please try again.';
    } else {
        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        if ($invoiceId) {
            $ok = retryPayment($invoiceId, (int)$user['user_id']);
            setFlash($ok ? 'success' : 'danger',
                     $ok ? 'Payment successful!' : 'Payment failed again. Please try later.');
            header('Location: ' . APP_URL . '/pages/invoice_history.php');
            exit;
        }
    }
}

$invoices   = getInvoiceHistory((int)$user['user_id']);
$failedInv  = getFailedInvoice((int)$user['user_id']);
$restricted = caregiverAccessRestricted((int)$user['user_id']);

// Single invoice detail view
$detail = null;
if (isset($_GET['invoice_id'])) {
    $invId  = (int)$_GET['invoice_id'];
    // Fetch invoice scoped to this caregiver only (prevents IDOR)
    $db     = getDB();
    $stmt   = $db->prepare('SELECT * FROM invoices WHERE invoice_id = ? AND caregiver_id = ? LIMIT 1');
    $stmt->execute([$invId, $user['user_id']]);
    $detail = $stmt->fetch() ?: null;
}

$pageTitle = 'Invoice History';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-container">
    <div class="page-header">
        <h1>Invoice History</h1>
        <a href="<?= APP_URL ?>/pages/billing.php" class="btn btn-outline btn-sm">&larr; Billing Summary</a>
    </div>

    <?php foreach ($errors as $err): ?>
        <div class="alert alert-danger"><?= e($err) ?></div>
    <?php endforeach; ?>

    <?php if ($restricted && $failedInv): ?>
    <div class="alert alert-danger">
        <strong>⚠️ Payment Failed</strong> —
        Your <?= e(formatBillingMonth($failedInv['billing_month'])) ?> invoice of
        <strong><?= e(formatCents((int)$failedInv['amount_cents'])) ?></strong> is unpaid.
        <form method="POST" style="display:inline; margin-left:.75rem;">
            <?= csrfField() ?>
            <input type="hidden" name="invoice_id" value="<?= (int)$failedInv['invoice_id'] ?>">
            <button type="submit" class="btn btn-sm btn-danger">Retry Payment</button>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($detail): ?>
    <!-- ── Single invoice view ─────────────────────────────── -->
    <div class="card">
        <div class="page-header" style="margin-bottom:1rem;">
            <h2>Invoice — <?= e(formatBillingMonth($detail['billing_month'])) ?></h2>
            <span class="status-badge status-<?= e($detail['status']) ?>">
                <?= e(ucfirst($detail['status'])) ?>
            </span>
        </div>

        <table class="data-table" style="margin-bottom:1.5rem;">
            <thead><tr><th>Description</th><th>Amount</th></tr></thead>
            <tbody>
                <tr>
                    <td>ElderShield Premium — <?= e(formatBillingMonth($detail['billing_month'])) ?></td>
                    <td><?= e(formatCents((int)$detail['amount_cents'])) ?></td>
                </tr>
            </tbody>
            <tfoot>
                <tr>
                    <td style="font-weight:700; text-align:right;">Total</td>
                    <td style="font-weight:700;"><?= e(formatCents((int)$detail['amount_cents'])) ?></td>
                </tr>
            </tfoot>
        </table>

        <div style="color:var(--color-muted); font-size:.9rem;">
            <?php if ($detail['paid_at']): ?>
                Paid on <?= e(date('M j, Y \a\t g:i A', strtotime($detail['paid_at']))) ?>
            <?php elseif ($detail['status'] === 'failed'): ?>
                Payment failed.
                <form method="POST" style="display:inline; margin-left:.5rem;">
                    <?= csrfField() ?>
                    <input type="hidden" name="invoice_id" value="<?= (int)$detail['invoice_id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger">Retry Payment</button>
                </form>
            <?php else: ?>
                Payment pending.
            <?php endif; ?>
        </div>
    </div>
    <a href="<?= APP_URL ?>/pages/invoice_history.php" class="btn btn-outline btn-sm"
       style="margin-bottom:1.5rem;">&larr; All Invoices</a>
    <?php endif; ?>

    <!-- ── Invoice list ────────────────────────────────────── -->
    <?php if (empty($invoices)): ?>
    <div class="empty-state">
        <h2>No Invoices Yet</h2>
        <p>Your first invoice will be generated on the 1st of next month once your Premium plan is active.</p>
    </div>
    <?php else: ?>
    <div class="card">
        <h2>All Invoices (<?= count($invoices) ?>)</h2>
        <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Month</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Paid On</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($invoices as $inv): ?>
                <tr class="<?= $inv['status'] === 'failed' ? 'row-danger' : '' ?>">
                    <td><?= e(formatBillingMonth($inv['billing_month'])) ?></td>
                    <td><?= e(formatCents((int)$inv['amount_cents'])) ?></td>
                    <td>
                        <span class="status-badge status-<?= e($inv['status']) ?>">
                            <?= e(ucfirst($inv['status'])) ?>
                        </span>
                    </td>
                    <td><?= $inv['paid_at'] ? e(date('M j, Y', strtotime($inv['paid_at']))) : '—' ?></td>
                    <td>
                        <a href="?invoice_id=<?= (int)$inv['invoice_id'] ?>"
                           class="btn btn-sm btn-outline">View</a>
                        <?php if ($inv['status'] === 'failed'): ?>
                            <form method="POST" style="display:inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="invoice_id" value="<?= (int)$inv['invoice_id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Retry</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
