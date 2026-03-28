<?php
// pages/admin_users.php — User management + caregiver linking + billing overview
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/subscription_helper.php';
require_once __DIR__ . '/../includes/billing_helper.php';

requireLogin();
$user = currentUser();
$db   = getDB();

// Caregivers can only link/manage relationships; admins can do everything
if (!in_array($user['role'], ['admin','caregiver'])) {
    header('Location: ' . APP_URL . '/pages/dashboard.php');
    exit;
}

$errors  = [];
$success = [];

// ── Handle POST actions ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        $errors[] = 'Invalid form token.';
    } else {
        $action = $_POST['action'] ?? '';

        // Admin: toggle user active status
        if ($action === 'toggle_active' && $user['role'] === 'admin') {
            $uid = (int)($_POST['target_user_id'] ?? 0);
            if ($uid && $uid !== (int)$user['user_id']) {
                $db->prepare('UPDATE users SET is_active = NOT is_active WHERE user_id=?')->execute([$uid]);
                $success[] = 'User status updated.';
            }
        }

        // Admin: change user role
        if ($action === 'change_role' && $user['role'] === 'admin') {
            $uid     = (int)($_POST['target_user_id'] ?? 0);
            $newRole = $_POST['new_role'] ?? '';
            if ($uid && in_array($newRole, ['elder','caregiver','admin'])) {
                $db->prepare('UPDATE users SET role=? WHERE user_id=?')->execute([$newRole, $uid]);
                $success[] = 'Role updated.';
            }
        }

        // Caregiver/Admin: link caregiver to elder
        if ($action === 'link') {
            $elderEmail  = trim($_POST['elder_email'] ?? '');
            $caregiverId = ($user['role'] === 'admin')
                             ? (int)($_POST['caregiver_id'] ?? $user['user_id'])
                             : (int)$user['user_id'];

            // Enforce subscription link limit for caregivers (admins bypass)
            if ($user['role'] === 'caregiver' && !caregiverCanLink($caregiverId)) {
                $errors[] = 'You have reached your free plan limit of ' . FREE_LINK_LIMIT . ' linked elders. '
                          . '<a href="' . APP_URL . '/pages/subscription.php">Upgrade to Premium</a> for unlimited links.';
            } else {
                $elderStmt = $db->prepare('SELECT user_id FROM users WHERE email=? AND role="elder" AND is_active=1');
                $elderStmt->execute([$elderEmail]);
                $elder = $elderStmt->fetch();

                if (!$elder) {
                    $errors[] = 'No active elder account found with that email.';
                } else {
                    $result = linkCaregiverToElder((int)$elder['user_id'], $caregiverId);
                    if ($result['success']) {
                        $success[] = 'Link request sent. The elder must approve it.';
                    } else {
                        $errors[] = $result['message'];
                    }
                }
            }
        }

        // Approve / revoke links
        if ($action === 'approve_link') {
            approveLink((int)($_POST['link_id'] ?? 0));
            $success[] = 'Link approved.';
        }
        if ($action === 'revoke_link') {
            revokeLink((int)($_POST['link_id'] ?? 0));
            $success[] = 'Link revoked.';
        }

        if ($action === 'reactivate_link') {
            $lid = (int)($_POST['link_id'] ?? 0);
            if ($lid) {
                getDB()->prepare('UPDATE account_links SET status = "active" WHERE link_id = ?')
                       ->execute([$lid]);
                $success[] = 'Link reactivated.';
            }
        }

        // Admin: delete user
        if ($action === 'delete_user' && $user['role'] === 'admin') {
            $uid = (int)($_POST['target_user_id'] ?? 0);
            if ($uid && $uid !== (int)$user['user_id']) {
                $db->prepare('DELETE FROM users WHERE user_id=?')->execute([$uid]);
                $success[] = 'User deleted.';
            }
        }
    }
}

// ── Fetch data ────────────────────────────────────────────────
if ($user['role'] === 'admin') {
    $users      = $db->query('SELECT * FROM users ORDER BY created_at DESC')->fetchAll();
    $links      = $db->query(
        'SELECT al.*, e.full_name AS elder_name, e.email AS elder_email,
                c.full_name AS caregiver_name
         FROM account_links al
         JOIN users e ON al.elder_user_id = e.user_id
         JOIN users c ON al.caregiver_user_id = c.user_id
         ORDER BY al.created_at DESC'
    )->fetchAll();
    $caregivers    = array_filter($users, fn($u) => $u['role'] === 'caregiver');
    $billingOverview = getAdminBillingOverview();
} else {
    $stmt = $db->prepare(
        'SELECT al.*, u.full_name AS elder_name, u.email AS elder_email
         FROM account_links al JOIN users u ON al.elder_user_id = u.user_id
         WHERE al.caregiver_user_id = ?
         ORDER BY al.created_at DESC'
    );
    $stmt->execute([$user['user_id']]);
    $links = $stmt->fetchAll();
    $users = [];
    $billingOverview = [];
}

$pageTitle = ($user['role'] === 'admin') ? 'User Management' : 'Manage My Elders';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-container">
    <h1><?= $user['role'] === 'admin' ? 'User Management' : 'Manage My Elders' ?></h1>

    <?php foreach ($errors  as $e): ?><div class="alert alert-danger"><?= e($e) ?></div><?php endforeach; ?>
    <?php foreach ($success as $s): ?><div class="alert alert-success"><?= e($s) ?></div><?php endforeach; ?>

    <!-- ── LINK NEW ELDER ────────────────────────────────────────── -->
    <div class="card">
        <h2>Link an Elder Account</h2>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="link">
            <div class="form-inline">
                <input type="email" name="elder_email" placeholder="Elder's email address" required>
                <?php if ($user['role'] === 'admin'): ?>
                <select name="caregiver_id">
                    <?php foreach ($caregivers as $cg): ?>
                        <option value="<?= $cg['user_id'] ?>"><?= e($cg['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary">Send Link Request</button>
            </div>
        </form>
    </div>

    <!-- ── ACCOUNT LINKS ──────────────────────────────────────────── -->
    <?php if (!empty($links)): ?>
    <div class="card">
        <h2>Caregiver–Elder Relationships</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Elder</th>
                    <?php if ($user['role']==='admin'): ?><th>Caregiver</th><?php endif; ?>
                    <th>Status</th>
                    <th>Linked</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($links as $l): ?>
                <tr>
                    <td><?= e($l['elder_name']) ?><br><small><?= e($l['elder_email']) ?></small></td>
                    <?php if ($user['role']==='admin'): ?><td><?= e($l['caregiver_name']) ?></td><?php endif; ?>
                    <td><span class="status-badge status-<?= e($l['status']) ?>"><?= e(ucfirst($l['status'])) ?></span></td>
                    <td><?= e(date("M j, Y", strtotime($l["created_at"]))) ?></td>
                    <td>
                        <?php if ($l['status'] === 'pending'): ?>
                            <form method="POST" style="display:inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="approve_link">
                                <input type="hidden" name="link_id" value="<?= $l['link_id'] ?>">
                                <button class="btn btn-sm btn-success">Approve</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($l['status'] === 'active'): ?>
                            <form method="POST" style="display:inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="revoke_link">
                                <input type="hidden" name="link_id" value="<?= $l['link_id'] ?>">
                                <button class="btn btn-sm btn-danger"
                                        onclick="return confirm('Revoke this relationship?')">
                                    Revoke
                                </button>
                            </form>
                        <?php endif; ?>
                        <?php if ($l['status'] === 'revoked'): ?>
                            <form method="POST" style="display:inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="reactivate_link">
                                <input type="hidden" name="link_id" value="<?= $l['link_id'] ?>">
                                <button class="btn btn-sm btn-success"
                                        onclick="return confirm('Reactivate this link?')">
                                    Reactivate
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- ── ADMIN: BILLING OVERVIEW ───────────────────────────────── -->
    <?php if ($user['role'] === 'admin' && !empty($billingOverview)): ?>
    <div class="card">
        <div class="page-header" style="margin-bottom:1rem;">
            <h2>Caregiver Billing Overview</h2>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Caregiver</th>
                    <th>Active Elders</th>
                    <th>Monthly Charge</th>
                    <th>Last Billed</th>
                    <th>Last Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($billingOverview as $row): ?>
                <tr class="<?= $row['last_status'] === 'failed' ? 'row-danger' : '' ?>">
                    <td>
                        <?= e($row['full_name']) ?><br>
                        <small style="color:var(--color-muted)"><?= e($row['email']) ?></small>
                    </td>
                    <td><?= (int)$row['active_elders'] ?></td>
                    <td><?= formatCents((int)$row['monthly_cents']) ?></td>
                    <td><?= $row['last_billed'] ? e(formatBillingMonth($row['last_billed'])) : 'Never' ?></td>
                    <td>
                        <?php if ($row['last_status']): ?>
                        <span class="status-badge status-<?= e($row['last_status']) ?>">
                            <?= e(ucfirst($row['last_status'])) ?>
                        </span>
                        <?php else: ?>
                            <span style="color:var(--color-muted)">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- ── ALL USERS (admin only) ─────────────────────────────────── -->
    <?php if ($user['role'] === 'admin' && !empty($users)): ?>
    <div class="card">
        <h2>All Users (<?= count($users) ?>)</h2>
        <table class="data-table">
            <thead>
                <tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Joined</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr class="<?= !$u['is_active'] ? 'row-muted' : '' ?>">
                    <td><?= e($u['full_name']) ?></td>
                    <td><?= e($u['email']) ?></td>
                    <td>
                        <form method="POST" style="display:inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="change_role">
                            <input type="hidden" name="target_user_id" value="<?= $u['user_id'] ?>">
                            <select name="new_role" onchange="this.form.submit()">
                                <?php foreach (['elder','caregiver','admin'] as $r): ?>
                                    <option value="<?= $r ?>" <?= $u['role']===$r?'selected':'' ?>><?= ucfirst($r) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </td>
                    <td>
                        <form method="POST" style="display:inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="target_user_id" value="<?= $u['user_id'] ?>">
                            <button class="btn btn-sm <?= $u['is_active'] ? 'btn-warning' : 'btn-success' ?>"
                                <?= $u['user_id']==$user['user_id'] ? 'disabled' : '' ?>>
                                <?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>
                            </button>
                        </form>
                    </td>
                    <td><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                    <td>
                        <?php if ($u['user_id'] != $user['user_id']): ?>
                        <form method="POST" style="display:inline"
                              onsubmit="return confirm('Permanently delete <?= e($u['full_name']) ?>?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_user">
                            <input type="hidden" name="target_user_id" value="<?= $u['user_id'] ?>">
                            <button class="btn btn-sm btn-danger">Delete</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
