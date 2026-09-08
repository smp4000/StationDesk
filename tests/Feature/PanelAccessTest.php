<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Tests\TestCase;

/** Gastzugriff und Trennung der öffentlichen Authentifizierungsflächen. */
class PanelAccessTest extends TestCase
{
    public function test_public_landing_page_links_to_shared_owner_login(): void
    {
        $this->withoutVite();
        $this->get('/')->assertOk()->assertSee('StationDeck')->assertSee('/owner/login', false);
    }

    public function test_owner_overview_rejects_guests(): void
    {
        $this->get('/owner/overview')->assertRedirect('/owner/login');
    }

    public function test_platform_overview_rejects_guests(): void
    {
        $this->get('/admin/provisioning')->assertRedirect('/admin/login');
    }

    public function test_platform_has_mandatory_totp_and_no_public_registration(): void
    {
        $panel = Filament::getPanel('admin');
        $this->assertTrue($panel->isMultiFactorAuthenticationRequired());
        $this->assertFalse($panel->hasRegistration());
        $this->assertSame('admin', $panel->getAuthGuard());
        $this->assertSame('web', Filament::getPanel('owner')->getAuthGuard());
    }
}
