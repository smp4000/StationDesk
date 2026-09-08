<?php

namespace App\Models;

/** Minimaler Chef-Datensatz für P01; sensible Personalfelder gehören erst zum Personalmodul. */
class Employee extends TenantModel
{
    protected $keyType = 'string';

    public $incrementing = false;
}
