<?php
define('IN_SUBDIR', true);
require __DIR__ . '/../includes/auth.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST required.');
}
csrf_check();

$binary = getenv('MYSQLDUMP_PATH') ?: 'mysqldump';
$command = escapeshellarg($binary)
    . ' --host=' . escapeshellarg(DB['host'])
    . ' --port=' . escapeshellarg(DB['port'])
    . ' --user=' . escapeshellarg(DB['user']);
if (DB['pass'] !== '') {
    $command .= ' --password=' . escapeshellarg(DB['pass']);
}
$command .= ' --single-transaction --routines --triggers ' . escapeshellarg(DB['name']);

$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$process = proc_open($command, $descriptors, $pipes);
if (!is_resource($process)) {
    http_response_code(500);
    exit('Unable to start mysqldump. Set MYSQLDUMP_PATH if it is not on PATH.');
}
$sql = stream_get_contents($pipes[1]);
$error = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$status = proc_close($process);

if ($status !== 0 || $sql === '') {
    error_log('Database backup failed: ' . trim($error));
    http_response_code(500);
    exit('Database backup failed. Check the server error log.');
}

log_activity('Database backup', 'System', 'Downloaded a consistent MySQL snapshot');
header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="befitflex-backup-' . date('Y-m-d-His') . '.sql"');
header('Content-Length: ' . strlen($sql));
echo $sql;