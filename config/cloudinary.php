<?php

return [
    'url' => $_ENV['CLOUDINARY_URL'] ?? '',
    'default_folder' => $_ENV['CLOUDINARY_DEFAULT_FOLDER'] ?? 'gitbook',
    'max_size_mb' => (int) ($_ENV['CLOUDINARY_MAX_SIZE_MB'] ?? 5),
    'allowed_types' => array_filter(array_map(
        'trim',
        explode(',', $_ENV['CLOUDINARY_ALLOWED_TYPES'] ?? 'image/jpeg,image/png,image/webp,image/gif')
    )),
    'upload_defaults' => [
        'resource_type' => 'image',
        'quality_analysis' => true,
        'colors' => false,
        'phash' => false,
        'overwrite' => false,
        'invalidate' => true,
        'use_filename' => true,
        'unique_filename' => true,
    ],
    'transformations' => [
        'thumb' => ['width' => 160, 'height' => 160, 'gravity' => 'auto', 'crop' => 'fill', 'format' => 'auto', 'quality' => 'auto'],
        'card' => ['width' => 640, 'height' => 360, 'gravity' => 'auto', 'crop' => 'fill', 'format' => 'auto', 'quality' => 'auto'],
        'hero' => ['width' => 1600, 'height' => 900, 'gravity' => 'auto', 'crop' => 'fill', 'format' => 'auto', 'quality' => 'auto'],
        'original' => ['format' => 'auto', 'quality' => 'auto'],
    ],
    'responsive_widths' => [320, 480, 640, 960, 1280, 1600],
];
