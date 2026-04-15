<?php
require __DIR__ . '/includes/forum.php';
forum_init_storage();

unset($_SESSION['user']);
forum_set_flash('success', 'Logged out successfully.');
header('Location: index.php');
exit;

