<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

const FORUM_BASE_DIR = __DIR__ . '/../';
const FORUM_USERS_FILE = FORUM_BASE_DIR . 'users.txt';
const FORUM_DATA_DIR = FORUM_BASE_DIR . 'data';
const FORUM_DIR_MODE = 0750;
const FORUM_THREAD_ID_BYTES = 8;
const FORUM_POST_ID_BYTES = 12;

function forum_init_storage(): void
{
    if (!is_dir(FORUM_DATA_DIR)) {
        mkdir(FORUM_DATA_DIR, FORUM_DIR_MODE, true);
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
    return forum_parse_users($lines);
}

function forum_parse_users(array $lines): array
{
    $users = [];
    foreach ($lines as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded) && isset($decoded['username'], $decoded['password'], $decoded['role'])) {
            if (!isset($decoded['status'])) {
                $decoded['status'] = forum_user_is_suspended($decoded) ? 'suspended' : 'active';
            }
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

    $fp = fopen(FORUM_USERS_FILE, 'c+');
    if ($fp === false) {
        return [false, 'Unable to write user database.'];
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return [false, 'Unable to lock user database.'];
    }

    rewind($fp);
    $lockedContent = stream_get_contents($fp) ?: '';
    $users = forum_parse_users(
        $lockedContent === '' ? [] : preg_split('/\R/', $lockedContent, -1, PREG_SPLIT_NO_EMPTY)
    );
    foreach ($users as $existingUser) {
        if (strcasecmp($existingUser['username'], $username) === 0) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return [false, 'Username already exists.'];
        }
    }

    $record = [
        'username' => $username,
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'role' => count($users) === 0 ? 'admin' : 'user',
        'status' => 'active',
        'created_at' => gmdate('c'),
    ];

    fseek($fp, 0, SEEK_END);
    fwrite($fp, json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL);
    fflush($fp);
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
    $sessionUser = $_SESSION['user'] ?? null;
    if (!is_array($sessionUser) || !isset($sessionUser['username'])) {
        return null;
    }

    $freshUser = forum_find_user((string) $sessionUser['username']);
    if (!$freshUser || forum_user_is_suspended($freshUser)) {
        unset($_SESSION['user']);
        return null;
    }

    $_SESSION['user'] = $freshUser;
    return $freshUser;
}

function forum_user_is_suspended(array $user): bool
{
    if (($user['status'] ?? null) === 'suspended') {
        return true;
    }
    if (($user['suspended'] ?? null) === true) {
        return true;
    }
    return isset($user['suspended_at']) && (string) $user['suspended_at'] !== '';
}

function forum_validate_role(string $role): bool
{
    return in_array($role, ['admin', 'user'], true);
}

function forum_load_users_from_locked_file($fp): array
{
    rewind($fp);
    $lockedContent = stream_get_contents($fp) ?: '';
    return forum_parse_users(
        $lockedContent === '' ? [] : preg_split('/\R/', $lockedContent, -1, PREG_SPLIT_NO_EMPTY)
    );
}

function forum_save_users_to_locked_file($fp, array $users): bool
{
    rewind($fp);
    if (!ftruncate($fp, 0)) {
        return false;
    }

    foreach ($users as $user) {
        if (!isset($user['status'])) {
            $user['status'] = forum_user_is_suspended($user) ? 'suspended' : 'active';
        }
        $written = fwrite($fp, json_encode($user, JSON_UNESCAPED_SLASHES) . PHP_EOL);
        if ($written === false) {
            return false;
        }
    }

    return fflush($fp);
}

function forum_admin_list_users(): array
{
    $users = forum_read_users();
    usort($users, static fn(array $a, array $b): int => strcmp($a['username'], $b['username']));
    return $users;
}

function forum_admin_update_user(string $targetUsername, string $newUsername, string $newRole): array
{
    $targetUsername = trim($targetUsername);
    $newUsername = trim($newUsername);
    $newRole = trim($newRole);

    if (!preg_match('/^[A-Za-z0-9_]{3,20}$/', $newUsername)) {
        return [false, 'Username must be 3-20 characters and contain only letters, numbers, or underscore.'];
    }
    if (!forum_validate_role($newRole)) {
        return [false, 'Invalid role.'];
    }

    $fp = fopen(FORUM_USERS_FILE, 'c+');
    if ($fp === false) {
        return [false, 'Unable to write user database.'];
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return [false, 'Unable to lock user database.'];
    }

    $users = forum_load_users_from_locked_file($fp);
    $targetIndex = null;
    foreach ($users as $index => $existingUser) {
        if (strcasecmp((string) $existingUser['username'], $targetUsername) === 0) {
            $targetIndex = $index;
            break;
        }
    }

    if ($targetIndex === null) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return [false, 'User not found.'];
    }

    foreach ($users as $index => $existingUser) {
        if ($index === $targetIndex) {
            continue;
        }
        if (strcasecmp((string) $existingUser['username'], $newUsername) === 0) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return [false, 'Username already exists.'];
        }
    }

    $targetUser = $users[$targetIndex];
    $wasAdmin = ((string) ($targetUser['role'] ?? 'user')) === 'admin';
    if ($wasAdmin && $newRole !== 'admin') {
        $otherActiveAdmins = 0;
        foreach ($users as $index => $existingUser) {
            if ($index === $targetIndex) {
                continue;
            }
            if (((string) ($existingUser['role'] ?? 'user')) === 'admin' && !forum_user_is_suspended($existingUser)) {
                $otherActiveAdmins++;
            }
        }
        if ($otherActiveAdmins === 0) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return [false, 'At least one active admin is required.'];
        }
    }

    $users[$targetIndex]['username'] = $newUsername;
    $users[$targetIndex]['role'] = $newRole;
    if (!isset($users[$targetIndex]['status'])) {
        $users[$targetIndex]['status'] = forum_user_is_suspended($users[$targetIndex]) ? 'suspended' : 'active';
    }

    if (!forum_save_users_to_locked_file($fp, $users)) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return [false, 'Unable to save user changes.'];
    }

    flock($fp, LOCK_UN);
    fclose($fp);
    return [true, $users[$targetIndex]];
}

function forum_admin_set_user_suspension(string $targetUsername, bool $suspend): array
{
    $targetUsername = trim($targetUsername);
    $fp = fopen(FORUM_USERS_FILE, 'c+');
    if ($fp === false) {
        return [false, 'Unable to write user database.'];
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return [false, 'Unable to lock user database.'];
    }

    $users = forum_load_users_from_locked_file($fp);
    $targetIndex = null;
    foreach ($users as $index => $existingUser) {
        if (strcasecmp((string) $existingUser['username'], $targetUsername) === 0) {
            $targetIndex = $index;
            break;
        }
    }

    if ($targetIndex === null) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return [false, 'User not found.'];
    }

    $targetUser = $users[$targetIndex];
    if (
        $suspend
        && ((string) ($targetUser['role'] ?? 'user')) === 'admin'
        && !forum_user_is_suspended($targetUser)
    ) {
        $otherActiveAdmins = 0;
        foreach ($users as $index => $existingUser) {
            if ($index === $targetIndex) {
                continue;
            }
            if (((string) ($existingUser['role'] ?? 'user')) === 'admin' && !forum_user_is_suspended($existingUser)) {
                $otherActiveAdmins++;
            }
        }
        if ($otherActiveAdmins === 0) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return [false, 'At least one active admin is required.'];
        }
    }

    $users[$targetIndex]['status'] = $suspend ? 'suspended' : 'active';
    $users[$targetIndex]['suspended'] = $suspend;
    $users[$targetIndex]['suspended_at'] = $suspend ? gmdate('c') : null;

    if (!forum_save_users_to_locked_file($fp, $users)) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return [false, 'Unable to save user changes.'];
    }

    flock($fp, LOCK_UN);
    fclose($fp);
    return [true, $users[$targetIndex]];
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
    if (!mkdir($path, FORUM_DIR_MODE, true) && !is_dir($path)) {
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
    $threadSlug = $threadSlugBase . '-' . bin2hex(random_bytes(FORUM_THREAD_ID_BYTES));
    $threadPath = forum_category_path($categorySlug) . '/' . $threadSlug;
    if (!mkdir($threadPath, FORUM_DIR_MODE, true) && !is_dir($threadPath)) {
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
    $fileName = bin2hex(random_bytes(FORUM_POST_ID_BYTES)) . '.txt';
    $path = forum_category_path($categorySlug) . '/' . $threadSlug . '/' . $fileName;
    $encoded = json_encode($post, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return [false, 'Unable to encode post payload.'];
    }
    $ok = file_put_contents($path, $encoded, LOCK_EX);
    return ($ok === false || $ok < 1) ? [false, 'Unable to save post.'] : [true, null];
}

function forum_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
