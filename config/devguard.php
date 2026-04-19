<?php

declare(strict_types=1);

return [
    'tools' => [
        'deploy' => [
            'enabled' => true,
            'fail_on' => 'fail',
            'checks' => [
                'env_file_exists' => ['impact' => 20],
                'debug_mode' => ['impact' => 15],
                'cache_configured' => ['impact' => 10],
                'queue_configured' => ['impact' => 10],
                'rate_limit' => ['impact' => 10],
                'https_enforced' => ['impact' => 10],
                'logging_configured' => ['impact' => 5],
            ],
        ],
        'architecture' => [
            'enabled' => true,
            'rules' => [
                'fat_controller' => ['max_lines' => 300],
                'service_layer' => ['path' => 'app/Services'],
                'repository_layer' => ['path' => 'app/Repositories'],
            ],
        ],
    ],
    'output' => [
        'colors' => true,
        'icons' => true,
    ],
];
