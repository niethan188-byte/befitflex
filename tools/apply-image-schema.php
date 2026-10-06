<?php
declare(strict_types=1);

require __DIR__ . '/../config/db.php';

$column = db()->query("SHOW COLUMNS FROM notifications LIKE 'image_path'")->fetch();
if (!$column) {
    db()->exec('ALTER TABLE notifications ADD COLUMN image_path VARCHAR(500) NULL AFTER icon_color');
    echo "Added notifications.image_path.\n";
} else {
    echo "notifications.image_path already exists.\n";
}

$videoColumn = db()->query("SHOW COLUMNS FROM notifications LIKE 'video_path'")->fetch();
if (!$videoColumn) {
    db()->exec('ALTER TABLE notifications ADD COLUMN video_path VARCHAR(500) NULL AFTER image_path');
    echo "Added notifications.video_path.\n";
} else {
    echo "notifications.video_path already exists.\n";
}