<?php

namespace Tests\Feature;

use App\Services\FlutterwaveService;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntegrationSettingsTest extends TestCase
{
    public function test_owner_connects_google_and_flutterwave_from_settings_and_secrets_never_leak(): void
    {
        config(['services.google.client_id' => null, 'services.flutterwave.public_key' => null, 'services.flutterwave.secret_key' => null, 'services.flutterwave.webhook_hash' => null]);
        $owner = $this->staff('owner');

        $this->actingAs($owner)->getJson('/api/admin/settings')->assertOk()
            ->assertJsonPath('integrations.google_signin', false)->assertJsonPath('integrations.flutterwave', false);

        $res = $this->actingAs($owner)->putJson('/api/admin/settings', [
            'google_client_id' => '123-abc.apps.googleusercontent.com',
            'flutterwave_public_key' => 'FLWPUBK_TEST-pub',
            'flutterwave_secret_key' => 'FLWSECK_TEST-super-secret',
            'flutterwave_webhook_hash' => 'my-webhook-hash',
        ])->assertOk();
        $res->assertJsonPath('integrations.google_signin', true)->assertJsonPath('integrations.flutterwave', true)
            ->assertJsonPath('integrations.flutterwave_mode', 'test')->assertJsonPath('integrations.secrets_set.flutterwave_secret_key', true)
            ->assertJsonPath('flutterwave_secret_key', '');
        $this->assertStringNotContainsString('super-secret', $res->getContent());

        // Stored encrypted, decrypted only into config on the server.
        $raw = DB::table('settings')->where('key', 'flutterwave_secret_key')->value('value');
        $this->assertNotSame('FLWSECK_TEST-super-secret', $raw);
        Cache::forget('settings');
        config(['services.flutterwave.secret_key' => null]);
        Settings::applyIntegrations();
        $this->assertSame('FLWSECK_TEST-super-secret', config('services.flutterwave.secret_key'));
        $this->assertTrue(FlutterwaveService::configured());

        // Storefront gets the public client id only.
        Cache::flush();
        $home = $this->getJson('/api/shop/home')->assertOk();
        $home->assertJsonPath('settings.google_client_id', '123-abc.apps.googleusercontent.com')->assertJsonPath('settings.online_payments', '1');
        $this->assertStringNotContainsString('FLWSECK', $home->getContent());

        // Blank secret = keep; explicit clear removes it.
        $this->actingAs($owner)->putJson('/api/admin/settings', ['flutterwave_secret_key' => ''])->assertOk()->assertJsonPath('integrations.flutterwave', true);
        config(['services.flutterwave.secret_key' => null]);
        $this->actingAs($owner)->putJson('/api/admin/settings', ['clear_secrets' => ['flutterwave_secret_key']])->assertOk()->assertJsonPath('integrations.flutterwave', false);

        // Wrong-looking keys are refused; non-owners cannot change them.
        $this->actingAs($owner)->putJson('/api/admin/settings', ['flutterwave_secret_key' => 'sk_live_wrong_provider'])->assertStatus(422);
        $this->actingAs($this->staff('manager'))->putJson('/api/admin/settings', ['google_client_id' => 'x.apps.googleusercontent.com'])->assertForbidden();
        $this->assertSame(0, DB::table('audit_logs')->where('new_values', 'like', '%super-secret%')->count(), 'secrets never written to the audit log');
    }
}
