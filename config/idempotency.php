<?php

return [
    'driver' => $_ENV['IDEMPOTENCY_DRIVER'] ?? 'database',
    'ttl' => (int) ($_ENV['IDEMPOTENCY_TTL_SECONDS'] ?? 86400),
    'header' => $_ENV['IDEMPOTENCY_HEADER'] ?? 'Idempotency-Key',
    'table' => $_ENV['IDEMPOTENCY_TABLE'] ?? 'idempotency_keys',
];
