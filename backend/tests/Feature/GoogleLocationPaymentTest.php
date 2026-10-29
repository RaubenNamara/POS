<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Google sign-in, location-based payment options, and Flutterwave payments.
 * Google and Flutterwave are faked at the HTTP layer; the app's own logic runs for real.
 */
class GoogleLocationPaymentTest extends TestCase
{
    // Real-world points (lat, lng) and what Google's reverse geocoder says about them.
    private const KAMPALA = [0.3136, 32.5811, 'Kampala'];
    private const WAKISO = [0.4044, 32.4594, 'Wakiso'];
    private const GULU = [2.7724, 32.2881, 'Gulu'];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'services.google.client_id' => 'test-client.apps.googleusercontent.com',
            'services.google.maps_server_key' => 'test-maps-key',
            'services.flutterwave.public_key' => 'FLWPUBK_TEST-x',
            'services.flutterwave.secret_key' => 'FLWSECK_TEST-x',
            'services.flutterwave.webhook_hash' => 'my-webhook-secret',
            'services.delivery.fallback_radius_km' => 0,
        ]);
    }

    private function fakeGoogle(array $flutterwave = []): void
    {
        Http::fake(array_merge([
            'maps.googleapis.com/*' => function ($request) {
                [$lat, $lng] = array_map('floatval', explode(',', $request['latlng']));
                $district = collect([self::KAMPALA, self::WAKISO, self::GULU])->first(fn ($p) => abs($p[0] - $lat) < 0.01 && abs($p[1] - $lng) < 0.01)[2] ?? 'Mukono';

                return Http::response(['status' => 'OK', 'results' => [[
                    'formatted_address' => "Somewhere, {$district}, Uganda",
                    'address_components' => [
                        ['long_name' => 'Somewhere', 'types' => ['route']],
                        ['long_name' => $district === 'Kampala' ? 'Kampala Capital City' : "{$district} District", 'types' => ['administrative_area_level_2', 'political']],
                        ['long_name' => 'Central Region', 'types' => ['administrative_area_level_1', 'political']],
                        ['long_name' => 'Uganda', 'types' => ['country', 'political']],
                    ],
                ]]]);
            },
            'oauth2.googleapis.com/tokeninfo*' => function ($request) {
                $tokens = [
                    'good-token' => ['aud' => 'test-client.apps.googleusercontent.com', 'iss' => 'accounts.google.com', 'exp' => time() + 600,
                        'email_verified' => 'true', 'sub' => 'google-123', 'email' => 'Amina@Gmail.com', 'name' => 'Amina N', 'picture' => 'https://x/p.jpg'],
                    'other-app' => ['aud' => 'someone-else', 'iss' => 'accounts.google.com', 'exp' => time() + 600, 'email_verified' => 'true', 'sub' => 'g9', 'email' => 'x@gmail.com'],
                    'staff-token' => ['aud' => 'test-client.apps.googleusercontent.com', 'iss' => 'accounts.google.com', 'exp' => time() + 600,
                        'email_verified' => 'true', 'sub' => 'google-staff', 'email' => 'boss@shop.test'],
                ];

                return isset($tokens[$request['id_token']]) ? Http::response($tokens[$request['id_token']]) : Http::response(['error' => 'invalid_token'], 400);
            },
        ], $flutterwave));
    }

    private function shopper(?array $at = null): User
    {
        $u = User::factory()->create();
        $c = Customer::create(['user_id' => $u->id, 'name' => $u->name, 'email' => $u->email]);
        if ($at) {
            $this->actingAs($u)->putJson('/api/my/location', ['lat' => $at[0], 'lng' => $at[1]])->assertOk();
        }

        return $u;
    }

    private function order(User $u, $product, array $at, string $option, array $extra = [])
    {
        return $this->actingAs($u)->postJson('/api/my/orders', array_merge([
            'client_uuid' => (string) Str::uuid(), 'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'fulfillment' => 'delivery', 'delivery_name' => 'Amina', 'delivery_phone' => '0772000000', 'delivery_address' => 'Near the mosque',
            'delivery_lat' => $at[0], 'delivery_lng' => $at[1], 'payment_option' => $option,
        ], $extra));
    }

    // ------------------------------------------------------------------ Google sign-in

    public function test_google_sign_in_creates_a_customer_and_asks_for_location(): void
    {
        $this->fakeGoogle();
        $this->postJson('/api/auth/google', ['credential' => 'good-token'])->assertOk()
            ->assertJsonPath('user.role', 'customer')->assertJsonPath('user.email', 'amina@gmail.com')
            ->assertJsonPath('user.needs_location', true)->assertJsonPath('user.uses_google', true);
        $u = User::where('email', 'amina@gmail.com')->first();
        $this->assertSame('google-123', $u->google_id);
        $this->assertNull($u->password);
        $this->assertNotNull($u->customer);

        // Signing in again links to the same account, not a new one.
        $this->postJson('/api/auth/google', ['credential' => 'good-token'])->assertOk();
        $this->assertSame(1, User::where('email', 'amina@gmail.com')->count());
        // A Google-only account cannot be used with a password.
        $this->postJson('/api/auth/login', ['login' => 'amina@gmail.com', 'password' => 'anything'])->assertStatus(422)
            ->assertJsonPath('errors.login.0', 'This account signs in with Google. Use the Google button.');
    }

    public function test_google_tokens_for_other_apps_or_forged_are_refused(): void
    {
        $this->fakeGoogle();
        $this->postJson('/api/auth/google', ['credential' => 'other-app'])->assertStatus(422)->assertJsonPath('code', 'google_invalid');
        $this->postJson('/api/auth/google', ['credential' => 'forged'])->assertStatus(422)->assertJsonPath('code', 'google_invalid');
        $this->assertSame(0, User::whereIn('email', ['x@gmail.com'])->count());
    }

    public function test_google_cannot_sign_in_to_a_staff_account(): void
    {
        $this->fakeGoogle();
        $staff = $this->staff('owner');
        $staff->email = 'boss@shop.test';
        $staff->save();
        $this->postJson('/api/auth/google', ['credential' => 'staff-token'])->assertStatus(422);
        $this->assertNull($staff->fresh()->google_id);
    }

    // ------------------------------------------------------------------ location -> payment options

    public function test_location_is_classified_on_the_server(): void
    {
        $this->fakeGoogle();
        foreach ([[self::KAMPALA, 'metro', 'Kampala'], [self::WAKISO, 'metro', 'Wakiso'], [self::GULU, 'outside', 'Gulu District']] as [$at, $zone, $district]) {
            $u = $this->shopper();
            $this->actingAs($u)->putJson('/api/my/location', ['lat' => $at[0], 'lng' => $at[1], 'zone' => 'metro'])->assertOk()
                ->assertJsonPath('location.zone', $zone)->assertJsonPath('location.district', $district);
        }
        // Far outside Uganda: decided without even calling Google.
        $this->actingAs($this->shopper())->putJson('/api/my/location', ['lat' => 51.5, 'lng' => -0.12])->assertOk()->assertJsonPath('location.zone', 'outside');
        $this->actingAs($this->shopper())->getJson('/api/auth/me')->assertJsonPath('user.needs_location', true);
    }

    public function test_kampala_and_wakiso_can_pay_on_delivery_or_online(): void
    {
        $this->fakeGoogle();
        foreach ([self::KAMPALA, self::WAKISO] as $at) {
            $this->actingAs($this->shopper($at))->postJson('/api/my/delivery-options', ['fulfillment' => 'delivery', 'lat' => $at[0], 'lng' => $at[1]])
                ->assertOk()->assertJsonPath('options', ['online', 'cod']);
            $this->order($this->shopper($at), $this->product('1000', '5000', 5), $at, 'cod')->assertCreated()
                ->assertJsonPath('order.payment_option', 'cod')->assertJsonPath('order.fulfillment_status', 'placed');
        }
    }

    public function test_outside_kampala_and_wakiso_pay_on_delivery_is_refused(): void
    {
        $this->fakeGoogle();
        $u = $this->shopper(self::GULU);
        $p = $this->product('1000', '5000', 5);
        $this->actingAs($u)->postJson('/api/my/delivery-options', ['fulfillment' => 'delivery', 'lat' => self::GULU[0], 'lng' => self::GULU[1]])
            ->assertOk()->assertJsonPath('options', ['online']);
        // Even a hand-crafted request cannot choose pay on delivery for a Gulu pin.
        $this->order($u, $p, self::GULU, 'cod')->assertStatus(422)->assertJsonPath('code', 'payment_option_not_allowed');
        $this->assertSame(5, $p->fresh()->total_stock, 'nothing reserved for a refused order');

        // A customer registered in Kampala but delivering to Gulu is judged by the delivery pin.
        $this->order($this->shopper(self::KAMPALA), $p, self::GULU, 'cod')->assertStatus(422);
    }

    public function test_when_location_cannot_be_verified_pay_on_delivery_is_off(): void
    {
        config(['services.google.maps_server_key' => null]); // no Google key, no fallback radius
        $this->fakeGoogle();
        $u = $this->shopper();
        $this->actingAs($u)->putJson('/api/my/location', ['lat' => self::KAMPALA[0], 'lng' => self::KAMPALA[1]])->assertOk()->assertJsonPath('location.zone', 'unknown');
        $this->order($u, $this->product('1000', '5000', 5), self::KAMPALA, 'cod')->assertStatus(422);
    }

    // ------------------------------------------------------------------ Flutterwave

    private function flutterwave(string $status = 'successful', ?string $amount = null, string $currency = 'UGX'): array
    {
        return [
            'api.flutterwave.com/v3/payments' => Http::response(['status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.com/v3/hosted/pay/abc']]),
            'api.flutterwave.com/v3/transactions/*' => function ($request) use ($status, $amount, $currency) {
                $ref = DB::table('gateway_transactions')->latest('id')->first();

                return Http::response(['status' => 'success', 'data' => [
                    'id' => 987654, 'tx_ref' => $ref->tx_ref, 'status' => $status, 'amount' => $amount ?? $ref->amount, 'currency' => $currency,
                ]]);
            },
        ];
    }

    public function test_online_payment_flow_marks_order_paid_once(): void
    {
        $this->fakeGoogle($this->flutterwave());
        $u = $this->shopper(self::GULU);
        $p = $this->product('1000', '5000', 5);

        $res = $this->order($u, $p, self::GULU, 'online')->assertCreated()
            ->assertJsonPath('checkout_url', 'https://checkout.flutterwave.com/v3/hosted/pay/abc')
            ->assertJsonPath('order.fulfillment_status', 'awaiting_payment')->assertJsonPath('order.payment_status', 'unpaid');
        $this->assertSame(4, $p->fresh()->total_stock, 'stock is held while the customer pays');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/v3/payments') && $r['amount'] === '5000.00' && $r['currency'] === 'UGX'
            && $r->hasHeader('Authorization', 'Bearer FLWSECK_TEST-x'));

        $txRef = DB::table('gateway_transactions')->value('tx_ref');
        // Staff cannot start work on it before it is paid.
        $sale = Sale::first();
        $this->actingAs($this->staff('manager'))->postJson("/api/admin/orders/{$sale->id}/status", ['status' => 'confirmed'])->assertStatus(422);

        // Customer returns from Flutterwave: confirmed via Flutterwave's API, then again (refresh) = no double payment.
        $this->actingAs($u)->postJson('/api/my/payments/confirm', ['tx_ref' => $txRef, 'transaction_id' => '987654'])->assertOk()
            ->assertJsonPath('status', 'successful')->assertJsonPath('order.payment_status', 'paid')->assertJsonPath('order.fulfillment_status', 'placed');
        $this->actingAs($u)->postJson('/api/my/payments/confirm', ['tx_ref' => $txRef, 'transaction_id' => '987654'])->assertOk();
        // The webhook arriving later is harmless too.
        $this->postJson('/api/webhooks/flutterwave', ['event' => 'charge.completed', 'data' => ['id' => 987654, 'tx_ref' => $txRef, 'status' => 'successful']],
            ['verif-hash' => 'my-webhook-secret'])->assertOk();

        $sale->refresh();
        $this->assertSame(1, $sale->payments()->count());
        $this->assertSame('5000.00', (string) $sale->amount_paid);
        $this->assertSame('FLW 987654', $sale->payments()->value('reference'));
        $this->assertReconciles();
    }

    public function test_webhook_alone_confirms_payment_and_forged_webhooks_are_rejected(): void
    {
        $this->fakeGoogle($this->flutterwave());
        $u = $this->shopper(self::GULU);
        $this->order($u, $this->product('1000', '5000', 5), self::GULU, 'online')->assertCreated();
        $txRef = DB::table('gateway_transactions')->value('tx_ref');

        $this->postJson('/api/webhooks/flutterwave', ['data' => ['id' => 987654, 'tx_ref' => $txRef, 'status' => 'successful']], ['verif-hash' => 'wrong'])->assertStatus(401);
        $this->assertSame('unpaid', Sale::first()->payment_status);

        $this->postJson('/api/webhooks/flutterwave', ['data' => ['id' => 987654, 'tx_ref' => $txRef, 'status' => 'successful']], ['verif-hash' => 'my-webhook-secret'])
            ->assertOk()->assertJsonPath('status', 'successful');
        $this->assertSame('paid', Sale::first()->payment_status);
    }

    public function test_redirect_claiming_success_is_not_trusted(): void
    {
        // Flutterwave's API says the payment failed, whatever the browser's URL claims.
        $this->fakeGoogle($this->flutterwave('failed'));
        $u = $this->shopper(self::GULU);
        $this->order($u, $this->product('1000', '5000', 5), self::GULU, 'online')->assertCreated();
        $this->actingAs($u)->postJson('/api/my/payments/confirm', ['tx_ref' => DB::table('gateway_transactions')->value('tx_ref'), 'transaction_id' => '987654'])
            ->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('order.payment_status', 'unpaid');
    }

    public function test_underpayment_or_wrong_currency_is_held_for_review(): void
    {
        $this->fakeGoogle($this->flutterwave('successful', '100.00'));
        $u = $this->shopper(self::GULU);
        $this->order($u, $this->product('1000', '5000', 5), self::GULU, 'online')->assertCreated();
        $this->actingAs($u)->postJson('/api/my/payments/confirm', ['tx_ref' => DB::table('gateway_transactions')->value('tx_ref'), 'transaction_id' => '987654'])
            ->assertOk()->assertJsonPath('status', 'mismatch')->assertJsonPath('order.payment_status', 'unpaid');
        $this->assertSame(1, DB::table('notifications_feed')->where('type', 'payment_review')->count());
    }

    public function test_unpaid_online_orders_expire_and_release_stock(): void
    {
        $this->fakeGoogle($this->flutterwave('failed'));
        $u = $this->shopper(self::GULU);
        $p = $this->product('1000', '5000', 5);
        $this->order($u, $p, self::GULU, 'online')->assertCreated();
        $this->assertSame(4, $p->fresh()->total_stock);

        Sale::query()->update(['payment_expires_at' => now()->subMinute()]);
        $this->artisan('shop:expire-unpaid-orders')->assertSuccessful();
        $this->assertSame('cancelled', Sale::first()->status);
        $this->assertSame(5, $p->fresh()->total_stock);
        $this->assertReconciles();
    }

    public function test_customers_cannot_confirm_someone_elses_payment(): void
    {
        $this->fakeGoogle($this->flutterwave());
        $this->order($this->shopper(self::GULU), $this->product('1000', '5000', 5), self::GULU, 'online')->assertCreated();
        $this->actingAs($this->shopper())->postJson('/api/my/payments/confirm', ['tx_ref' => DB::table('gateway_transactions')->value('tx_ref')])->assertNotFound();
    }
}
