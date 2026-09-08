<?php

namespace Tests\Feature;

use App\Models\SuperAdmin;
use Database\Seeders\LocalSuperAdminSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/** Lokale Kontoanlage, Wiederholbarkeit und Ausschluss produktiver Ausführung. */
class LocalSuperAdminSeederTest extends TestCase
{
    /** Verwendet ausschließlich das isolierte Testschema mit rückrollbaren Testdaten. */
    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.connections.central.database') !== 'stationdesk_test') {
            throw new RuntimeException('Isolierte Testdatenbank erforderlich.');
        }
        Artisan::call('migrate', ['--database' => 'central', '--force' => true]);
        DB::connection('central')->beginTransaction();
        config(['local-admin' => [
            'name' => 'Test Admin', 'email' => 'seeder@example.test', 'password' => 'Only-for-test-12345',
        ]]);
        $this->app->instance('env', 'local');
    }

    /** Entfernt Testkonto und Auditereignisse gemeinsam. */
    protected function tearDown(): void
    {
        DB::connection('central')->rollBack();
        parent::tearDown();
    }

    public function test_creates_hashed_account_and_preserves_password_and_totp_on_repeat(): void
    {
        $this->seed(LocalSuperAdminSeeder::class);
        $admin = SuperAdmin::query()->where('email', 'seeder@example.test')->sole();
        $this->assertTrue(Hash::check('Only-for-test-12345', $admin->password));
        $hash = $admin->password;
        $admin->saveAppAuthenticationSecret('TESTSECRET');
        config(['local-admin.password' => 'Another-test-password']);
        $this->seed(LocalSuperAdminSeeder::class);
        $this->assertSame($hash, $admin->fresh()->password);
        $this->assertSame('TESTSECRET', $admin->fresh()->getAppAuthenticationSecret());
        $this->assertSame(1, SuperAdmin::query()->where('email', 'seeder@example.test')->count());
        $this->assertSame(1, DB::connection('central')->table('audit_events')->where('action', 'super_admin.created')->where('subject_id', (string) $admin->id)->count());
    }

    public function test_rejects_production(): void
    {
        $this->app->instance('env', 'production');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ausschließlich lokal');
        Artisan::call('db:seed', ['--class' => LocalSuperAdminSeeder::class, '--force' => true]);
    }

    public function test_requires_password_configuration(): void
    {
        config(['local-admin.password' => null]);
        $this->expectException(ValidationException::class);
        $this->seed(LocalSuperAdminSeeder::class);
    }
}
