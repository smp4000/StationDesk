<?php

namespace App\Billing;

use App\Models\Owner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Stationsabos des angemeldeten Owners; Kündigungen sind vorerst ausschließlich für lokale Testverträge freigegeben. */
class ManageSubscriptions
{
    /** Ermittelt die Zuordnung erneut aus der Datenbank, niemals aus einem übergebenen Mandantenfeld. */
    public function owner(): Owner
    {
        $identity = auth('web')->user();
        abort_unless($identity instanceof Owner, 403);
        $owner = Owner::query()->findOrFail($identity->id);
        abort_unless($owner->hasVerifiedEmail() && $owner->tenant()->value('provisioning_status') === 'ready', 403);
        abort_if(tenancy()->initialized && (string) tenant()->getTenantKey() !== $owner->tenant_id, 403);

        return $owner;
    }

    /** Zeigt gespeicherte Preise und errechnete Zeiträume; Lesen erzeugt keine Rechnung und keinen Einzug. */
    public function all(): array
    {
        $owner = $this->owner();

        return DB::connection('central')->table('subscriptions')->where('tenant_id', $owner->tenant_id)->orderBy('id')->get()
            ->map(fn ($subscription) => $this->describe($subscription))->all();
    }

    /** Liefert den konkreten Endtermin für den Bestätigungsdialog eines eigenen Testabos. */
    public function quote(int $id): array
    {
        $owner = $this->owner();
        $subscription = DB::connection('central')->table('subscriptions')->where('tenant_id', $owner->tenant_id)->where('id', $id)->first();
        abort_unless($subscription, 404);
        $this->assertTestCancellationAllowed($subscription);

        return $this->describe($subscription);
    }

    /** Sperrt die Vertragszeile, prüft den angezeigten Termin erneut und schreibt Kündigung samt Audit genau einmal. */
    public function cancel(int $id, string $expectedEnd): array
    {
        $owner = $this->owner();

        return DB::connection('central')->transaction(function () use ($owner, $id, $expectedEnd): array {
            $db = DB::connection('central');
            $subscription = $db->table('subscriptions')->where('tenant_id', $owner->tenant_id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($subscription, 404);
            $this->assertTestCancellationAllowed($subscription);
            $view = $this->describe($subscription);
            if ($subscription->cancelled_at !== null) {
                return $view;
            }
            if ($view['cancellation_end'] !== $expectedEnd) {
                throw ValidationException::withMessages(['cancellation' => 'Die aktuelle Periode hat sich geändert. Bitte den Dialog schließen und den neuen Kündigungstermin prüfen.']);
            }
            $time = now();
            $db->table('subscription_cancellations')->insert([
                'subscription_id' => $id, 'tenant_id' => $owner->tenant_id, 'requested_by' => $owner->id,
                'requested_at' => $time, 'effective_at' => $view['cancellation_end'],
            ]);
            $db->table('subscriptions')->where('id', $id)->update([
                'cancelled_at' => $time, 'ends_at' => $view['cancellation_end'], 'updated_at' => $time,
            ]);
            $db->table('audit_events')->insert([
                'tenant_id' => $owner->tenant_id, 'actor_type' => 'owner', 'actor_id' => (string) $owner->id,
                'action' => 'subscription.cancellation_requested', 'subject_id' => (string) $id, 'occurred_at' => $time,
            ]);

            return $this->describe($db->table('subscriptions')->where('id', $id)->first());
        });
    }

    /** Verhindert echte Vertragskündigungen, bis der öffentliche Vertragsablauf implementiert und freigegeben ist. */
    private function assertTestCancellationAllowed(object $subscription): void
    {
        abort_unless(app()->environment('local', 'testing') && $subscription->is_test_registration, 403);
        abort_unless(in_array($subscription->status, ['trial', 'active'], true) || $subscription->cancelled_at, 409);
    }

    /** Berechnet eine reine Anzeigeprojektion: Trial, simulierte Monatsperiode, vorgemerkte Kündigung oder erreichtes Ende. */
    private function describe(object $subscription): array
    {
        $time = CarbonImmutable::now('UTC');
        $trialEnd = CarbonImmutable::parse($subscription->trial_ends_at, 'UTC');
        $inTrial = $time->lessThan($trialEnd);
        $period = $inTrial
            ? ['start' => CarbonImmutable::parse($subscription->trial_started_at, 'UTC'), 'end' => $trialEnd]
            : app(SubscriptionPeriod::class)->at(CarbonImmutable::parse($subscription->billing_anchor_at, 'UTC'), $time);
        $end = $subscription->ends_at ? CarbonImmutable::parse($subscription->ends_at, 'UTC') : null;
        $ended = $end !== null && $time->greaterThanOrEqualTo($end);
        $label = $ended ? 'Beendet' : ($subscription->cancelled_at ? 'Kündigung vorgemerkt' : ($inTrial ? '30-Tage-Testphase' : ($subscription->is_test_registration ? 'Monatsabo · Simulation' : 'Monatsabo')));
        if (! $ended && ! $subscription->cancelled_at && ! in_array($subscription->status, ['trial', 'active'], true)) {
            $label = ['payment_overdue' => 'Zahlung offen', 'cancelled' => 'Beendet', 'archived' => 'Archiviert'][$subscription->status] ?? 'Statusprüfung erforderlich';
        }

        return [
            'id' => $subscription->id, 'station_id' => $subscription->station_id, 'status_label' => $label,
            'is_test' => (bool) $subscription->is_test_registration, 'in_trial' => $inTrial, 'ended' => $ended,
            'gross_cents' => $subscription->gross_cents, 'tax_basis_points' => $subscription->tax_basis_points,
            'trial_end' => $trialEnd->toDateTimeString(),
            'period_start' => $ended ? null : $period['start']->toDateTimeString(),
            'period_end' => $ended ? null : $period['end']->toDateTimeString(),
            'cancelled_at' => $subscription->cancelled_at, 'ends_at' => $subscription->ends_at,
            'cancellation_end' => ($end ?? $period['end'])->toDateTimeString(),
            'can_cancel' => ! $subscription->cancelled_at && ! $ended && $subscription->is_test_registration
                && app()->environment('local', 'testing') && in_array($subscription->status, ['trial', 'active'], true),
        ];
    }
}
