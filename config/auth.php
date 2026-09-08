<?php

use App\Models\Owner;
use App\Models\SuperAdmin;

return [
    'defaults' => ['guard' => 'web', 'passwords' => 'owners'],
    'guards' => [
        'web' => ['driver' => 'session', 'provider' => 'owners'],
        'admin' => ['driver' => 'session', 'provider' => 'super_admins'],
    ],
    'providers' => [
        'owners' => ['driver' => 'eloquent', 'model' => Owner::class],
        'super_admins' => ['driver' => 'eloquent', 'model' => SuperAdmin::class],
    ],
    'passwords' => [
        'owners' => ['provider' => 'owners', 'table' => 'password_reset_tokens', 'connection' => 'central', 'expire' => 60, 'throttle' => 60],
        'super_admins' => ['provider' => 'super_admins', 'table' => 'admin_password_reset_tokens', 'connection' => 'central', 'expire' => 60, 'throttle' => 60],
    ],
    'password_timeout' => 10800,
];
