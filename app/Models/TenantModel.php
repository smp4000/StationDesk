<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Fachmodelle benötigen immer einen expliziten Tenant-Kontext und behalten dessen Herkunft. */
abstract class TenantModel extends Model
{
    protected $guarded = ['*'];

    private ?string $originTenantId = null;

    /** Bindet auch hydratisierte Models bereits bei ihrer Entstehung an den aktiven Mandanten. */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->originTenantId = tenancy()->initialized ? (string) tenant('id') : null;
    }

    /** Verhindert auch die Wiederverwendung geladener Models nach einem Mandantenwechsel. */
    public function getConnectionName(): ?string
    {
        $currentId = tenancy()->initialized ? (string) tenant('id') : null;
        if ($currentId === null || ($this->originTenantId !== null && $this->originTenantId !== $currentId)) {
            throw new LogicException('Fachmodell ohne passenden Mandantenkontext.');
        }
        $this->originTenantId = $currentId;

        return 'tenant';
    }
}
