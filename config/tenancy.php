<?php

use App\Models\Tenant;
use App\Tenancy\TenantCacheBootstrapper;
use App\Tenancy\TenantDatabaseBootstrapper;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;
use Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager;
use Stancl\Tenancy\UUIDGenerator;

return [
    'tenant_model' => Tenant::class,
    'id_generator' => UUIDGenerator::class,
    'central_domains' => [],
    'bootstrappers' => [
        TenantCacheBootstrapper::class,
        FilesystemTenancyBootstrapper::class,
        QueueTenancyBootstrapper::class,
        TenantDatabaseBootstrapper::class,
    ],
    'database' => [
        'central_connection' => 'central', 'template_tenant_connection' => 'tenant_template',
        'prefix' => 'sd_t_', 'suffix' => '',
        'managers' => ['mysql' => MySQLDatabaseManager::class],
    ],
    'filesystem' => [
        'suffix_base' => 'tenant', 'disks' => ['local'],
        'root_override' => ['local' => '%storage_path%/app/private/'],
        'suffix_storage_path' => true, 'asset_helper_tenancy' => false,
    ],
    'features' => [], 'routes' => false,
    'migration_parameters' => ['--force' => true, '--path' => [database_path('migrations/tenant')], '--realpath' => true],
    'seeder_parameters' => [],
];
