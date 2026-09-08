<?php

namespace App\Models;

/** Fachliche Station im Mandantenschema; ihr Abo wird später über eine zentrale Referenz geführt. */
class Station extends TenantModel
{
    protected $keyType = 'string';

    public $incrementing = false;
}
