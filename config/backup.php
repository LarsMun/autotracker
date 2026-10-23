<?php

return [
    // A local disk whose root is the NAS mount inside the container (config/filesystems.php).
    'disk' => 'backups',

    'keep_days' => (int) env('BACKUP_KEEP_DAYS', 30),
];
