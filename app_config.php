<?php
declare(strict_types=1);

return [
    'smtp' => [
        'host' => 'hotelpineta.bellariaigeamarina.info',
        'port' => 465,
        'username' => 'info@hotelpineta.bellariaigeamarina.info',
        'password' => 'Passw0rd!hotelpineta',
        'from_email' => 'info@hotelpineta.bellariaigeamarina.info',
        'from_name' => 'Hotel Pineta',
        'timeout' => 30,
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
