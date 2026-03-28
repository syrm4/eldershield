<?php
// ============================================================
// includes/helpers.php  —  CRUD helpers
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

// ════════════════════════════════════════════════════════════
// IMAGE UPLOAD
// ════════════════════════════════════════════════════════════

/**
 * Validate and save an uploaded image file to the uploads directory.
 * Checks MIME type, file size, and verifies the file is a real image
 * using getimagesize() to defend against disguised uploads.
 *
 * @param array $file A single entry from the $_FILES superglobal.
 * @return array ['success' => false, 'message' => string] on failure,
 *               ['success' => true, 'path' => string, 'filename' => string] on success.
 */
function handleImageUpload(array $file): array {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'File upload failed.'];
    }
    if (!in_array($file['type'], ALLOWED_IMAGE_TYPES, true)) {
        return ['success' => false, 'message' => 'Only JPG, PNG, GIF, WEBP images are allowed.'];
    }
    if ($file['size'] > UPLOAD_MAX_MB * 1024 * 1024) {
        return ['success' => false, 'message' => 'Image must be under ' . UPLOAD_MAX_MB . 'MB.'];
    }
    if (!@getimagesize($file['tmp_name'])) {
        return ['success' => false, 'message' => 'Uploaded file is not a valid image.'];
    }
    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $destPath = UPLOAD_DIR . $filename;
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        return ['success' => false, 'message' => 'Could not save uploaded file.'];
    }
    return ['success' => true, 'path' => $destPath, 'filename' => $filename];
}

// ════════════════════════════════════════════════════════════
// INCIDENTS
// ════════════════════════════════════════════════════════════

/**
 * Insert a new incident record with status 'pending'.
 *
 * @param int         $userId    The ID of the elder submitting the report.
 * @param string      $content   The text description of the suspicious message.
 * @param string|null $imagePath Optional absolute path to an uploaded screenshot.
 * @return int The auto-incremented incident_id of the new record.
 */
function createIncident(int $userId, string $content, ?string $imagePath = null): int {
    $db = getDB();
    $db->prepare(
        'INSERT INTO incidents (user_id, content, image_path, status) VALUES (?, ?, ?, "pending")'
    )->execute([$userId, $content, $imagePath]);
    return (int)$db->lastInsertId();
}

/**
 * Upsert AI analysis results for an incident and set its status to 'analyzed'.
 * Uses ON DUPLICATE KEY UPDATE so re-running analysis overwrites the previous result.
 *
 * @param int   $incidentId The ID of the incident being analyzed.
 * @param array $result     Structured analysis array from analyzeIncident().
 * @return void
 */
function saveAnalysis(int $incidentId, array $result): void {
    $db = getDB();
    $db->prepare(
        'INSERT INTO analysis
            (incident_id, scam_probability, scam_category, manipulation_tactics,
             explanation_simple, recommended_action)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            scam_probability   = VALUES(scam_probability),
            scam_category      = VALUES(scam_category),
            manipulation_tactics = VALUES(manipulation_tactics),
            explanation_simple = VALUES(explanation_simple),
            recommended_action = VALUES(recommended_action)'
    )->execute([
        $incidentId,
        $result['scam_probability'],
        $result['scam_category'],
        json_encode($result['manipulation_tactics']),
        $result['explanation_simple'],
        $result['recommended_action'],
    ]);
    $db->prepare('UPDATE incidents SET status = "analyzed" WHERE incident_id = ?')
       ->execute([$incidentId]);
}

/**
 * Fetch a single incident by ID, including its analysis data via LEFT JOIN.
 *
 * @param int $incidentId The incident ID to look up.
 * @return array|null The incident row with analysis columns, or null if not found.
 */
function getIncidentById(int $incidentId): ?array {
    $stmt = getDB()->prepare(
        'SELECT i.*, a.scam_probability, a.scam_category, a.manipulation_tactics,
                a.explanation_simple, a.recommended_action
         FROM incidents i
         LEFT JOIN analysis a ON i.incident_id = a.incident_id
         WHERE i.incident_id = ?'
    );
    $stmt->execute([$incidentId]);
    return $stmt->fetch() ?: null;
}

/**
 * Fetch all incidents submitted by a specific user, ordered by most recent first.
 *
 * @param int $userId The user ID to filter by.
 * @param int $limit  Maximum number of records to return. Defaults to 20.
 * @return array Array of incident rows with basic analysis columns.
 */
function getIncidentsByUser(int $userId, int $limit = 20): array {
    $stmt = getDB()->prepare(
        'SELECT i.*, a.scam_probability, a.scam_category
         FROM incidents i
         LEFT JOIN analysis a ON i.incident_id = a.incident_id
         WHERE i.user_id = ?
         ORDER BY i.submitted_at DESC LIMIT ?'
    );
    $stmt->execute([$userId, $limit]);
    return $stmt->fetchAll();
}

/**
 * Fetch all incidents across all users, including submitter info. Intended for admin use.
 *
 * @param int $limit Maximum number of records to return. Defaults to 50.
 * @return array Array of incident rows joined with user and analysis data.
 */
function getAllIncidents(int $limit = 50): array {
    $stmt = getDB()->prepare(
        'SELECT i.*, u.full_name, u.email, a.scam_probability, a.scam_category
         FROM incidents i
         JOIN users u ON i.user_id = u.user_id
         LEFT JOIN analysis a ON i.incident_id = a.incident_id
         ORDER BY i.submitted_at DESC LIMIT ?'
    );
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

/**
 * Fetch incidents for all elders actively linked to a given caregiver.
 *
 * @param int $caregiverId The caregiver's user ID.
 * @param int $limit       Maximum number of records to return. Defaults to 50.
 * @return array Array of incident rows with elder name, email, and analysis data.
 */
function getIncidentsForCaregiver(int $caregiverId, int $limit = 50): array {
    $stmt = getDB()->prepare(
        'SELECT i.*, u.full_name, u.email, a.scam_probability, a.scam_category
         FROM incidents i
         JOIN users u ON i.user_id = u.user_id
         LEFT JOIN analysis a ON i.incident_id = a.incident_id
         JOIN account_links al ON al.elder_user_id = i.user_id
         WHERE al.caregiver_user_id = ? AND al.status = "active"
         ORDER BY i.submitted_at DESC LIMIT ?'
    );
    $stmt->execute([$caregiverId, $limit]);
    return $stmt->fetchAll();
}

/**
 * Update the status field of an incident. Silently ignores invalid status values.
 *
 * @param int    $incidentId The ID of the incident to update.
 * @param string $status     One of: 'pending', 'analyzed', 'reviewed', 'dismissed'.
 * @return void
 */
function updateIncidentStatus(int $incidentId, string $status): void {
    $allowed = ['pending','analyzed','reviewed','dismissed'];
    if (!in_array($status, $allowed, true)) return;
    getDB()->prepare('UPDATE incidents SET status = ? WHERE incident_id = ?')
           ->execute([$status, $incidentId]);
}

/**
 * Delete an incident. Admins can delete any incident; other roles can only delete their own.
 * Cascading foreign keys will also remove the associated analysis and notifications.
 *
 * @param int    $incidentId The ID of the incident to delete.
 * @param int    $userId     The ID of the user requesting the deletion.
 * @param string $role       The role of the requesting user ('admin' or other).
 * @return bool True if a row was deleted, false if no matching incident was found.
 */
function deleteIncident(int $incidentId, int $userId, string $role): bool {
    $db = getDB();
    if ($role === 'admin') {
        $stmt = $db->prepare('DELETE FROM incidents WHERE incident_id = ?');
        $stmt->execute([$incidentId]);
    } else {
        $stmt = $db->prepare('DELETE FROM incidents WHERE incident_id = ? AND user_id = ?');
        $stmt->execute([$incidentId, $userId]);
    }
    return $stmt->rowCount() > 0;
}

// ════════════════════════════════════════════════════════════
// NOTIFICATIONS
// ════════════════════════════════════════════════════════════

/**
 * Insert a new in-app notification for a specific user.
 *
 * @param int         $recipientId The user ID of the notification recipient.
 * @param string      $message     The notification message text.
 * @param string      $type        Notification type: 'high_risk', 'medium_risk', 'info', or 'admin_action'.
 * @param int|null    $incidentId  Optional incident ID to link the notification to.
 * @return void
 */
function createNotification(int $recipientId, string $message, string $type = 'info', ?int $incidentId = null): void {
    getDB()->prepare(
        'INSERT INTO notifications (incident_id, recipient_user_id, message_text, notification_type)
         VALUES (?, ?, ?, ?)'
    )->execute([$incidentId, $recipientId, $message, $type]);
}

/**
 * Send risk alert notifications to all caregivers linked to an elder and all active admins.
 * Only called when scam probability meets or exceeds the RISK_MEDIUM threshold.
 *
 * @param int    $incidentId  The ID of the analyzed incident.
 * @param int    $elderUserId The ID of the elder who submitted the report.
 * @param int    $probability The scam probability score (0–100).
 * @param string $category    The detected scam category string.
 * @return void
 */
function notifyCaregivers(int $incidentId, int $elderUserId, int $probability, string $category): void {
    $db = getDB();

    $stmt = $db->prepare(
        'SELECT al.caregiver_user_id FROM account_links al
         WHERE al.elder_user_id = ? AND al.status = "active"'
    );
    $stmt->execute([$elderUserId]);
    $caregivers = $stmt->fetchAll();

    $nameStmt = $db->prepare('SELECT full_name FROM users WHERE user_id = ?');
    $nameStmt->execute([$elderUserId]);
    $elderName = $nameStmt->fetchColumn() ?: 'An elder user';

    $level   = $probability >= RISK_HIGH ? 'HIGH' : 'MEDIUM';
    $cat     = ucwords(str_replace('_', ' ', $category));
    $message = "{$elderName} submitted a {$level} RISK report ({$probability}%). Category: {$cat}. Please review.";
    $type    = $probability >= RISK_HIGH ? 'high_risk' : 'medium_risk';

    foreach ($caregivers as $cg) {
        createNotification((int)$cg['caregiver_user_id'], $message, $type, $incidentId);
    }

    // Notify admins too
    $admins = $db->query('SELECT user_id FROM users WHERE role = "admin" AND is_active = 1')->fetchAll();
    foreach ($admins as $admin) {
        createNotification((int)$admin['user_id'], $message, $type, $incidentId);
    }
}

/**
 * Fetch the 50 most recent notifications for a user, including linked incident and elder name.
 *
 * @param int $userId The recipient user ID.
 * @return array Array of notification rows with incident_content and elder_name columns.
 */
function getNotificationsForUser(int $userId): array {
    $stmt = getDB()->prepare(
        'SELECT n.*, i.content AS incident_content, u.full_name AS elder_name
         FROM notifications n
         LEFT JOIN incidents i ON n.incident_id = i.incident_id
         LEFT JOIN users u     ON i.user_id = u.user_id
         WHERE n.recipient_user_id = ?
         ORDER BY n.created_at DESC LIMIT 50'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Mark a specific notification as read. Scoped to the recipient to prevent cross-user access.
 *
 * @param int $notificationId The ID of the notification to mark as read.
 * @param int $userId         The ID of the user who owns the notification.
 * @return void
 */
function markNotificationRead(int $notificationId, int $userId): void {
    getDB()->prepare(
        'UPDATE notifications SET is_read = 1
         WHERE notification_id = ? AND recipient_user_id = ?'
    )->execute([$notificationId, $userId]);
}

/**
 * Count the number of unread notifications for a user.
 *
 * @param int $userId The user ID to count for.
 * @return int Number of unread notifications.
 */
function countUnreadNotifications(int $userId): int {
    $stmt = getDB()->prepare(
        'SELECT COUNT(*) FROM notifications WHERE recipient_user_id = ? AND is_read = 0'
    );
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

// ════════════════════════════════════════════════════════════
// ACCOUNT LINKS
// ════════════════════════════════════════════════════════════

/**
 * Create a pending caregiver-to-elder link request.
 * Returns an error if the relationship already exists (duplicate key).
 *
 * @param int    $elderUserId      The ID of the elder being linked.
 * @param int    $caregiverUserId  The ID of the caregiver requesting the link.
 * @param string $relationshipType Reserved for future use. Currently unused in the query.
 * @return array ['success' => true] on success,
 *               ['success' => false, 'message' => string] if the link already exists.
 */
function linkCaregiverToElder(int $elderUserId, int $caregiverUserId, string $relationshipType = 'caregiver'): array {
    try {
        getDB()->prepare(
            'INSERT INTO account_links (elder_user_id, caregiver_user_id, status) VALUES (?, ?, "pending")'
        )->execute([$elderUserId, $caregiverUserId]);
        return ['success' => true];
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return ['success' => false, 'message' => 'This relationship already exists.'];
        }
        return ['success' => false, 'message' => 'Failed to create link.'];
    }
}

/**
 * Approve a pending caregiver-elder link, setting its status to 'active'.
 *
 * @param int $linkId The ID of the account_links record to approve.
 * @return void
 */
function approveLink(int $linkId): void {
    getDB()->prepare('UPDATE account_links SET status = "active" WHERE link_id = ?')
           ->execute([$linkId]);
}

/**
 * Hard-delete a caregiver-elder link. Uses hard delete rather than soft delete
 * so the pair can be re-linked in the future without hitting the UNIQUE KEY constraint.
 *
 * @param int $linkId The ID of the account_links record to remove.
 * @return void
 */
function revokeLink(int $linkId): void {
    getDB()->prepare('DELETE FROM account_links WHERE link_id = ?')
           ->execute([$linkId]);
}

/**
 * Fetch all caregiver links for a given elder, including caregiver name and email.
 *
 * @param int $elderUserId The elder's user ID.
 * @return array Array of account_links rows joined with caregiver user data.
 */
function getLinksForElder(int $elderUserId): array {
    $stmt = getDB()->prepare(
        'SELECT al.*, u.full_name, u.email FROM account_links al
         JOIN users u ON al.caregiver_user_id = u.user_id
         WHERE al.elder_user_id = ?'
    );
    $stmt->execute([$elderUserId]);
    return $stmt->fetchAll();
}

/**
 * Fetch all active elder links for a given caregiver, including elder name and email.
 *
 * @param int $caregiverId The caregiver's user ID.
 * @return array Array of active account_links rows joined with elder user data.
 */
function getLinksForCaregiver(int $caregiverId): array {
    $stmt = getDB()->prepare(
        'SELECT al.*, u.full_name, u.email FROM account_links al
         JOIN users u ON al.elder_user_id = u.user_id
         WHERE al.caregiver_user_id = ? AND al.status = "active"'
    );
    $stmt->execute([$caregiverId]);
    return $stmt->fetchAll();
}

// ════════════════════════════════════════════════════════════
// UTILITY
// ════════════════════════════════════════════════════════════

/**
 * Escape a string for safe HTML output. Shorthand for htmlspecialchars with ENT_QUOTES.
 *
 * @param string $val The raw string to escape.
 * @return string HTML-safe string.
 */
function e(string $val): string {
    return htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
}

/**
 * Render a styled HTML risk badge for a given scam probability score.
 *
 * @param float $probability Scam probability from 0–100.
 * @return string HTML span element with the appropriate badge class and percentage.
 */
function riskBadge(float $probability): string {
    $level = $probability >= RISK_HIGH ? 'high' : ($probability >= RISK_MEDIUM ? 'medium' : 'low');
    $map   = [
        'high'   => ['High Risk',   'badge-danger'],
        'medium' => ['Medium Risk', 'badge-warning'],
        'low'    => ['Low Risk',    'badge-success'],
    ];
    [$label, $class] = $map[$level];
    return "<span class=\"badge {$class}\">{$label} (" . round($probability) . "%)</span>";
}

/**
 * Convert a stored datetime string to a human-readable relative time string.
 * Returns a formatted date for timestamps older than 30 days.
 *
 * @param string $datetime A datetime string in any format accepted by PHP's DateTime.
 * @return string Relative string such as '5m ago', '3h ago', '2d ago', or 'Jan 5, 2025'.
 */
function timeAgo(string $datetime): string {
    $tz   = new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : date_default_timezone_get());
    $now  = new DateTime('now', $tz);
    $then = new DateTime($datetime, $tz);
    // If stored without timezone info, treat it as the app timezone
    $diff = $now->diff($then);

    if ($diff->days > 30)  return $then->format('M j, Y');
    if ($diff->days >= 1)  return $diff->days . 'd ago';
    if ($diff->h >= 1)     return $diff->h    . 'h ago';
    if ($diff->i >= 1)     return $diff->i    . 'm ago';
    return 'just now';
}

/**
 * Format a snake_case scam category string into a human-readable title case label.
 *
 * @param string $category A category string such as 'romance_scam' or 'tech_support'.
 * @return string Title-cased label such as 'Romance Scam' or 'Tech Support'.
 */
function formatCategory(string $category): string {
    return ucwords(str_replace('_', ' ', $category));
}
