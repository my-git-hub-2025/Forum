<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

const FORUM_BASE_DIR = __DIR__ . '/../';
const FORUM_USERS_FILE = FORUM_BASE_DIR . 'users.txt';
const FORUM_DATA_DIR = FORUM_BASE_DIR . 'data';

function forum_init_storage(): void
{
    if (!is_dir(FORUM_DATA_DIR)) {
        mkdir(FORUM_DATA_DIR, 0755, true);
    }

    if (!file_exists(FORUM_USERS_FILE)) {
        file_put_contents(FORUM_USERS_FILE, '');
    }
}

function forum_slug(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9_-]+/', '-', $value) ?? '';
    $value = trim($value, '-_');
    return $value;
}

function forum_is_valid_slug(string $value): bool
{
    return $value !== '' && forum_slug($value) === $value;
}

function forum_read_users(): array
{
    forum_init_storage();
    $lines = file(FORUM_USERS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $users = [];
    foreach ($lines as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded) && isset($decoded['username'], $decoded['password'], $decoded['role'])) {
            $users[] = $decoded;
        }
    }
    return $users;
}

function forum_find_user(string $username): ?array
{
    foreach (forum_read_users() as $user) {
        if (strcasecmp($user['username'], $username) === 0) {
            return $user;
        }
    }
    return null;
}

function forum_register_user(string $username, string $password): array
{
    $username = trim($username);
    if (!preg_match('/^[A-Za-z0-9_]{3,20}$/', $username)) {
        return [false, 'Username must be 3-20 characters and contain only letters, numbers, or underscore.'];
    }

    if (strlen($password) < 6) {
        return [false, 'Password must be at least 6 characters.'];
    }

    if (forum_find_user($username)) {
        return [false, 'Username already exists.'];
    }

    $users = forum_read_users();
    $role = count($users) === 0 ? 'admin' : 'user';

    $record = [
        'username' => $username,
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'role' => $role,
        'created_at' => gmdate('c'),
    ];

    $fp = fopen(FORUM_USERS_FILE, 'ab');
    if ($fp === false) {
        return [false, 'Unable to write user database.'];
    }

    flock($fp, LOCK_EX);
    fwrite($fp, json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL);
    flock($fp, LOCK_UN);
    fclose($fp);

    return [true, $record];
}

function forum_authenticate(string $username, string $password): ?array
{
    $user = forum_find_user($username);
    if (!$user) {
        return null;
    }

    return password_verify($password, $user['password']) ? $user : null;
}

function forum_current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function forum_set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function forum_get_flash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function forum_category_path(string $slug): string
{
    return FORUM_DATA_DIR . '/' . $slug;
}

function forum_list_categories(): array
{
    forum_init_storage();
    $items = [];
    foreach (scandir(FORUM_DATA_DIR) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = forum_category_path($entry);
        if (!is_dir($path)) {
            continue;
        }
        $metaFile = $path . '/category.json';
        $meta = file_exists($metaFile) ? json_decode((string) file_get_contents($metaFile), true) : null;
        if (!is_array($meta)) {
            $meta = ['slug' => $entry, 'name' => $entry, 'created_at' => null, 'created_by' => 'unknown'];
        }
        $meta['slug'] = $entry;
        $items[] = $meta;
    }
    usort($items, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
    return $items;
}

function forum_get_category(string $slug): ?array
{
    if (!forum_is_valid_slug($slug)) {
        return null;
    }
    $path = forum_category_path($slug);
    if (!is_dir($path)) {
        return null;
    }
    $metaFile = $path . '/category.json';
    if (!file_exists($metaFile)) {
        return ['slug' => $slug, 'name' => $slug];
    }
    $meta = json_decode((string) file_get_contents($metaFile), true);
    return is_array($meta) ? $meta + ['slug' => $slug] : ['slug' => $slug, 'name' => $slug];
}

function forum_create_category(string $name, string $createdBy): array
{
    $name = trim($name);
    if ($name === '') {
        return [false, 'Category name is required.'];
    }
    $slug = forum_slug($name);
    if ($slug === '') {
        return [false, 'Invalid category name.'];
    }
    $path = forum_category_path($slug);
    if (file_exists($path)) {
        return [false, 'Category already exists.'];
    }
    if (!mkdir($path, 0755, true) && !is_dir($path)) {
        return [false, 'Unable to create category folder.'];
    }

    $meta = [
        'slug' => $slug,
        'name' => $name,
        'created_by' => $createdBy,
        'created_at' => gmdate('c'),
    ];
    file_put_contents($path . '/category.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return [true, $meta];
}

function forum_list_threads(string $categorySlug): array
{
    if (!forum_is_valid_slug($categorySlug)) {
        return [];
    }
    $catPath = forum_category_path($categorySlug);
    if (!is_dir($catPath)) {
        return [];
    }

    $threads = [];
    foreach (scandir($catPath) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === 'category.json') {
            continue;
        }
        $threadPath = $catPath . '/' . $entry;
        if (!is_dir($threadPath)) {
            continue;
        }
        $metaFile = $threadPath . '/thread.json';
        if (!file_exists($metaFile)) {
            continue;
        }
        $meta = json_decode((string) file_get_contents($metaFile), true);
        if (!is_array($meta)) {
            continue;
        }
        $posts = glob($threadPath . '/*.txt') ?: [];
        $meta['slug'] = $entry;
        $meta['post_count'] = count($posts);
        $threads[] = $meta;
    }
    usort(
        $threads,
        static fn(array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''))
    );
    return $threads;
}

function forum_get_thread(string $categorySlug, string $threadSlug): ?array
{
    if (!forum_is_valid_slug($categorySlug) || !forum_is_valid_slug($threadSlug)) {
        return null;
    }
    $metaFile = forum_category_path($categorySlug) . '/' . $threadSlug . '/thread.json';
    if (!file_exists($metaFile)) {
        return null;
    }
    $meta = json_decode((string) file_get_contents($metaFile), true);
    return is_array($meta) ? $meta + ['slug' => $threadSlug] : null;
}

function forum_create_thread(string $categorySlug, string $title, string $author, string $firstPost): array
{
    if (!forum_get_category($categorySlug)) {
        return [false, 'Category not found.'];
    }
    $title = trim($title);
    $firstPost = trim($firstPost);
    if ($title === '' || $firstPost === '') {
        return [false, 'Thread title and content are required.'];
    }

    $threadSlugBase = forum_slug($title);
    if ($threadSlugBase === '') {
        $threadSlugBase = 'thread';
    }
    $threadSlug = $threadSlugBase . '-' . bin2hex(random_bytes(8));
    $threadPath = forum_category_path($categorySlug) . '/' . $threadSlug;
    if (!mkdir($threadPath, 0755, true) && !is_dir($threadPath)) {
        return [false, 'Unable to create thread folder.'];
    }

    $meta = [
        'slug' => $threadSlug,
        'title' => $title,
        'created_by' => $author,
        'created_at' => gmdate('c'),
    ];
    file_put_contents($threadPath . '/thread.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    [$ok, $error] = forum_add_post($categorySlug, $threadSlug, $author, $firstPost);
    if (!$ok) {
        return [false, $error];
    }
    return [true, $meta];
}

function forum_list_posts(string $categorySlug, string $threadSlug): array
{
    if (!forum_is_valid_slug($categorySlug) || !forum_is_valid_slug($threadSlug)) {
        return [];
    }
    $threadPath = forum_category_path($categorySlug) . '/' . $threadSlug;
    if (!is_dir($threadPath)) {
        return [];
    }
    $posts = [];
    foreach (glob($threadPath . '/*.txt') ?: [] as $postFile) {
        $post = json_decode((string) file_get_contents($postFile), true);
        if (!is_array($post)) {
            continue;
        }
        $post['file'] = basename($postFile);
        $posts[] = $post;
    }
    usort(
        $posts,
        static fn(array $a, array $b): int => strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? ''))
    );
    return $posts;
}

function forum_add_post(string $categorySlug, string $threadSlug, string $author, string $content): array
{
    if (!forum_get_thread($categorySlug, $threadSlug)) {
        return [false, 'Thread not found.'];
    }
    $content = trim($content);
    if ($content === '') {
        return [false, 'Post content is required.'];
    }
    $post = [
        'author' => $author,
        'content' => $content,
        'created_at' => gmdate('c'),
    ];
    $fileName = bin2hex(random_bytes(12)) . '.txt';
    $path = forum_category_path($categorySlug) . '/' . $threadSlug . '/' . $fileName;
    $ok = file_put_contents($path, json_encode($post, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return $ok === false ? [false, 'Unable to save post.'] : [true, null];
}

function forum_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
