<?php
require __DIR__ . '/includes/forum.php';
forum_init_storage();

$user = forum_current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_category'])) {
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            forum_set_flash('danger', 'Only admin can create categories.');
        } else {
            [$ok, $result] = forum_create_category($_POST['category_name'] ?? '', $user['username']);
            forum_set_flash($ok ? 'success' : 'danger', $ok ? 'Category created.' : $result);
        }
        header('Location: index.php');
        exit;
    }

    if (isset($_POST['create_thread'])) {
        if (!$user) {
            forum_set_flash('danger', 'Login required to create thread.');
            header('Location: login.php');
            exit;
        }
        $categorySlug = (string) ($_POST['category_slug'] ?? '');
        [$ok, $result] = forum_create_thread(
            $categorySlug,
            (string) ($_POST['thread_title'] ?? ''),
            $user['username'],
            (string) ($_POST['thread_content'] ?? '')
        );
        if ($ok) {
            forum_set_flash('success', 'Thread created.');
            header('Location: thread.php?c=' . urlencode($categorySlug) . '&t=' . urlencode($result['slug']));
            exit;
        }
        forum_set_flash('danger', $result);
        header('Location: index.php?c=' . urlencode($categorySlug));
        exit;
    }
}

$categories = forum_list_categories();
$selectedCategorySlug = isset($_GET['c']) && forum_is_valid_slug((string) $_GET['c']) ? (string) $_GET['c'] : null;
$selectedCategory = $selectedCategorySlug ? forum_get_category($selectedCategorySlug) : null;
$threads = $selectedCategory ? forum_list_threads($selectedCategorySlug) : [];
$flash = forum_get_flash();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forum</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="index.php">Forum</a>
        <div class="ms-auto d-flex align-items-center gap-2">
            <?php if ($user): ?>
                <span class="text-white small" aria-label="Current user: <?= forum_h($user['username']) ?>">
                    Logged in as <strong><?= forum_h($user['username']) ?></strong>
                    (<?= forum_h($user['role'] ?? 'user') ?>)
                </span>
                <?php if (($user['role'] ?? '') === 'admin'): ?>
                    <a class="btn btn-outline-info btn-sm" href="admin_users.php">Manage Users</a>
                <?php endif; ?>
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

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">Categories</div>
                <div class="list-group list-group-flush">
                    <?php if (!$categories): ?>
                        <div class="list-group-item text-muted">No categories yet.</div>
                    <?php else: ?>
                        <?php foreach ($categories as $category): ?>
                            <?php $isActive = $selectedCategorySlug === $category['slug']; ?>
                            <a class="list-group-item list-group-item-action <?= $isActive ? 'active' : '' ?>"
                               href="index.php?c=<?= urlencode($category['slug']) ?>">
                                <?= forum_h($category['name'] ?? $category['slug']) ?>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($user && ($user['role'] ?? '') === 'admin'): ?>
                <div class="card mt-3">
                    <div class="card-header">Create Category (Admin)</div>
                    <div class="card-body">
                        <form method="post">
                            <div class="mb-3">
                                <label class="form-label">Category Name</label>
                                <input name="category_name" class="form-control" required>
                            </div>
                            <button type="submit" name="create_category" value="1" class="btn btn-primary">Create</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="col-lg-8">
            <?php if (!$selectedCategory): ?>
                <div class="alert alert-info">Choose a category to view threads.</div>
            <?php else: ?>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4 class="mb-0"><?= forum_h($selectedCategory['name'] ?? $selectedCategory['slug']) ?></h4>
                </div>

                <?php if (!$threads): ?>
                    <div class="alert alert-secondary">No threads in this category.</div>
                <?php else: ?>
                    <div class="list-group mb-4">
                        <?php foreach ($threads as $thread): ?>
                            <a class="list-group-item list-group-item-action"
                               href="thread.php?c=<?= urlencode($selectedCategorySlug) ?>&t=<?= urlencode($thread['slug']) ?>">
                                <div class="d-flex w-100 justify-content-between">
                                    <strong><?= forum_h($thread['title'] ?? $thread['slug']) ?></strong>
                                    <small><?= forum_h((string) ($thread['post_count'] ?? 0)) ?> posts</small>
                                </div>
                                <small class="text-muted">
                                    By <?= forum_h($thread['created_by'] ?? 'unknown') ?>
                                    at <?= forum_h($thread['created_at'] ?? '-') ?>
                                </small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($user): ?>
                    <div class="card">
                        <div class="card-header">Create Thread</div>
                        <div class="card-body">
                            <form method="post" id="thread-form">
                                <input type="hidden" name="category_slug" value="<?= forum_h($selectedCategorySlug) ?>">
                                <div class="mb-3">
                                    <label class="form-label">Title</label>
                                    <input class="form-control" name="thread_title" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">First Post</label>
                                    <textarea class="form-control" rows="4" name="thread_content" required></textarea>
                                </div>
                                <button type="submit" name="create_thread" value="1" class="btn btn-success">Post Thread</button>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning">Login to create a thread.</div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
    $('#thread-form').on('submit', function () {
        const title = $('input[name="thread_title"]').val().trim();
        const content = $('textarea[name="thread_content"]').val().trim();
        if (!title || !content) {
            alert('Title and first post are required.');
            return false;
        }
    });
</script>
</body>
</html>
