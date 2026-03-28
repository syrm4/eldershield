<?php
// ============================================================
// includes/subscription_helper.php
// Plan stored directly on users columns:
//   users.plan          ENUM('free','premium')
//   users.plan_expires  DATETIME nullable
//   users.plan_paused   TINYINT(1)  — admin can pause premium
//
// Elder accounts: always free, unlimited, never touched here.
// Caregiver free:    up to FREE_LINK_LIMIT linked elders
// Caregiver premium: unlimited linked elders ($9.99/mo)
//   — unless plan_paused = 1, treated as free until unpaused
// ============================================================

require_once __DIR__ . '/../config/db.php';

const FREE_LINK_LIMIT = 2;

/**
 * Fetch the raw plan columns from the users table for a given user.
 * Returns safe defaults on database error to prevent access from being incorrectly blocked.
 *
 * @param int $userId The user ID to look up.
 * @return array Keys: plan (string), plan_expires (string|null), plan_paused (int).
 */
function getUserPlanRow(int $userId): array {
    try {
        $stmt = getDB()->prepare(
            'SELECT plan, plan_expires, plan_paused FROM users WHERE user_id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: ['plan' => 'free', 'plan_expires' => null, 'plan_paused' => 0];
    } catch (PDOException $e) {
        error_log('[ElderShield] getUserPlanRow: ' . $e->getMessage());
        return ['plan' => 'free', 'plan_expires' => null, 'plan_paused' => 0];
    }
}

/**
 * Return the effective plan for a user, accounting for paused and expired states.
 * A premium plan that is paused or has passed its expiry date is treated as free.
 *
 * @param int $userId The user ID to check.
 * @return string 'premium' if active and unexpired, otherwise 'free'.
 */
function getUserPlan(int $userId): string {
    $row = getUserPlanRow($userId);
    if ($row['plan'] !== 'premium')    return 'free';
    if ($row['plan_paused'])           return 'free'; // paused = treated as free
    if ($row['plan_expires'] !== null
        && strtotime($row['plan_expires']) < time()) return 'free'; // expired
    return 'premium';
}

/**
 * Return a full subscription summary for a user, suitable for display in the UI.
 * Distinguishes between the raw DB plan value and the effective plan.
 *
 * @param int $userId The user ID to look up.
 * @return array Keys: plan_name, raw_plan, plan_paused, plan_expires, price,
 *               max_links (-1 = unlimited), notifications_enabled, status.
 */
function getUserSubscription(int $userId): array {
    $row  = getUserPlanRow($userId);
    $plan = getUserPlan($userId); // effective plan
    return [
        'plan_name'             => $plan,
        'raw_plan'              => $row['plan'],      // actual DB value
        'plan_paused'           => (bool)$row['plan_paused'],
        'plan_expires'          => $row['plan_expires'],
        'price'                 => $row['plan'] === 'premium' ? 9.99 : 0.00,
        'max_links'             => $plan === 'premium' ? -1 : FREE_LINK_LIMIT,
        'notifications_enabled' => 1,
        'status'                => $row['plan_paused'] ? 'paused' : 'active',
    ];
}

/**
 * Check whether a user is allowed to submit an incident report.
 * Elder accounts are always permitted — no plan restrictions apply.
 *
 * @param int $userId The user ID to check.
 * @return bool Always returns true.
 */
function canSubmitIncident(int $userId): bool { return true; }

/**
 * Count the number of incidents submitted by a user in the current calendar month.
 *
 * @param int $userId The user ID to count for.
 * @return int Number of incidents this month, or 0 on database error.
 */
function getMonthlyIncidentCount(int $userId): int {
    try {
        $stmt = getDB()->prepare(
            "SELECT COUNT(*) FROM incidents
             WHERE user_id = ? AND submitted_at >= DATE_FORMAT(NOW(),'%Y-%m-01')"
        );
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) { return 0; }
}

/**
 * Check whether a caregiver is permitted to link another elder.
 * Premium caregivers have no limit; free caregivers are capped at FREE_LINK_LIMIT.
 *
 * @param int $caregiverId The caregiver's user ID.
 * @return bool True if the caregiver can add another link.
 */
function caregiverCanLink(int $caregiverId): bool {
    if (getUserPlan($caregiverId) === 'premium') return true;
    return caregiverLinkCount($caregiverId) < FREE_LINK_LIMIT;
}

/**
 * Count the number of active elder links for a caregiver.
 *
 * @param int $caregiverId The caregiver's user ID.
 * @return int Number of active links, or 0 on database error.
 */
function caregiverLinkCount(int $caregiverId): int {
    try {
        $stmt = getDB()->prepare(
            'SELECT COUNT(*) FROM account_links
             WHERE caregiver_user_id = ? AND status = "active"'
        );
        $stmt->execute([$caregiverId]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) { return 0; }
}

/**
 * Update a user's plan. Sets plan_expires to 30 days from now for premium,
 * or null for free. Clears any paused state.
 * Used for caregiver self-upgrade and basic admin changes.
 *
 * @param int    $userId   The user ID to update.
 * @param string $planName The new plan: 'free' or 'premium'.
 * @return bool True on success, false if the plan name is invalid or on database error.
 */
function setUserPlan(int $userId, string $planName): bool {
    if (!in_array($planName, ['free','premium'], true)) return false;
    $expires = $planName === 'premium'
        ? date('Y-m-d H:i:s', strtotime('+30 days'))
        : null;
    try {
        return getDB()->prepare(
            'UPDATE users SET plan = ?, plan_expires = ?, plan_paused = 0 WHERE user_id = ?'
        )->execute([$planName, $expires, $userId]);
    } catch (PDOException $e) {
        error_log('[ElderShield] setUserPlan: ' . $e->getMessage());
        return false;
    }
}

/**
 * Admin override to set a user's plan with a custom expiry date.
 * Defaults to 30 days from now for premium if no expiry is provided.
 *
 * @param int         $targetId  The user ID to update.
 * @param string      $planName  The new plan: 'free' or 'premium'.
 * @param string|null $expiresAt Optional expiry datetime in Y-m-d H:i:s format.
 * @return bool True on success, false if the plan name is invalid or on database error.
 */
function adminSetPlan(int $targetId, string $planName, ?string $expiresAt = null): bool {
    if (!in_array($planName, ['free','premium'], true)) return false;
    if ($planName === 'premium' && $expiresAt === null) {
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
    }
    if ($planName === 'free') $expiresAt = null;
    try {
        return getDB()->prepare(
            'UPDATE users SET plan = ?, plan_expires = ?, plan_paused = 0 WHERE user_id = ?'
        )->execute([$planName, $expiresAt, $targetId]);
    } catch (PDOException $e) {
        error_log('[ElderShield] adminSetPlan: ' . $e->getMessage());
        return false;
    }
}

/**
 * Pause or unpause a premium subscription. A paused plan is treated as free
 * by getUserPlan() without changing the stored plan value.
 *
 * @param int  $userId The user ID to update.
 * @param bool $paused True to pause, false to unpause.
 * @return bool True on success, false on database error.
 */
function setPlanPaused(int $userId, bool $paused): bool {
    try {
        return getDB()->prepare(
            'UPDATE users SET plan_paused = ? WHERE user_id = ?'
        )->execute([$paused ? 1 : 0, $userId]);
    } catch (PDOException $e) {
        error_log('[ElderShield] setPlanPaused: ' . $e->getMessage());
        return false;
    }
}

/**
 * Cancel a user's subscription by downgrading them to the free plan.
 *
 * @param int $userId The user ID to downgrade.
 * @return bool True on success, false on database error.
 */
function cancelSubscription(int $userId): bool {
    return setUserPlan($userId, 'free');
}

/**
 * Fetch all caregivers with their subscription details and active link count. Admin use only.
 *
 * @return array Array of caregiver rows with plan, plan_expires, plan_paused, and link_count.
 */
function getAllCaregiverSubscriptions(): array {
    try {
        return getDB()->query(
            'SELECT u.user_id, u.full_name, u.email, u.plan,
                    u.plan_expires, u.plan_paused, u.is_active, u.created_at,
                    (SELECT COUNT(*) FROM account_links al
                     WHERE al.caregiver_user_id = u.user_id AND al.status = "active") AS link_count
             FROM users u
             WHERE u.role = "caregiver"
             ORDER BY u.plan DESC, u.full_name ASC'
        )->fetchAll();
    } catch (PDOException $e) {
        error_log('[ElderShield] getAllCaregiverSubscriptions: ' . $e->getMessage());
        return [];
    }
}
