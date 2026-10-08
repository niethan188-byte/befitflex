<?php
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
require_role('member');
redirect('dashboard.php');
?>
