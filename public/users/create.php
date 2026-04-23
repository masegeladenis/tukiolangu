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
$pageTitle = 'Add User';
$errors = [];

// Fetch all events for assignment
$allEvents = $db->query("SELECT id, event_name, event_code FROM events ORDER BY created_at DESC");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName  = trim($_POST['full_name'] ?? '');
    $username  = trim($_POST['username'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $role      = $_POST['role'] ?? 'scanner';
    $password  = $_POST['password'] ?? '';
    $isActive  = isset($_POST['is_active']) ? 1 : 0;
    $assignedEvents = $_POST['assigned_events'] ?? [];

    // Validate
    if (empty($fullName)) $errors[] = 'Full name is required.';
    if (empty($username)) $errors[] = 'Username is required.';
    if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';
    if (!in_array($role, ['admin', 'scanner'])) $errors[] = 'Invalid role.';

    // Check username uniqueness
    if (empty($errors)) {
        $existing = $db->queryOne("SELECT id FROM users WHERE username = :username", ['username' => $username]);
        if ($existing) $errors[] = 'Username already exists.';
    }

    if (empty($errors)) {
        $userId = $db->insert(
            "INSERT INTO users (full_name, username, email, role, password, is_active) VALUES (:full_name, :username, :email, :role, :password, :is_active)",
            [
                'full_name' => $fullName,
                'username'  => $username,
                'email'     => $email ?: null,
                'role'      => $role,
                'password'  => Auth::hashPassword($password),
                'is_active' => $isActive,
            ]
        );

        // Assign events (only relevant for scanners)
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

        Session::flash('success', "User '{$fullName}' created successfully.");
        Utils::redirect('/users/index.php');
    }
}

ob_start();
?>

<div class="card" style="max-width: 700px; margin: 0 auto;">
    <div class="card-header">
        <h3>Add New User</h3>
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
                <input type="text" name="full_name" class="form-control" value="<?= Utils::escape($_POST['full_name'] ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">Username <span class="text-danger">*</span></label>
                <input type="text" name="username" class="form-control" value="<?= Utils::escape($_POST['username'] ?? '') ?>" required autocomplete="off">
            </div>

            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" value="<?= Utils::escape($_POST['email'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label class="form-label">Password <span class="text-danger">*</span></label>
                <input type="password" name="password" class="form-control" required autocomplete="new-password" minlength="6">
                <small class="text-muted">Minimum 6 characters.</small>
            </div>

            <div class="form-group">
                <label class="form-label">Role <span class="text-danger">*</span></label>
                <select name="role" class="form-control" id="role-select">
                    <option value="scanner" <?= (($_POST['role'] ?? '') === 'scanner') ? 'selected' : '' ?>>Scanner</option>
                    <option value="admin" <?= (($_POST['role'] ?? '') === 'admin') ? 'selected' : '' ?>>Admin</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">
                    <input type="checkbox" name="is_active" value="1" <?= isset($_POST['is_active']) || !isset($_POST['full_name']) ? 'checked' : '' ?>>
                    Active (can log in)
                </label>
            </div>

            <!-- Event assignment — only for scanner role -->
            <div class="form-group" id="event-assignment-section">
                <label class="form-label">Assign to Events</label>
                <small class="text-muted d-block mb-2">Select which events this scanner user can access. Admins have access to all events.</small>
                <?php if (empty($allEvents)): ?>
                    <p class="text-muted">No events found. <a href="../events/create.php">Create an event first</a>.</p>
                <?php else: ?>
                    <div style="max-height: 250px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 6px; padding: 12px;">
                        <?php foreach ($allEvents as $event): ?>
                        <label style="display: flex; align-items: center; gap: 8px; padding: 6px 0; cursor: pointer;">
                            <input type="checkbox" name="assigned_events[]" value="<?= $event['id'] ?>"
                                <?= in_array($event['id'], $_POST['assigned_events'] ?? []) ? 'checked' : '' ?>>
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
                    <i class="fas fa-save"></i> Create User
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
        assignSection.style.display = roleSelect.value === 'scanner' ? 'block' : 'none';
    }

    roleSelect.addEventListener('change', toggleAssign);
    toggleAssign();
})();
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../templates/layout.php';
?>
