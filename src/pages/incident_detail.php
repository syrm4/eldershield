<?php
// pages/incident_detail.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/ai_service.php';

requireLogin();
$user       = currentUser();
$incidentId = (int)($_GET['id'] ?? 0);

if (!$incidentId) {
    header('Location: ' . APP_URL . '/pages/dashboard.php');
    exit;
}

$incident = getIncidentById($incidentId);
if (!$incident) {
    setFlash('danger', 'Incident not found.');
    header('Location: ' . APP_URL . '/pages/dashboard.php');
    exit;
}

$db = getDB();

// ── Access control ────────────────────────────────────────────
if ($user['role'] === 'elder' && $incident['user_id'] != $user['user_id']) {
    header('Location: ' . APP_URL . '/pages/dashboard.php');
    exit;
}
if ($user['role'] === 'caregiver') {
    $check = $db->prepare(
        'SELECT link_id FROM account_links
         WHERE elder_user_id = ? AND caregiver_user_id = ? AND status = "active"'
    );
    $check->execute([$incident['user_id'], $user['user_id']]);
    if (!$check->fetch()) {
        header('Location: ' . APP_URL . '/pages/dashboard.php');
        exit;
    }
}

$errors = [];

// ── Handle POST actions ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid form token.');
        header('Location: ' . APP_URL . '/pages/incident_detail.php?id=' . $incidentId);
        exit;
    }

    $action = $_POST['action'] ?? '';

    // ── Admin/caregiver: update status ────────────────────────
    if ($action === 'update_status' && in_array($user['role'], ['admin','caregiver'])) {
        $newStatus = $_POST['status'] ?? '';
        if ($newStatus) {
            updateIncidentStatus($incidentId, $newStatus);
            setFlash('success', 'Status updated.');
        }
        header('Location: ' . APP_URL . '/pages/incident_detail.php?id=' . $incidentId);
        exit;
    }

    // ── Admin: manually edit analysis fields ──────────────────
    if ($action === 'edit_analysis' && $user['role'] === 'admin') {
        $probability = max(0, min(100, (int)($_POST['scam_probability'] ?? 0)));
        $category    = trim($_POST['scam_category'] ?? 'other');
        $explanation = trim($_POST['explanation_simple'] ?? '');
        $recommended = trim($_POST['recommended_action'] ?? '');

        $validCategories = [
            'phishing','impersonation','romance_scam','tech_support',
            'lottery_prize','grandparent_scam','investment_fraud','other','not_a_scam'
        ];
        if (!in_array($category, $validCategories, true)) $category = 'other';

        if (strlen($explanation) < 5) {
            $errors[] = 'Explanation must be at least 5 characters.';
        } elseif (strlen($recommended) < 5) {
            $errors[] = 'Recommended action must be at least 5 characters.';
        } else {
            // Parse tactics from comma-separated input
            $tacticsRaw = trim($_POST['manipulation_tactics'] ?? '');
            $tactics    = array_values(array_filter(
                array_map('trim', explode(',', $tacticsRaw))
            ));

            // Upsert — update if exists, insert if not.
            // ai_error is explicitly set to NULL: a human override clears any prior failure flag.
            $db->prepare(
                'INSERT INTO analysis
                    (incident_id, scam_probability, scam_category, manipulation_tactics,
                     explanation_simple, recommended_action, ai_error)
                 VALUES (?, ?, ?, ?, ?, ?, NULL)
                 ON DUPLICATE KEY UPDATE
                    scam_probability    = VALUES(scam_probability),
                    scam_category       = VALUES(scam_category),
                    manipulation_tactics = VALUES(manipulation_tactics),
                    explanation_simple  = VALUES(explanation_simple),
                    recommended_action  = VALUES(recommended_action),
                    ai_error            = NULL'
            )->execute([
                $incidentId, $probability, $category,
                json_encode($tactics), $explanation, $recommended
            ]);

            $db->prepare('UPDATE incidents SET status = "reviewed" WHERE incident_id = ?')
               ->execute([$incidentId]);

            setFlash('success', 'Analysis updated manually.');
            header('Location: ' . APP_URL . '/pages/incident_detail.php?id=' . $incidentId);
            exit;
        }
    }

    // ── Admin: reprompt AI (wipe analysis, re-run in background) ──
    if ($action === 'reprompt_ai' && $user['role'] === 'admin') {
        $db->prepare('DELETE FROM analysis WHERE incident_id = ?')->execute([$incidentId]);
        $db->prepare('UPDATE incidents SET status = "pending" WHERE incident_id = ?')
           ->execute([$incidentId]);

        analyzeIncidentAsync(
            $incidentId,
            $incident['content'],
            $incident['image_path'] ?: null
        );

        setFlash('success', 'AI re-analysis started. Results will appear shortly.');
        header('Location: ' . APP_URL . '/pages/incident_detail.php?id=' . $incidentId);
        exit;
    }

    // ── Admin: clear analysis (leaves blank for elder to re-submit) ──
    if ($action === 'clear_analysis' && $user['role'] === 'admin') {
        $db->prepare('DELETE FROM analysis WHERE incident_id = ?')->execute([$incidentId]);
        // Use 'cleared' status — distinct from 'pending' (AI is running).
        // 'cleared' means the admin wiped it; the elder should review and re-submit.
        $db->prepare('UPDATE incidents SET status = "cleared" WHERE incident_id = ?')
           ->execute([$incidentId]);

        setFlash('success', 'Analysis cleared. The elder will be prompted to review and re-submit their report.');
        header('Location: ' . APP_URL . '/pages/incident_detail.php?id=' . $incidentId);
        exit;
    }

    // ── Elder: edit submission and re-run AI ──────────────────
    if ($action === 'edit_submission' && $user['role'] === 'elder'
        && $incident['user_id'] == $user['user_id']) {

        $newContent   = trim($_POST['content'] ?? '');
        $newImagePath = $incident['image_path'];

        if (strlen($newContent) < 10) {
            $errors[] = 'Please describe the suspicious message in at least 10 characters.';
        }

        if (!empty($_FILES['screenshot']['name'])) {
            $upload = handleImageUpload($_FILES['screenshot']);
            if (!$upload['success']) {
                $errors[] = $upload['message'];
            } else {
                if ($incident['image_path'] && file_exists($incident['image_path'])) {
                    @unlink($incident['image_path']);
                }
                $newImagePath = $upload['path'];
            }
        }

        if (isset($_POST['remove_image']) && $_POST['remove_image'] === '1') {
            if ($incident['image_path'] && file_exists($incident['image_path'])) {
                @unlink($incident['image_path']);
            }
            $newImagePath = null;
        }

        if (!$errors) {
            $db->prepare(
                'UPDATE incidents SET content = ?, image_path = ?, status = "pending" WHERE incident_id = ?'
            )->execute([$newContent, $newImagePath, $incidentId]);
            $db->prepare('DELETE FROM analysis WHERE incident_id = ?')->execute([$incidentId]);
            analyzeIncidentAsync($incidentId, $newContent, $newImagePath);

            setFlash('success', 'Your report has been updated and is being re-analyzed.');
            header('Location: ' . APP_URL . '/pages/incident_detail.php?id=' . $incidentId);
            exit;
        }
    }
}

// Re-fetch after any updates
$incident = getIncidentById($incidentId);

// $analysisReady is true only when a successful AI result exists.
// A failed analysis (ai_error set) leaves scam_probability at 0 but must
// NOT render as "Low Risk 0%" — the error banner is shown instead.
$aiError       = $incident['ai_error'] ?? null;
$analysisReady = ($incident['scam_probability'] !== null && empty($aiError));
$probability   = (float)($incident['scam_probability'] ?? 0);
$riskLevel     = getRiskLevel($probability);
$tactics       = json_decode($incident['manipulation_tactics'] ?? '[]', true) ?: [];

$ownerStmt = $db->prepare('SELECT full_name FROM users WHERE user_id = ?');
$ownerStmt->execute([$incident['user_id']]);
$ownerName = $ownerStmt->fetchColumn();

$isOwner  = ($user['role'] === 'elder' && $incident['user_id'] == $user['user_id']);
// Show edit form if owner AND status is not 'pending' (AI running) — 'cleared' should allow editing
$showEdit = $isOwner && !in_array($incident['status'], ['pending']);

$validCategories = [
    'phishing','impersonation','romance_scam','tech_support',
    'lottery_prize','grandparent_scam','investment_fraud','other','not_a_scam'
];

$pageTitle = 'Incident #' . $incidentId;
include __DIR__ . '/../includes/header.php';
?>

<div class="detail-container">

    <div class="detail-header">
        <a href="<?= APP_URL ?>/pages/<?= $user['role'] === 'elder' ? 'my_incidents' : 'incidents' ?>.php"
           class="back-link">&larr; Back to Reports</a>
        <h1>Scam Report #<?= $incidentId ?></h1>
        <div class="detail-meta">
            <span>Submitted by <strong><?= e($ownerName) ?></strong></span>
            <span><?= date('F j, Y g:i A', strtotime($incident['submitted_at'])) ?></span>
            <span class="status-badge status-<?= e($incident['status']) ?>">
                <?= e(ucfirst($incident['status'])) ?>
            </span>
            <?php if ($user['role'] === 'admin' && $analysisReady): ?>
                <span class="badge badge-secondary" style="font-size:.75rem;">Admin editable</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <?php foreach ($errors as $err): ?><p><?= e($err) ?></p><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($aiError): ?>
    <!-- ── AI FAILURE WARNING ───────────────────────────────── -->
    <div class="alert alert-warning">
        <strong>⚠️ AI analysis could not complete.</strong>
        Make sure Ollama is running and the model
        <code><?= e(OLLAMA_MODEL) ?></code> is pulled
        (<code>ollama pull <?= e(OLLAMA_MODEL) ?></code>).
        <?php if ($user['role'] === 'admin'): ?>
            Once Ollama is ready, use <strong>Reprompt AI</strong> below to retry.
        <?php else: ?>
            A caregiver or admin has been notified and can retry the analysis.
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($analysisReady): ?>
    <!-- ── AI RESULT ─────────────────────────────────────────── -->
    <div class="result-card result-<?= $riskLevel ?>">
        <div class="result-score-area">
            <div class="risk-gauge">
                <div class="gauge-fill" style="width: <?= round($probability) ?>%"></div>
            </div>
            <div class="risk-score"><?= round($probability) ?>%</div>
            <div class="risk-label"><?= getRiskLabel($riskLevel) ?></div>
        </div>
        <div class="result-details">
            <div class="result-section">
                <h3>📋 Scam Type</h3>
                <p class="scam-category"><?= e(formatCategory($incident['scam_category'] ?? 'Unknown')) ?></p>
            </div>
            <?php if (!empty($tactics)): ?>
            <div class="result-section">
                <h3>🎯 Tactics Detected</h3>
                <ul class="tactics-list">
                    <?php foreach ($tactics as $tactic): ?>
                        <li><?= e(formatCategory($tactic)) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
            <div class="result-section">
                <h3>💬 What This Means</h3>
                <p class="explanation-text"><?= e($incident['explanation_simple'] ?? '') ?></p>
            </div>
            <div class="result-section">
                <h3>✅ What You Should Do</h3>
                <div class="action-box"><?= nl2br(e($incident['recommended_action'] ?? '')) ?></div>
            </div>
        </div>
    </div>

    <?php elseif (!$aiError): ?>
    <!-- Only show the spinner when analysis is genuinely in progress (no error). -->
    <div class="alert alert-info analyzing-banner">
        <span class="analyzing-spinner">⏳</span>
        <strong>Analysis in progress...</strong>
        This page will update automatically &mdash; no need to do anything.
    </div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════════════
         ADMIN: ANALYSIS CONTROLS
         ══════════════════════════════════════════════════════════ -->
    <?php if ($user['role'] === 'admin'): ?>
    <div class="card admin-analysis-card">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:.75rem; margin-bottom:1.25rem;">
            <h2>🔧 Admin &mdash; Analysis Controls</h2>
            <div style="display:flex; gap:.6rem; flex-wrap:wrap;">
                <?php if (!in_array($incident['status'], ['pending', 'cleared']) || $aiError): ?>
                <button class="btn btn-sm btn-secondary" onclick="toggleAdminEdit()">
                    ✏️ Edit Analysis
                </button>
                <form method="POST" style="display:inline;"
                      onsubmit="return confirm('Re-run the AI on this submission? Current analysis will be replaced.')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="reprompt_ai">
                    <button type="submit" class="btn btn-sm btn-primary">🤖 Reprompt AI</button>
                </form>
                <?php endif; ?>
                <form method="POST" style="display:inline;"
                      onsubmit="return confirm('Clear the analysis? The elder will see a blank result and can re-submit.')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="clear_analysis">
                    <button type="submit" class="btn btn-sm btn-danger">🗑 Clear Analysis</button>
                </form>
            </div>
        </div>

        <!-- ── Manual edit form ───────────────────────────────── -->
        <div id="adminEditForm" style="display:none;">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="edit_analysis">

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
                    <div class="form-group">
                        <label for="a_probability">Scam Probability (0–100)</label>
                        <input type="number" id="a_probability" name="scam_probability"
                               min="0" max="100"
                               value="<?= $analysisReady ? round($probability) : 0 ?>"
                               required>
                    </div>
                    <div class="form-group">
                        <label for="a_category">Scam Category</label>
                        <select id="a_category" name="scam_category">
                            <?php foreach ($validCategories as $cat): ?>
                                <option value="<?= $cat ?>"
                                    <?= ($incident['scam_category'] ?? '') === $cat ? 'selected' : '' ?>>
                                    <?= e(formatCategory($cat)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="a_tactics">Manipulation Tactics
                        <small style="font-weight:400; color:var(--color-muted);">
                            &mdash; comma separated, e.g. urgency, fear_based_language
                        </small>
                    </label>
                    <input type="text" id="a_tactics" name="manipulation_tactics"
                           value="<?= e(implode(', ', $tactics)) ?>"
                           placeholder="urgency, fear_based_language, authority_impersonation">
                </div>

                <div class="form-group">
                    <label for="a_explanation">Plain-Language Explanation</label>
                    <textarea id="a_explanation" name="explanation_simple" rows="4"
                              required minlength="5"
                              placeholder="2–3 sentences a senior would understand..."><?= e($incident['explanation_simple'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label for="a_recommended">Recommended Actions</label>
                    <textarea id="a_recommended" name="recommended_action" rows="3"
                              required minlength="5"
                              placeholder="2–3 clear steps the user should take..."><?= e($incident['recommended_action'] ?? '') ?></textarea>
                </div>

                <div style="display:flex; gap:.75rem; flex-wrap:wrap;">
                    <button type="submit" class="btn btn-primary">💾 Save Manual Analysis</button>
                    <button type="button" class="btn btn-outline" onclick="toggleAdminEdit()">Cancel</button>
                </div>
            </form>
        </div>

        <?php if ($analysisReady): ?>
        <p style="font-size:.875rem; color:var(--color-muted); margin-top:<?= $incident['status'] === 'pending' ? '0' : '.75rem' ?>;">
            <strong>Edit Analysis</strong> &mdash; overwrite the AI result with your own values, marked as reviewed.<br>
            <strong>Reprompt AI</strong> &mdash; discard current result and re-run Ollama on the original submission.<br>
            <strong>Clear Analysis</strong> &mdash; remove the result entirely so the elder sees a blank state and can edit &amp; re-submit.
        </p>
        <?php else: ?>
        <p style="font-size:.875rem; color:var(--color-muted);">
            No analysis yet. Use <strong>Reprompt AI</strong> to run analysis now, or wait for the elder to re-submit.
        </p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── SUBMISSION + ELDER EDIT ───────────────────────────── -->
    <div class="submission-card">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:.75rem; margin-bottom:1rem;">
            <h2><?= $isOwner ? 'Your Submission' : 'Original Submission' ?></h2>
            <?php if ($showEdit): ?>
                <button class="btn btn-sm btn-secondary" id="editToggleBtn"
                        onclick="toggleEditForm()">
                    ✏️ Edit &amp; Re-analyze
                </button>
            <?php endif; ?>
        </div>

        <div id="submissionView">
            <div class="submission-content"><?= nl2br(e($incident['content'])) ?></div>
            <?php if ($incident['image_path'] && file_exists($incident['image_path'])): ?>
            <div class="submission-image" style="margin-top:1rem;">
                <h3 style="margin-bottom:.5rem;">Attached Screenshot</h3>
                <img src="<?= APP_URL ?>/uploads/<?= e(basename($incident['image_path'])) ?>"
                     alt="Submitted screenshot" class="screenshot-img">
            </div>
            <?php endif; ?>
        </div>

        <?php if ($showEdit): ?>
        <div id="editForm" style="display:none; margin-top:1.25rem; border-top:2px solid var(--color-border); padding-top:1.25rem;">
            <form method="POST" enctype="multipart/form-data">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="edit_submission">
                <div class="form-group">
                    <label for="edit_content">Update your description:</label>
                    <textarea id="edit_content" name="content" rows="7"
                              required minlength="10"><?= e($incident['content']) ?></textarea>
                    <small>Add more details to help the AI give a better result.</small>
                </div>
                <?php if ($incident['image_path'] && file_exists($incident['image_path'])): ?>
                <div class="form-group">
                    <label>Current Screenshot</label>
                    <div style="display:flex; align-items:center; gap:1rem; flex-wrap:wrap;">
                        <img src="<?= APP_URL ?>/uploads/<?= e(basename($incident['image_path'])) ?>"
                             alt="Current screenshot"
                             style="max-height:100px; border-radius:var(--radius); border:1px solid var(--color-border);">
                        <label style="display:flex; align-items:center; gap:.5rem; font-weight:500; cursor:pointer;">
                            <input type="checkbox" name="remove_image" value="1"
                                   style="width:1.1rem; height:1.1rem;">
                            Remove this screenshot
                        </label>
                    </div>
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label for="edit_screenshot">
                        <?= $incident['image_path'] ? 'Replace Screenshot (optional)' : 'Add a Screenshot (optional)' ?>
                    </label>
                    <div class="upload-zone" id="editUploadZone">
                        <input type="file" id="edit_screenshot" name="screenshot"
                               accept="image/*" class="upload-input">
                        <div class="upload-label">
                            <span>Click to upload or drag &amp; drop</span>
                            <small>JPG, PNG, GIF, WEBP &middot; Max <?= (int)UPLOAD_MAX_MB ?>MB</small>
                        </div>
                        <div id="editImagePreview" class="image-preview hidden"></div>
                    </div>
                </div>
                <div style="display:flex; gap:.75rem; flex-wrap:wrap;">
                    <button type="submit" class="btn btn-primary" id="reanalyzeBtn">
                        🔍 Save &amp; Re-analyze
                    </button>
                    <button type="button" class="btn btn-outline" onclick="toggleEditForm()">Cancel</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($isOwner && $incident['status'] === 'pending' && !$aiError): ?>
        <p style="margin-top:.75rem; font-size:.875rem; color:var(--color-muted);">
            ⏳ Analysis is running. You can edit your report once it finishes.
        </p>
        <?php elseif ($isOwner && $incident['status'] === 'cleared'): ?>
        <div class="alert alert-info" style="margin-top:.75rem;">
            ✏️ <strong>An admin has cleared the previous analysis.</strong>
            Please review your description below, make any changes if needed, and re-submit so it can be re-analyzed.
        </div>
        <?php endif; ?>
    </div>

    <!-- ── ADMIN/CAREGIVER STATUS ─────────────────────────────── -->
    <?php if (in_array($user['role'], ['admin','caregiver'])): ?>
    <div class="admin-controls">
        <h2>Update Status</h2>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_status">
            <div class="form-inline">
                <select name="status">
                    <?php foreach (['pending','cleared','analyzed','reviewed','dismissed'] as $s): ?>
                        <option value="<?= $s ?>" <?= $incident['status'] == $s ? 'selected' : '' ?>>
                            <?= ucfirst($s) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-secondary">Update Status</button>
            </div>
        </form>
        <?php if ($user['role'] === 'admin'): ?>
        <div class="danger-zone">
            <a href="<?= APP_URL ?>/api/delete_incident.php?id=<?= $incidentId ?>&csrf=<?= urlencode(csrfToken()) ?>"
               class="btn btn-danger"
               onclick="return confirm('Permanently delete this incident and all its data?')">
                🗑️ Delete Incident
            </a>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── ELDER DELETE ───────────────────────────────────────── -->
    <?php if ($isOwner): ?>
    <div class="elder-controls">
        <a href="<?= APP_URL ?>/api/delete_incident.php?id=<?= $incidentId ?>&csrf=<?= urlencode(csrfToken()) ?>"
           class="btn btn-outline-danger btn-sm"
           onclick="return confirm('Remove this report from your history?')">
            Remove This Report
        </a>
    </div>
    <?php endif; ?>

</div>

<?php
// Auto-refresh only when analysis is genuinely in progress.
// Do NOT refresh when ai_error is set — Ollama being down won't fix itself on reload.
if (!$analysisReady && $incident['status'] === 'pending' && !$aiError):
?>
<script>setTimeout(function() { window.location.reload(); }, 5000);</script>
<?php endif; ?>

<style>
.admin-analysis-card {
    border-left: 4px solid #7c3aed;
    background: #faf5ff;
}
.admin-analysis-card h2 { color: #5b21b6; }
</style>

<script>
// ── Admin analysis panel toggle ───────────────────────────────
function toggleAdminEdit() {
    const form = document.getElementById('adminEditForm');
    if (!form) return;
    const showing = form.style.display !== 'none';
    form.style.display = showing ? 'none' : 'block';
    if (!showing) form.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ── Elder edit form toggle ────────────────────────────────────
function toggleEditForm() {
    const view    = document.getElementById('submissionView');
    const form    = document.getElementById('editForm');
    const btn     = document.getElementById('editToggleBtn');
    if (!form) return;
    const showing = form.style.display !== 'none';
    form.style.display = showing ? 'none'  : 'block';
    view.style.display = showing ? 'block' : 'none';
    btn.textContent    = showing ? '✏️ Edit & Re-analyze' : '✕ Cancel Edit';
    if (!showing) form.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ── Image preview in elder edit form ─────────────────────────
function editScreenshotChangeHandler(e) {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function(ev) {
        const preview = document.getElementById('editImagePreview');
        preview.innerHTML = '<img src="' + ev.target.result + '" alt="Preview" style="max-height:160px;">'
            + '<button type="button" onclick="clearEditImage()" class="btn btn-sm btn-secondary" style="display:block;margin-top:.5rem;">Remove</button>';
        preview.classList.remove('hidden');
        document.querySelector('#editUploadZone .upload-label').classList.add('hidden');
    };
    reader.readAsDataURL(file);
}
document.getElementById('edit_screenshot')?.addEventListener('change', editScreenshotChangeHandler);

function clearEditImage() {
    // Replace the file input with a fresh clone so the browser truly clears it
    const inp = document.getElementById('edit_screenshot');
    const newInp = inp.cloneNode(true);
    inp.parentNode.replaceChild(newInp, inp);
    // Re-attach the change listener to the new input
    newInp.addEventListener('change', editScreenshotChangeHandler);
    document.getElementById('editImagePreview').innerHTML = '';
    document.getElementById('editImagePreview').classList.add('hidden');
    document.querySelector('#editUploadZone .upload-label').classList.remove('hidden');
}

// ── Loading state on elder re-analyze ────────────────────────
document.getElementById('reanalyzeBtn')?.closest('form')?.addEventListener('submit', function() {
    const btn = document.getElementById('reanalyzeBtn');
    btn.textContent = '⏳ Submitting...';
    btn.disabled = true;
});

// ── Risk gauge animation ──────────────────────────────────────
const gauge = document.querySelector('.gauge-fill');
if (gauge) {
    const target = gauge.style.width;
    gauge.style.width = '0%';
    gauge.style.transition = 'width 1s ease-out';
    setTimeout(() => { gauge.style.width = target; }, 100);
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
