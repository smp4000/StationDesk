<?php

namespace App\Jobs;

use App\Tenancy\Provisioner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Zentrale Provisionierung enthält nur eine ID, niemals Passwörter oder Personaldaten im Queue-Payload. */
class ProvisionTenant implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** Bindet den Auftrag an die zentrale Queue unabhängig vom aktuellen Tenant-Kontext. */
    public function __construct(public readonly string $tenantId)
    {
        $this->onConnection('provisioning');
        $this->onQueue('provisioning');
    }

    /** Verhindert sofortige Wiederholung bei temporären Infrastrukturfehlern. */
    public function backoff(): array
    {
        return [60, 300];
    }

    /** Der Dienst serialisiert konkurrierende Versuche und speichert eine bereinigte Laufhistorie. */
    public function handle(Provisioner $provisioner): void
    {
        $provisioner->provision($this->tenantId);
    }
}
