<?php

return [
    'content_enabled' => filter_var($_ENV['ADMIN_CONTENT_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN),
];
