<?php
// ============================================================
// includes/billing_helper.php
// Caregiver billing — flat $9.99/month for premium plan.
// Invoices table: one row per caregiver per billing month.
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/subscription_helper.php';

const PREMIUM_MONTHLY_CENTS = 999; // $9.99

/**
 * Return a billing summary for a caregiver including their active elder count,
 * current plan, and monthly charge amount.
 *
 * @param int $caregiverId The caregiver's user ID.
 * @return array Keys: active_elders (int), monthly_cents (int), monthly_fmt (string), plan (string).
 */
function getCaregiverBillingSummary(int $caregiverId): array {
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM account_links
         WHERE caregiver_user_id = ? AND status = "active"'
    );
    $stmt->execute([$caregiverId]);
    $activeElders = (int)$stmt->fetchColumn();

    $plan     = getUserPlan($caregiverId);
    $monthly  = $plan === 'premium' ? PREMIUM_MONTHLY_CENTS : 0;

    return [
        'active_elders' => $activeElders,
        'monthly_cents' => $monthly,
        'monthly_fmt'   => formatCents($monthly),
        'plan'          => $plan,
    ];
}

/**
 * Check whether a caregiver's account access should be restricted due to a failed invoice.
 * Returns false on database error to avoid incorrectly blocking access.
 *
 * @param int $caregiverId The caregiver's user ID.
 * @return bool True if one or more failed invoices exist for this caregiver.
 */
function caregiverAccessRestricted(int $caregiverId): bool {
    try {
        $stmt = getDB()->prepare(
            'SELECT COUNT(*) FROM invoices WHERE caregiver_id = ? AND status = "failed"'
        );
        $stmt->execute([$caregiverId]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Retrieve the most recent failed invoice for a caregiver.
 *
 * @param int $caregiverId The caregiver's user ID.
 * @return array|null The invoice row, or null if no failed invoice exists.
 */
function getFailedInvoice(int $caregiverId): ?array {
    try {
        $stmt = getDB()->prepare(
            'SELECT * FROM invoices WHERE caregiver_id = ? AND status = "failed"
             ORDER BY billing_month DESC LIMIT 1'
        );
        $stmt->execute([$caregiverId]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Generate pending invoices for all active premium caregivers for a given billing month.
 * Skips caregivers who already have an invoice for that month.
 * Immediately calls simulatePayment() on each new invoice.
 *
 * @param string $billingMonth Billing period in Y-m-01 format (e.g. '2025-03-01').
 * @return array ['created' => int, 'skipped' => int] or ['error' => string] on invalid input.
 */
function generateMonthlyInvoices(string $billingMonth): array {
    $db   = getDB();
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $billingMonth);
    if (!$date || $date->format('d') !== '01') {
        return ['error' => 'billingMonth must be Y-m-01 format'];
    }

    // Only bill premium caregivers
    $caregivers = $db->query(
        'SELECT user_id FROM users
         WHERE role = "caregiver" AND is_active = 1 AND plan = "premium"'
    )->fetchAll();

    $created = 0;
    $skipped = 0;

    foreach ($caregivers as $cg) {
        $caregiverId = (int)$cg['user_id'];

        $stmt = $db->prepare(
            'INSERT INTO invoices (caregiver_id, billing_month, amount_cents, status)
             VALUES (?, ?, ?, "pending")
             ON DUPLICATE KEY UPDATE invoice_id = invoice_id'
        );
        $stmt->execute([$caregiverId, $billingMonth, PREMIUM_MONTHLY_CENTS]);

        if ($stmt->rowCount() === 0) { $skipped++; continue; }

        $invoiceId = (int)$db->lastInsertId();
        $created++;
        simulatePayment($invoiceId, $caregiverId);
    }

    return ['created' => $created, 'skipped' => $skipped];
}

/**
 * Simulate payment processing for a single invoice at a 95% success rate.
 * Updates the invoice status to 'paid' or 'failed' and sends a billing notification.
 * Skips processing if the invoice is already paid.
 *
 * @param int $invoiceId    The ID of the invoice to process.
 * @param int $caregiverId  The ID of the caregiver being billed.
 * @return bool True if payment succeeded, false if it failed or the invoice was not found.
 */
function simulatePayment(int $invoiceId, int $caregiverId): bool {
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT invoice_id, amount_cents, status FROM invoices
         WHERE invoice_id = ? AND caregiver_id = ? LIMIT 1'
    );
    $stmt->execute([$invoiceId, $caregiverId]);
    $invoice = $stmt->fetch();

    if (!$invoice)                          return false;
    if ($invoice['status'] === 'paid')      return true;

    $success = (rand(1, 100) <= 95);

    if ($success) {
        $db->prepare(
            'UPDATE invoices SET status = "paid", paid_at = UTC_TIMESTAMP() WHERE invoice_id = ?'
        )->execute([$invoiceId]);
        sendBillingNotification($caregiverId, $invoiceId, 'billing_success');
    } else {
        $db->prepare('UPDATE invoices SET status = "failed" WHERE invoice_id = ?')
           ->execute([$invoiceId]);
        sendBillingNotification($caregiverId, $invoiceId, 'billing_failed');
    }

    return $success;
}

/**
 * Retry a previously failed invoice payment by calling simulatePayment() again.
 * Returns false if the invoice does not exist or is not in 'failed' status.
 *
 * @param int $invoiceId   The ID of the failed invoice to retry.
 * @param int $caregiverId The ID of the caregiver who owns the invoice.
 * @return bool True if the retry payment succeeded, false otherwise.
 */
function retryPayment(int $invoiceId, int $caregiverId): bool {
    $stmt = getDB()->prepare(
        'SELECT invoice_id FROM invoices
         WHERE invoice_id = ? AND caregiver_id = ? AND status = "failed" LIMIT 1'
    );
    $stmt->execute([$invoiceId, $caregiverId]);
    if (!$stmt->fetch()) return false;
    return simulatePayment($invoiceId, $caregiverId);
}

/**
 * Fetch invoice history for a caregiver, ordered by most recent billing month first.
 *
 * @param int $caregiverId The caregiver's user ID.
 * @param int $limit       Maximum number of invoices to return. Defaults to 24 (two years).
 * @return array Array of invoice rows, or empty array on database error.
 */
function getInvoiceHistory(int $caregiverId, int $limit = 24): array {
    try {
        $stmt = getDB()->prepare(
            'SELECT * FROM invoices WHERE caregiver_id = ?
             ORDER BY billing_month DESC LIMIT ?'
        );
        $stmt->execute([$caregiverId, $limit]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Fetch all invoices across all caregivers with caregiver name and email. Admin use only.
 *
 * @param int $limit Maximum number of invoices to return. Defaults to 100.
 * @return array Array of invoice rows joined with caregiver user data.
 */
function getAllInvoicesAdmin(int $limit = 100): array {
    return getDB()->query(
        'SELECT i.*, u.full_name AS caregiver_name, u.email AS caregiver_email
         FROM invoices i
         JOIN users u ON i.caregiver_id = u.user_id
         ORDER BY i.billing_month DESC
         LIMIT ' . (int)$limit
    )->fetchAll();
}

/**
 * Return a billing overview of all active caregivers with their plan, link count,
 * last billing month, and most recent invoice status. Admin use only.
 *
 * @return array Array of rows with caregiver details and billing summary columns.
 */
function getAdminBillingOverview(): array {
    return getDB()->query(
        'SELECT u.user_id, u.full_name, u.email, u.plan,
                COUNT(DISTINCT al.link_id) AS active_elders,
                MAX(i.billing_month)       AS last_billed,
                (SELECT status FROM invoices i2
                 WHERE i2.caregiver_id = u.user_id
                 ORDER BY i2.billing_month DESC LIMIT 1) AS last_status
         FROM users u
         LEFT JOIN account_links al
               ON al.caregiver_user_id = u.user_id AND al.status = "active"
         LEFT JOIN invoices i ON i.caregiver_id = u.user_id
         WHERE u.role = "caregiver" AND u.is_active = 1
         GROUP BY u.user_id, u.full_name, u.email, u.plan
         ORDER BY u.full_name'
    )->fetchAll();
}

/**
 * Send an in-app billing notification to a caregiver for a payment success or failure event.
 *
 * @param int    $caregiverId The ID of the caregiver to notify.
 * @param int    $invoiceId   The ID of the invoice the notification relates to.
 * @param string $type        Event type: 'billing_success' or 'billing_failed'.
 * @return void
 */
function sendBillingNotification(int $caregiverId, int $invoiceId, string $type): void {
    $db  = getDB();
    $inv = $db->prepare('SELECT * FROM invoices WHERE invoice_id = ?');
    $inv->execute([$invoiceId]);
    $invoice = $inv->fetch();
    if (!$invoice) return;

    $month   = date('F Y', strtotime($invoice['billing_month']));
    $amount  = formatCents((int)$invoice['amount_cents']);
    $messages = [
        'billing_success' => "Your invoice for {$month} ({$amount}) has been paid successfully.",
        'billing_failed'  => "Payment failed for your {$month} invoice ({$amount}). Please check your payment method.",
    ];
    $message = $messages[$type] ?? "Billing update for {$month}.";

    $db->prepare(
        'INSERT INTO notifications (incident_id, recipient_user_id, message_text, notification_type)
         VALUES (NULL, ?, ?, ?)'
    )->execute([$caregiverId, $message, 'admin_action']);
}

/**
 * Format a cent integer as a human-readable dollar string.
 *
 * @param int $cents Amount in cents (e.g. 999).
 * @return string Formatted string (e.g. '$9.99').
 */
function formatCents(int $cents): string {
    return '$' . number_format($cents / 100, 2);
}

/**
 * Format a Y-m-d billing date as a human-readable month and year string.
 *
 * @param string $date A date string in Y-m-d format (e.g. '2025-03-01').
 * @return string Formatted string (e.g. 'March 2025').
 */
function formatBillingMonth(string $date): string {
    return date('F Y', strtotime($date));
}
