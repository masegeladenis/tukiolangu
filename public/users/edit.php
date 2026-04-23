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
$pageTitle   = 'Edit User';
$errors      = [];
$allPerms    = Auth::PERMISSIONS;
$rolePresets = Auth::ROLE_PRESETS;
$validRoles  = array_keys($rolePresets);

$userId = (int) ($_GET['id'] ?? 0);
if (!$userId) Utils::redirect('/users/index.php');

$editUser = $db->queryOne("SELECT * FROM users WHERE id = :id", ['id' => $userId]);
if (!$editUser) {
    Session::flash('error', 'User not found.');
    Utils::redirect('/users/index.php');
}

$allEvents       = $db->query("SELECT id, event_name, event_code FROM events ORDER BY created_at DESC");
$assignedRows    = $db->query("SELECT event_id FROM event_users WHERE user_id = :uid", ['uid' => $userId]);
$currentAssigned = array_map('intval', array_column($assignedRows, 'event_id'));

// Current permissions from DB
$dbPerms = [];
if (!empty($editUser['permissions'])) {
    $dec = json_decode($editUser['permissions'], true);
    if (is_array($dec)) $dbPerms = $dec;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName       = trim($_POST['full_name'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $role           = $_POST['role'] ?? 'custom';
    $isActive       = isset($_POST['is_active']) ? 1 : 0;
    $newPassword    = $_POST['password'] ?? '';
    $assignedEvents = $_POST['assigned_events'] ?? [];
    $permissions    = array_values(array_intersect(
        $_POST['permissions'] ?? [],
        array_keys($allPerms)
    ));

    if (empty($fullName))              $errors[] = 'Full name is required.';
    if (!in_array($role, $validRoles)) $errors[] = 'Invalid role.';
    if (!empty($newPassword) && strlen($newPassword) < 6) $errors[] = 'Password must be at least 6 characters.';

    if (empty($errors)) {
        $params = [
            'full_name'   => $fullName,
            'email'       => $email ?: null,
            'role'        => $role,
            'is_active'   => $isActive,
            'permissions' => json_encode($permissions),
            'id'          => $userId,
        ];

        $pwSql = '';
        if (!empty($newPassword)) {
            $pwSql = ', password = :password';
            $params['password'] = Auth::hashPassword($newPassword);
        }

        $db->execute(
            "UPDATE users SET full_name=:full_name, email=:email, role=:role,
             is_active=:is_active, permissions=:permissions{$pwSql} WHERE id=:id",
            $params
        );

        // Sync event assignments
        $db->execute("DELETE FROM event_users WHERE user_id = :uid", ['uid' => $userId]);
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

        Session::flash('success', "User '{$fullName}' updated successfully.");
        Utils::redirect('/users/index.php');
    }

    // Repopulate on validation failure
    $editUser        = array_merge($editUser, ['full_name' => $fullName, 'email' => $email,
                                               'role' => $role, 'is_active' => $isActive]);
    $currentAssigned = array_map('intval', $assignedEvents);
    $dbPerms         = $permissions;
}

$selectedPerms = $dbPerms;

ob_start();
?>

<div class="card" style="max-width:780px;margin:0 auto;">
    <div class="card-header">
        <h3>Edit User: <?= Utils::escape($editUser['full_name']) ?></h3>
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
                    value="<?= Utils::escape($editUser['full_name']) ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Username</label>
                <input type="text" class="form-control"
                    value="<?= Utils::escape($editUser['username']) ?>" disabled>
                <small class="text-muted">Username cannot be changed.</small>
            </div>
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control"
                    value="<?= Utils::escape($editUser['email'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">New Password</label>
                <input type="password" name="password" class="form-control"
                    autocomplete="new-password" minlength="6">
                <small class="text-muted">Leave blank to keep current password.</small>
            </div>
            <div class="form-group">
                <label class="form-label" style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="is_active" value="1"
                        <?= $editUser['is_active'] ? 'checked' : '' ?>>
                    Active (can log in)
                </label>
            </div>

            <!-- ── Role Presets ── -->
            <div class="form-group" style="border-top:1px solid #e5e7eb;padding-top:20px;margin-top:8px;">
                <label class="form-label">Role Preset</label>
                <small class="text-muted d-block" style="margin-bottom:10px;">
                    Choose a preset to reset permissions, or adjust the checkboxes individually.
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
                <?php $isSelf = ($editUser['id'] === Auth::id()); ?>
                <input type="hidden" name="role" id="role-input"
                    value="<?= Utils::escape($editUser['role']) ?>"
                    <?= $isSelf ? 'disabled' : '' ?>>
                <?php if ($isSelf): ?>
                    <input type="hidden" name="role" value="<?= Utils::escape($editUser['role']) ?>">
                    <small class="text-muted">You cannot change your own role.</small>
                <?php endif; ?>
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
                            <?= in_array($key, $selectedPerms) ? 'checked' : '' ?>
                            <?= ($isSelf && $key === 'users_manage') ? 'disabled' : '' ?>>
                        <span><?= Utils::escape($label) ?></span>
                    </label>
                    <?php endforeach; ?>
                    <?php if ($isSelf): ?>
                        <!-- Preserve users_manage for self even if checkbox disabled -->
                        <input type="hidden" name="permissions[]" value="users_manage">
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── Event Assignment ── -->
            <div class="form-group" style="border-top:1px solid #e5e7eb;padding-top:20px;margin-top:8px;">
                <label class="form-label">Assigned Events</label>
                <small class="text-muted d-block" style="margin-bottom:10px;">
                    Restrict this user to specific events. Leave all unchecked only if the user
                    has <em>Manage Users &amp; Permissions</em> and should see every event.
                </small>
                <?php if (empty($allEvents)): ?>
                    <p class="text-muted">No events found.</p>
                <?php else: ?>
                    <div style="max-height:280px;overflow-y:auto;border:1px solid #e5e7eb;
                                border-radius:6px;padding:12px;">
                        <?php foreach ($allEvents as $ev): ?>
                        <label style="display:flex;align-items:center;gap:8px;
                                      padding:6px 0;cursor:pointer;">
                            <input type="checkbox" name="assigned_events[]"
                                value="<?= $ev['id'] ?>"
                                <?= in_array((int)$ev['id'], $currentAssigned) ? 'checked' : '' ?>>
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
                    <i class="fas fa-save"></i> Save Changes
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
            if (!roleInput || roleInput.disabled) return;
            var perms = JSON.parse(btn.getAttribute('data-perms'));
            roleInput.value = btn.getAttribute('data-role');
            checkboxes.forEach(function (cb) {
                if (!cb.disabled) cb.checked = perms.indexOf(cb.value) !== -1;
            });
            clearActive();
            btn.classList.remove('btn-outline');
            btn.classList.add('btn-primary');
        });

        if (roleInput && btn.getAttribute('data-role') === roleInput.value) {
            btn.classList.remove('btn-outline');
            btn.classList.add('btn-primary');
        }
    });

    checkboxes.forEach(function (cb) {
        cb.addEventListener('change', function () {
            if (roleInput && !roleInput.disabled) roleInput.value = 'custom';
            clearActive();
        });
    });
})();
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../templates/layout.php';
?>
