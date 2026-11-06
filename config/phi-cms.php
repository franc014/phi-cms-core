<?php

return [
    'sections' => [],
    'pages' => [],
    'editing' => false,
    'images' => [
        'collection' => 'phi-section-images',
        'disk' => 'phi-cms-images',
        'max_bytes' => 3 * 1024 * 1024,
        'max_kilobytes' => 3 * 1024,
        'max_dimension' => 8192,
    ],
    'page_seo_images' => [
        'collection' => 'phi-page-seo-images',
        'disk' => 'phi-cms-images',
    ],
];
