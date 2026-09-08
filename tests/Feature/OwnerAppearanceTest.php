<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\Tenant;
use App\Settings\OwnerAppearance;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OwnerAppearanceTest extends TestCase
{
    /** Die persönlichen Einstellungen werden ausschließlich auf der isolierten Testinstanz geprüft. */
    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.connections.central.database') !== 'stationdesk_test') {
            throw new RuntimeException('Isolierte Testdatenbank erforderlich.');
        }
        Artisan::call('migrate', ['--database' => 'central', '--force' => true]);
        DB::connection('central')->beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::connection('central')->rollBack();
        parent::tearDown();
    }

    private function owner(): Owner
    {
        $tenant = (new Tenant)->forceFill(['id' => (string) Str::uuid(), 'company_name' => 'Farbtest', 'provisioning_status' => 'ready',
            'tenancy_db_name' => 'sd_t_'.bin2hex(random_bytes(16)), 'tenancy_db_username' => 'sdu_'.bin2hex(random_bytes(14)), 'tenancy_db_password' => bin2hex(random_bytes(32))]);
        $tenant->save();

        return Owner::factory()->create(['tenant_id' => $tenant->id]);
    }

    public function test_selection_is_personal_and_reloaded_without_leaking_between_users(): void
    {
        $first = $this->owner();
        $second = $this->owner();
        $appearance = app(OwnerAppearance::class);
        $this->actingAs($first, 'web');
        $appearance->save('shell');
        $appearance->save('shell');
        $this->assertSame('shell', $appearance->currentKey());
        $this->actingAs($second, 'web');
        $this->assertSame('petrol', $appearance->currentKey());
        $appearance->save('esso');
        $this->actingAs($first->fresh(), 'web');
        $this->assertSame('shell', $appearance->currentKey());
        $this->assertSame('esso', $second->fresh()->color_scheme);
        $this->assertSame(1, DB::connection('central')->table('audit_events')->where('actor_id', $first->id)->where('action', 'owner.appearance_changed')->count());
        try {
            $appearance->save('</style><script>alert(1)</script>');
            $this->fail('Freier CSS-Wert wurde gespeichert.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('colorScheme', $exception->errors());
        }
        $this->assertSame('shell', $first->fresh()->color_scheme);
    }

    public function test_guests_get_default_colors_but_cannot_save(): void
    {
        $this->assertSame('petrol', app(OwnerAppearance::class)->currentKey());
        $this->expectException(HttpException::class);
        app(OwnerAppearance::class)->save('aral');
    }
}
