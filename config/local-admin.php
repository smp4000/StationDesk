<?php

// Nur der ausdrücklich aufgerufene lokale Seeder verwendet diese privaten Einstellungen.
return [
    'name' => env('LOCAL_SUPER_ADMIN_NAME'),
    'email' => env('LOCAL_SUPER_ADMIN_EMAIL'),
    'password' => env('LOCAL_SUPER_ADMIN_PASSWORD'),
];
