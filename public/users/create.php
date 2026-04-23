<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';

use App\Helpers\Auth;
use App\Helpers\Session;
use App\Helpers\Utils;
use App\Database\Connection;

Session::start();
Auth::requirePermission('users_manage');

$db = Connection::getInstance();
$pageTitle   = 'Add User';
$errors      = [];
$allEvents   = $db->query("SELECT id, event_name, event_code FROM events ORDER BY created_at DESC");
$allPerms    = Auth::PERMISSIONS;
$rolePresets = Auth::ROLE_PRESETS;
$validRoles  = array_keys($rolePresets);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName       = trim($_POST['full_name'] ?? '');
    $username       = trim($_POST['username'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $role           = $_POST['role'] ?? 'custom';
    $password       = $_POST['password'] ?? '';
    $isActive       = isset($_POST['is_active']) ? 1 : 0;
    $assignedEvents = $_POST['assigned_events'] ?? [];
    $permissions    = array_values(array_intersect(
        $_POST['permissions'] ?? [],
        array_keys($allPerms)
    ));

    if (empty($fullName))              $errors[] = 'Full name is required.';
    if (empty($username))              $errors[] = 'Username is required.';
    if (strlen($password) < 6)         $errors[] = 'Password must be at least 6 characters.';
    if (!in_array($role, $validRoles)) $errors[] = 'Invalid role.';

    if (empty($errors)) {
        $existing = $db->queryOne("SELECT id FROM users WHERE username = :u", ['u' => $username]);
        if ($existing) $errors[] = 'Username already exists.';
    }

    if (empty($errors)) {
        $userId = $db->insert(
            "INSERT INTO users (full_name, username, email, role, password, is_active, permissions)
             VALUES (:full_name,:username,:email,:role,:password,:is_active,:permissions)",
            [
                'full_name'   => $fullName,
                'username'    => $username,
                'email'       => $email ?: null,
                'role'        => $role,
                'password'    => Auth::hashPassword($password),
                'is_active'   => $isActive,
                'permissions' => json_encode($permissions),
            ]
        );

        foreach ($assignedEvents as $eid) {
            $eid = (int) $eid;
            if ($eid > 0) {
                $db->execute(
                    "INSERT IGNORE INTO event_users (event_id, user_id, assigned_by)
                     VALUES (:event_id,:user_id,:by)",
                    ['event_id' => $eid, 'user_id' => $userId, 'by' => Auth::id()]
                );
            }
        }

        Session::flash('success', "User '{$fullName}' created successfully.");
        Utils::redirect('/users/index.php');
    }
}

$selectedPerms = $_POST['permissions'] ?? $rolePresets['scanner']['permissions'];

ob_start();
?>

<div class="card" style="max-width:780px;margin:0 auto;">
    <div class="card-header">
        <h3>Add New User</h3>
        <a href="index.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
    <div class="card-body">
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul style="margin:0;padding-left:20px;">
                    <?php foreach ($errors as $e): ?><li><?= Utils::escape($e) ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST">

            <!-- ── Basic Info ── -->
            <div class="form-group">
                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                <input type="text" name="full_name" class="form-control"
                    value="<?= Utils::escape($_POST['full_name'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Username <span class="text-danger">*</span></label>
                <input type="text" name="username" class="form-control"
                    value="<?= Utils::escape($_POST['username'] ?? '') ?>" required autocomplete="off">
            </div>
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control"
                    value="<?= Utils::escape($_POST['email'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Password <span class="text-danger">*</span></label>
                <input type="password" name="password" class="form-control"
                    required autocomplete="new-password" minlength="6">
                <small class="text-muted">Minimum 6 characters.</small>
            </div>
            <div class="form-group">
                <label class="form-label" style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="is_active" value="1"
                        <?= (!isset($_POST['full_name']) || isset($_POST['is_active'])) ? 'checked' : '' ?>>
                    Active (can log in)
                </label>
            </div>

            <!-- ── Role Presets ── -->
            <div class="form-group" style="border-top:1px solid #e5e7eb;padding-top:20px;margin-top:8px;">
                <label class="form-label">Role Preset <span class="text-danger">*</span></label>
                <small class="text-muted d-block" style="margin-bottom:10px;">
                    Pick a preset to auto-fill permissions, then fine-tune below if needed.
                </small>
                <div style="display:flex;flex-wrap:wrap;gap:8px;">
                    <?php foreach ($rolePresets as $key => $preset): ?>
                    <button type="button" class="btn btn-sm btn-outline role-preset-btn"
                        data-role="<?= $key ?>"
                        data-perms='<?= htmlspecialchars(json_encode($preset['permissions']), ENT_QUOTES) ?>'>
                        <?= Utils::escape($preset['label']) ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="role" id="role-input"
                    value="<?= Utils::escape($_POST['role'] ?? 'scanner') ?>">
            </div>

            <!-- ── Permissions ── -->
            <div class="form-group">
                <label class="form-label">Permissions</label>
                <small class="text-muted d-block" style="margin-bottom:10px;">
                    Tick every page / feature this user is allowed to access.
                </small>
                <div style="border:1px solid #e5e7eb;border-radius:6px;padding:16px;
                            display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <?php foreach ($allPerms as $key => $label): ?>
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                        <input type="checkbox" name="permissions[]" value="<?= $key ?>"
                            class="perm-checkbox"
                            <?= in_array($key, $selectedPerms) ? 'checked' : '' ?>>
                        <span><?= Utils::escape($label) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ── Event Assignment ── -->
            <div class="form-group" style="border-top:1px solid #e5e7eb;padding-top:20px;margin-top:8px;">
                <label class="form-label">Assign to Events</label>
                <small class="text-muted d-block" style="margin-bottom:10px;">
                    Restrict this user to specific events. Leave all unchecked only if the user
                    has <em>Manage Users &amp; Permissions</em> and should see every event.
                </small>
                <?php if (empty($allEvents)): ?>
                    <p class="text-muted">No events yet.
                        <a href="../events/create.php">Create an event first</a>.</p>
                <?php else: ?>
                    <div style="max-height:260px;overflow-y:auto;border:1px solid #e5e7eb;
                                border-radius:6px;padding:12px;">
                        <?php foreach ($allEvents as $ev): ?>
                        <label style="display:flex;align-items:center;gap:8px;
                                      padding:6px 0;cursor:pointer;">
                            <input type="checkbox" name="assigned_events[]"
                                value="<?= $ev['id'] ?>"
                                <?= in_array($ev['id'], $_POST['assigned_events'] ?? []) ? 'checked' : '' ?>>
                            <span>
                                <strong><?= Utils::escape($ev['event_name']) ?></strong>
                                <code style="font-size:12px;margin-left:4px;">
                                    <?= Utils::escape($ev['event_code']) ?>
                                </code>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div style="display:flex;gap:12px;margin-top:24px;">
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
    var roleInput  = document.getElementById('role-input');
    var checkboxes = document.querySelectorAll('.perm-checkbox');
    var presetBtns = document.querySelectorAll('.role-preset-btn');

    function clearActive() {
        presetBtns.forEach(function (b) {
            b.classList.remove('btn-primary');
            b.classList.add('btn-outline');
        });
    }

    presetBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var perms = JSON.parse(btn.getAttribute('data-perms'));
            roleInput.value = btn.getAttribute('data-role');
            checkboxes.forEach(function (cb) {
                cb.checked = perms.indexOf(cb.value) !== -1;
            });
            clearActive();
            btn.classList.remove('btn-outline');
            btn.classList.add('btn-primary');
        });

        // Highlight the currently selected role on page load
        if (btn.getAttribute('data-role') === roleInput.value) {
            btn.classList.remove('btn-outline');
            btn.classList.add('btn-primary');
        }
    });

    // If user manually ticks/unticks, switch to "custom"
    checkboxes.forEach(function (cb) {
        cb.addEventListener('change', function () {
            roleInput.value = 'custom';
            clearActive();
        });
    });
})();
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../templates/layout.php';
?>
