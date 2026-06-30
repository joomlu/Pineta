<?php
declare(strict_types=1);

return [
    'smtp' => [
        'username' => 'info@hotelpineta.bellariaigeamarina.info',
        'password' => 'Passw0rd!hotelpineta',
        'from_email' => 'info@hotelpineta.bellariaigeamarina.info',
        'from_name' => 'Hotel Pineta',
        'timeout' => 12,
        'attempts' => [
            [
                'host' => 'hotelpineta.bellariaigeamarina.info',
                'port' => 465,
                'security' => 'ssl',
            ],
            [
                'host' => 'hotelpineta.bellariaigeamarina.info',
                'port' => 587,
                'security' => 'tls',
            ],
        ],
    ],
    'lead_recipients' => [
        'info@hotelpineta.bellariaigeamarina.info',
        'info@h-pineta.com',
    ],
    'crm_storage' => __DIR__ . '/storage/requests.json',
    'admin' => [
        'username' => 'pineta',
        'password' => 'Passw0rd!',
    ],
];
