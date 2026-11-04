<?php
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
$u = require_role('admin');

flash('The session scheduling feature has been removed from the admin area.');
redirect('dashboard.php');
