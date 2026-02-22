<?php

declare(strict_types=1);

return [
    'woo' => [
        'base_url' => 'https://sklep.histmag.org/',
        'consumer_key' => 'ck_YOUR_KEY',
        'consumer_secret' => 'cs_YOUR_SECRET',
        'products_status' => 'publish',
        'per_page' => 100,
        'timeout' => 30,
        'site_timezone' => 'Europe/Warsaw',
        'sales_order_statuses' => [
            'processing',
            'completed',
        ],
    ],

    'paths' => [
        'catalog_snapshots_dir' => __DIR__ . '/data/snapshots/woo_catalog',
        'periods_dir' => __DIR__ . '/data/periods',
        'monthly_report_template_xlsx' => __DIR__ . '/templates/month_template.xlsx',
    ],

    'runtime' => [
    'php_cli_bin' => '/usr/bin/php8.4',
    ],
];