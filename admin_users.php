<?php
require __DIR__ . '/includes/forum.php';
forum_init_storage();

$user = forum_current_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    forum_set_flash('danger', 'Admin access required.');
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_user'])) {
        $username = (string) ($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $role = (string) ($_POST['role'] ?? 'user');
        [$ok, $result] = forum_admin_create_user($username, $password, $role);
        forum_set_flash($ok ? 'success' : 'danger', $ok ? 'User created.' : $result);
        header('Location: admin_users.php');
        exit;
    }

    if (isset($_POST['edit_user'])) {
        $targetUsername = (string) ($_POST['target_username'] ?? '');
        $newUsername = (string) ($_POST['username'] ?? '');
        $newRole = (string) ($_POST['role'] ?? 'user');

        if (strcasecmp($targetUsername, $user['username']) === 0) {
            forum_set_flash('danger', 'You cannot edit your own account.');
        } else {
            [$ok, $result] = forum_admin_update_user($targetUsername, $newUsername, $newRole);
            forum_set_flash($ok ? 'success' : 'danger', $ok ? 'User updated.' : $result);
        }

        header('Location: admin_users.php');
        exit;
    }

    if (isset($_POST['toggle_suspend'])) {
        $targetUsername = (string) ($_POST['target_username'] ?? '');
        $suspend = ((string) ($_POST['suspend'] ?? '0')) === '1';

        if (strcasecmp($targetUsername, $user['username']) === 0) {
            forum_set_flash('danger', 'You cannot suspend your own account.');
        } else {
            [$ok, $result] = forum_admin_set_user_suspension($targetUsername, $suspend);
            forum_set_flash($ok ? 'success' : 'danger', $ok ? ($suspend ? 'User suspended.' : 'User reactivated.') : $result);
        }

        header('Location: admin_users.php');
        exit;
    }

    if (isset($_POST['delete_user'])) {
        $targetUsername = (string) ($_POST['target_username'] ?? '');
        if (strcasecmp($targetUsername, $user['username']) === 0) {
            forum_set_flash('danger', 'You cannot delete your own account.');
        } else {
            [$ok, $result] = forum_admin_delete_user($targetUsername);
            forum_set_flash($ok ? 'success' : 'danger', $ok ? 'User deleted.' : $result);
        }
        header('Location: admin_users.php');
        exit;
    }
}

$users = forum_admin_list_users();
$flash = forum_get_flash();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Users - Forum</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/forum.css" rel="stylesheet">
</head>
<body class="app-page">
<nav class="navbar navbar-expand-lg navbar-dark app-navbar mb-4">
    <div class="container">
        <a class="navbar-brand app-title" href="index.php">Forum</a>
        <div class="ms-auto d-flex gap-2">
            <span class="text-white small align-self-center">
                <?= forum_h($user['username']) ?> (admin)
            </span>
            <a class="btn btn-outline-light btn-sm" href="index.php">Back to forum</a>
            <a class="btn btn-outline-light btn-sm" href="logout.php">Logout</a>
        </div>
    </div>
</nav>

<div class="container app-shell pb-5">
    <h3 class="mb-3">Manage Users</h3>

    <?php if ($flash): ?>
        <div class="alert alert-<?= forum_h($flash['type']) ?>"><?= forum_h($flash['message']) ?></div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header">Create User</div>
        <div class="card-body">
            <form method="post" class="row g-2">
                <div class="col-md-4">
                    <input name="username" class="form-control" placeholder="Username" required>
                </div>
                <div class="col-md-4">
                    <input type="password" name="password" class="form-control" placeholder="Password" minlength="6" required>
                </div>
                <div class="col-md-2">
                    <select name="role" class="form-select">
                        <option value="user" selected>user</option>
                        <option value="admin">admin</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-success w-100" type="submit" name="create_user" value="1">Create</button>
                </div>
            </form>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-striped table-bordered align-middle bg-white">
            <thead>
            <tr>
                <th>Username</th>
                <th>Role</th>
                <th>Status</th>
                <th>Created</th>
                <th style="min-width: 360px;">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$users): ?>
                <tr>
                    <td colspan="5" class="text-center text-muted">No users found.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($users as $listedUser): ?>
                    <?php
                    $isSelf = strcasecmp((string) $listedUser['username'], (string) $user['username']) === 0;
                    $isSuspended = forum_user_is_suspended($listedUser);
                    ?>
                    <tr>
                        <td><?= forum_h($listedUser['username']) ?></td>
                        <td><?= forum_h($listedUser['role'] ?? 'user') ?></td>
                        <td>
                            <span class="badge text-bg-<?= $isSuspended ? 'danger' : 'success' ?>">
                                <?= $isSuspended ? 'suspended' : 'active' ?>
                            </span>
                        </td>
                        <td><?= forum_h($listedUser['created_at'] ?? '-') ?></td>
                        <td>
                            <?php if ($isSelf): ?>
                                <span class="text-muted small">Your account cannot be edited or suspended here.</span>
                            <?php else: ?>
                                <form method="post" class="row g-2 mb-2">
                                    <input type="hidden" name="target_username" value="<?= forum_h($listedUser['username']) ?>">
                                    <div class="col-md-5">
                                        <input name="username" class="form-control form-control-sm"
                                               value="<?= forum_h($listedUser['username']) ?>" required>
                                    </div>
                                    <div class="col-md-3">
                                        <select name="role" class="form-select form-select-sm">
                                            <option value="user" <?= (($listedUser['role'] ?? 'user') === 'user') ? 'selected' : '' ?>>user</option>
                                            <option value="admin" <?= (($listedUser['role'] ?? 'user') === 'admin') ? 'selected' : '' ?>>admin</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <button class="btn btn-primary btn-sm w-100" type="submit" name="edit_user" value="1">
                                            Save
                                        </button>
                                    </div>
                                </form>

                                <form method="post">
                                    <input type="hidden" name="target_username" value="<?= forum_h($listedUser['username']) ?>">
                                    <input type="hidden" name="suspend" value="<?= $isSuspended ? '0' : '1' ?>">
                                    <button
                                        class="btn btn-<?= $isSuspended ? 'success' : 'warning' ?> btn-sm"
                                        type="submit"
                                        name="toggle_suspend"
                                        value="1"
                                    >
                                        <?= $isSuspended ? 'Unsuspend' : 'Suspend' ?>
                                    </button>
                                </form>
                                <form method="post" class="mt-2">
                                    <input type="hidden" name="target_username" value="<?= forum_h($listedUser['username']) ?>">
                                    <button
                                        class="btn btn-danger btn-sm"
                                        type="submit"
                                        name="delete_user"
                                        value="1"
                                        onclick="return confirm('Delete this user? This cannot be undone.');"
                                    >
                                        Delete
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
