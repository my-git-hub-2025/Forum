<?php
require __DIR__ . '/includes/forum.php';
forum_init_storage();

if (forum_current_user()) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = (string) ($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if ($password !== $confirm) {
        forum_set_flash('danger', 'Password confirmation does not match.');
    } else {
        [$ok, $result] = forum_register_user($username, $password);
        if ($ok) {
            $_SESSION['user'] = $result;
            forum_set_flash('success', 'Registered successfully. Welcome ' . $result['username'] . '!');
            header('Location: index.php');
            exit;
        }
        forum_set_flash('danger', $result);
    }
}

$flash = forum_get_flash();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register - Forum</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card">
                <div class="card-body">
                    <h4 class="mb-3">Register</h4>
                    <?php if ($flash): ?>
                        <div class="alert alert-<?= forum_h($flash['type']) ?>"><?= forum_h($flash['message']) ?></div>
                    <?php endif; ?>
                    <form method="post" id="register-form">
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input name="username" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm Password</label>
                            <input type="password" name="confirm_password" class="form-control" required>
                        </div>
                        <button class="btn btn-primary w-100" type="submit">Create Account</button>
                    </form>
                    <p class="mt-3 mb-0 text-center">
                        Already have an account? <a href="login.php">Login</a>
                    </p>
                    <p class="mt-2 mb-0 text-center">
                        <a href="index.php">Back to forum</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
    $('#register-form').on('submit', function () {
        const password = $('input[name="password"]').val();
        const confirm = $('input[name="confirm_password"]').val();
        if (password !== confirm) {
            alert('Password confirmation does not match.');
            return false;
        }
    });
</script>
</body>
</html>

