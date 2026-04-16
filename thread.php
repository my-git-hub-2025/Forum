<?php
require __DIR__ . '/includes/forum.php';
forum_init_storage();

$user = forum_current_user();
$categorySlug = isset($_GET['c']) ? (string) $_GET['c'] : '';
$threadSlug = isset($_GET['t']) ? (string) $_GET['t'] : '';

if (!forum_is_valid_slug($categorySlug) || !forum_is_valid_slug($threadSlug)) {
    forum_set_flash('danger', 'Invalid thread URL.');
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_post'])) {
    if (!$user) {
        forum_set_flash('danger', 'Login required to reply.');
        header('Location: login.php');
        exit;
    }
    [$ok, $error] = forum_add_post($categorySlug, $threadSlug, $user['username'], (string) ($_POST['content'] ?? ''));
    forum_set_flash($ok ? 'success' : 'danger', $ok ? 'Reply posted.' : $error);
    header('Location: thread.php?c=' . urlencode($categorySlug) . '&t=' . urlencode($threadSlug));
    exit;
}

$category = forum_get_category($categorySlug);
$thread = forum_get_thread($categorySlug, $threadSlug);
if (!$category || !$thread) {
    forum_set_flash('danger', 'Thread not found.');
    header('Location: index.php');
    exit;
}

$posts = forum_list_posts($categorySlug, $threadSlug);
$flash = forum_get_flash();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= forum_h($thread['title'] ?? 'Thread') ?> - Forum</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="index.php">Forum</a>
        <div class="ms-auto d-flex gap-2">
            <?php if ($user): ?>
                <span class="text-white small align-self-center"><?= forum_h($user['username']) ?></span>
                <a class="btn btn-outline-light btn-sm" href="logout.php">Logout</a>
            <?php else: ?>
                <a class="btn btn-outline-light btn-sm" href="login.php">Login</a>
                <a class="btn btn-warning btn-sm" href="register.php">Register</a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<div class="container pb-5">
    <?php if ($flash): ?>
        <div class="alert alert-<?= forum_h($flash['type']) ?>"><?= forum_h($flash['message']) ?></div>
    <?php endif; ?>

    <a href="index.php?c=<?= urlencode($categorySlug) ?>" class="btn btn-link ps-0">&larr; Back to category</a>
    <h3 class="mb-1"><?= forum_h($thread['title'] ?? $thread['slug']) ?></h3>
    <p class="text-muted mb-4">
        Category: <?= forum_h($category['name'] ?? $categorySlug) ?>
        | By <?= forum_h($thread['created_by'] ?? 'unknown') ?>
        | <?= forum_h($thread['created_at'] ?? '-') ?>
    </p>

    <?php if (!$posts): ?>
        <div class="alert alert-secondary">No posts yet.</div>
    <?php else: ?>
        <?php foreach ($posts as $post): ?>
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between">
                    <strong><?= forum_h($post['author'] ?? 'unknown') ?></strong>
                    <small><?= forum_h($post['created_at'] ?? '-') ?></small>
                </div>
                <div class="card-body">
                    <p class="mb-0" style="white-space: pre-wrap;"><?= forum_h($post['content'] ?? '') ?></p>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($user): ?>
        <div class="card mt-4">
            <div class="card-header">Add Reply</div>
            <div class="card-body">
                <form method="post" id="reply-form">
                    <div class="mb-3">
                        <textarea class="form-control" rows="4" name="content" required></textarea>
                    </div>
                    <button class="btn btn-primary" type="submit" name="add_post" value="1">Reply</button>
                </form>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-warning mt-4">Login to reply.</div>
    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
    $('#reply-form').on('submit', function () {
        if (!$('textarea[name="content"]').val().trim()) {
            alert('Reply content is required.');
            return false;
        }
    });
</script>
</body>
</html>
