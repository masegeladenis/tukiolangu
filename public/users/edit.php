<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';

use App\Helpers\Auth;
use App\Helpers\Session;
use App\Helpers\Utils;
use App\Database\Connection;

Session::start();
Auth::requireAdmin();

$db = Connection::getInstance();
$pageTitle = 'Edit User';
$errors = [];

$userId = (int) ($_GET['id'] ?? 0);
if (!$userId) {
    Utils::redirect('/users/index.php');
}

$editUser = $db->queryOne("SELECT * FROM users WHERE id = :id", ['id' => $userId]);
if (!$editUser) {
    Session::flash('error', 'User not found.');
    Utils::redirect('/users/index.php');
}

// Fetch all events and currently assigned event IDs
$allEvents = $db->query("SELECT id, event_name, event_code FROM events ORDER BY created_at DESC");
$assignedRows = $db->query("SELECT event_id FROM event_users WHERE user_id = :user_id", ['user_id' => $userId]);
$currentlyAssigned = array_column($assignedRows, 'event_id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName  = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $role      = $_POST['role'] ?? 'scanner';
    $isActive  = isset($_POST['is_active']) ? 1 : 0;
    $newPassword = $_POST['password'] ?? '';
    $assignedEvents = $_POST['assigned_events'] ?? [];

    if (empty($fullName)) $errors[] = 'Full name is required.';
    if (!in_array($role, ['admin', 'scanner'])) $errors[] = 'Invalid role.';
    if (!empty($newPassword) && strlen($newPassword) < 6) $errors[] = 'Password must be at least 6 characters.';

    if (empty($errors)) {
        $params = [
            'full_name' => $fullName,
            'email'     => $email ?: null,
            'role'      => $role,
            'is_active' => $isActive,
            'id'        => $userId,
        ];

        $passwordSql = '';
        if (!empty($newPassword)) {
            $passwordSql = ', password = :password';
            $params['password'] = Auth::hashPassword($newPassword);
        }

        $db->execute(
            "UPDATE users SET full_name = :full_name, email = :email, role = :role, is_active = :is_active{$passwordSql} WHERE id = :id",
            $params
        );

        // Sync event assignments
        $db->execute("DELETE FROM event_users WHERE user_id = :user_id", ['user_id' => $userId]);
        if ($role === 'scanner' && !empty($assignedEvents)) {
            foreach ($assignedEvents as $eventId) {
                $eventId = (int) $eventId;
                if ($eventId > 0) {
                    $db->execute(
                        "INSERT IGNORE INTO event_users (event_id, user_id, assigned_by) VALUES (:event_id, :user_id, :assigned_by)",
                        ['event_id' => $eventId, 'user_id' => $userId, 'assigned_by' => Auth::id()]
                    );
                }
            }
        }

        Session::flash('success', "User '{$fullName}' updated successfully.");
        Utils::redirect('/users/index.php');
    }

    // Re-populate from POST on validation failure
    $editUser = array_merge($editUser, [
        'full_name' => $fullName,
        'email'     => $email,
        'role'      => $role,
        'is_active' => $isActive,
    ]);
    $currentlyAssigned = array_map('intval', $assignedEvents);
}

ob_start();
?>

<div class="card" style="max-width: 700px; margin: 0 auto;">
    <div class="card-header">
        <h3>Edit User: <?= Utils::escape($editUser['full_name']) ?></h3>
        <a href="index.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>
    <div class="card-body">
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul style="margin: 0; padding-left: 20px;">
                    <?php foreach ($errors as $e): ?>
                        <li><?= Utils::escape($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                <input type="text" name="full_name" class="form-control" value="<?= Utils::escape($editUser['full_name']) ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">Username</label>
                <input type="text" class="form-control" value="<?= Utils::escape($editUser['username']) ?>" disabled>
                <small class="text-muted">Username cannot be changed.</small>
            </div>

            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" value="<?= Utils::escape($editUser['email'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label class="form-label">New Password</label>
                <input type="password" name="password" class="form-control" autocomplete="new-password" minlength="6">
                <small class="text-muted">Leave blank to keep current password.</small>
            </div>

            <div class="form-group">
                <label class="form-label">Role <span class="text-danger">*</span></label>
                <select name="role" class="form-control" id="role-select" <?= $editUser['id'] === Auth::id() ? 'disabled' : '' ?>>
                    <option value="scanner" <?= $editUser['role'] === 'scanner' ? 'selected' : '' ?>>Scanner</option>
                    <option value="admin" <?= $editUser['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                </select>
                <?php if ($editUser['id'] === Auth::id()): ?>
                    <input type="hidden" name="role" value="<?= Utils::escape($editUser['role']) ?>">
                    <small class="text-muted">You cannot change your own role.</small>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label">
                    <input type="checkbox" name="is_active" value="1" <?= $editUser['is_active'] ? 'checked' : '' ?>>
                    Active (can log in)
                </label>
            </div>

            <!-- Event assignment — only for scanner role -->
            <div class="form-group" id="event-assignment-section">
                <label class="form-label">Assigned Events</label>
                <small class="text-muted d-block mb-2">Select which events this scanner can access. Admins have access to all events.</small>
                <?php if (empty($allEvents)): ?>
                    <p class="text-muted">No events found.</p>
                <?php else: ?>
                    <div style="max-height: 280px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 6px; padding: 12px;">
                        <?php foreach ($allEvents as $event): ?>
                        <label style="display: flex; align-items: center; gap: 8px; padding: 6px 0; cursor: pointer;">
                            <input type="checkbox" name="assigned_events[]" value="<?= $event['id'] ?>"
                                <?= in_array((int) $event['id'], $currentlyAssigned) ? 'checked' : '' ?>>
                            <span>
                                <strong><?= Utils::escape($event['event_name']) ?></strong>
                                <code style="font-size: 12px; margin-left: 4px;"><?= Utils::escape($event['event_code']) ?></code>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div style="display: flex; gap: 12px; margin-top: 24px;">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Changes
                </button>
                <a href="index.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var roleSelect = document.getElementById('role-select');
    var assignSection = document.getElementById('event-assignment-section');

    function toggleAssign() {
        var role = roleSelect ? roleSelect.value : '<?= Utils::escape($editUser['role']) ?>';
        assignSection.style.display = role === 'scanner' ? 'block' : 'none';
    }

    if (roleSelect) roleSelect.addEventListener('change', toggleAssign);
    toggleAssign();
})();
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../templates/layout.php';
?>
