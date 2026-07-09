<?php

return [
    'driver' => $_ENV['AUDIT_DRIVER'] ?? 'database',
    'table' => $_ENV['AUDIT_TABLE'] ?? 'game_audit_logs',
    'fallback_to_file' => filter_var($_ENV['AUDIT_FALLBACK_TO_FILE'] ?? true, FILTER_VALIDATE_BOOLEAN),
    'path' => __DIR__ . '/../logs/audit.log',
    'json_lines' => true,
];
