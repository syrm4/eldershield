<?php
// api/delete_incident.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireLogin();

// Deletion is a destructive action — must arrive via POST.
// A GET request (e.g. someone following a stale link) is rejected cleanly.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Location: ' . APP_URL . '/pages/dashboard.php');
    exit;
}

$user       = currentUser();
$incidentId = (int)($_POST['incident_id'] ?? 0);
$csrfToken  = $_POST['csrf_token']        ?? '';

if (!$incidentId || !verifyCsrf($csrfToken)) {
    setFlash('danger', 'Invalid request.');
    header('Location: ' . APP_URL . '/pages/dashboard.php');
    exit;
}

$success = deleteIncident($incidentId, (int)$user['user_id'], $user['role']);

if ($success) {
    setFlash('success', 'Report deleted successfully.');
} else {
    setFlash('danger', 'Could not delete that report.');
}

$redirect = $user['role'] === 'elder' ? '/pages/my_incidents.php' : '/pages/incidents.php';
header('Location: ' . APP_URL . $redirect);
exit;
