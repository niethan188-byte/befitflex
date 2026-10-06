<?php
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
$u = require_role('admin');
require __DIR__ . '/../includes/notifications-page.php';
