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
$pageTitle = 'User Management';

// Handle delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $currentUserId = Auth::id();

    if ($userId && $userId !== $currentUserId) {
        $db->execute("DELETE FROM users WHERE id = :id", ['id' => $userId]);
        Session::flash('success', 'User deleted successfully.');
    } else {
        Session::flash('error', 'Cannot delete your own account.');
    }
    Utils::redirect('/users/index.php');
}

// Get all users with their assigned event count
$users = $db->query("
    SELECT 
        u.*,
        COUNT(DISTINCT eu.event_id) as assigned_events
    FROM users u
    LEFT JOIN event_users eu ON u.id = eu.user_id
    GROUP BY u.id
    ORDER BY u.role DESC, u.created_at DESC
");

ob_start();
?>

<div class="card">
    <div class="card-header">
        <h3>User Management</h3>
        <a href="create.php" class="btn btn-primary">
            <i class="fas fa-user-plus"></i> Add User
        </a>
    </div>
    <div class="card-body">
        <?php if ($flash = Session::getFlash('success')): ?>
            <div class="alert alert-success"><?= Utils::escape($flash) ?></div>
        <?php endif; ?>
        <?php if ($flash = Session::getFlash('error')): ?>
            <div class="alert alert-danger"><?= Utils::escape($flash) ?></div>
        <?php endif; ?>

        <?php if (empty($users)): ?>
            <div class="empty-state">
                <p>No users found. Add your first user to get started.</p>
                <a href="create.php" class="btn btn-primary">Add User</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Full Name</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Assigned Events</th>
                            <th>Status</th>
                            <th>Last Login</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                        <tr>
                            <td><strong><?= Utils::escape($u['full_name']) ?></strong></td>
                            <td><?= Utils::escape($u['username']) ?></td>
                            <td><?= Utils::escape($u['email'] ?? '—') ?></td>
                            <td>
                                <span class="badge badge-<?= $u['role'] === 'admin' ? 'primary' : 'secondary' ?>">
                                    <?= ucfirst($u['role']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($u['role'] === 'admin'): ?>
                                    <span class="text-muted">All events</span>
                                <?php else: ?>
                                    <?= (int) $u['assigned_events'] ?> event(s)
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-<?= $u['is_active'] ? 'success' : 'danger' ?>">
                                    <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td>
                                <?= $u['last_login'] ? Utils::formatDate($u['last_login']) : 'Never' ?>
                            </td>
                            <td>
                                <a href="edit.php?id=<?= $u['id'] ?>" class="btn btn-sm btn-secondary">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                <?php if ($u['id'] !== Auth::id()): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this user?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">
                                        <i class="fas fa-trash"></i> Delete
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
    </div>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../templates/layout.php';
?>
