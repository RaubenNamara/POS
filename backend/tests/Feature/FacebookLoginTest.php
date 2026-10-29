<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Facebook Login: the token must be valid for OUR app; Facebook is faked at the HTTP layer. */
class FacebookLoginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.facebook.app_id' => '111222333444', 'services.facebook.app_secret' => str_repeat('a1', 16)]);
    }

    private function fakeFacebook(string $appId = '111222333444', ?string $email = 'fb.person@example.com'): void
    {
        Http::fake([
            'graph.facebook.com/*/debug_token*' => Http::response(['data' => ['is_valid' => true, 'app_id' => $appId, 'user_id' => '987654']]),
            'graph.facebook.com/*/me*' => Http::response(array_filter(['id' => '987654', 'name' => 'Fb Person', 'email' => $email])),
        ]);
    }

    public function test_new_customer_signs_up_with_facebook_then_signs_in_again(): void
    {
        $this->fakeFacebook();
        $this->postJson('/api/auth/facebook', ['access_token' => 'tok', 'device_name' => 'test'])
            ->assertOk()->assertJsonPath('user.email', 'fb.person@example.com')->assertJsonPath('user.uses_facebook', true)
            ->assertJsonPath('user.needs_location', true);
        $u = User::where('email', 'fb.person@example.com')->firstOrFail();
        $this->assertSame('987654', $u->facebook_id);
        $this->assertSame('customer', $u->role);
        $this->assertNotNull($u->customer);

        // Second time: same account, no duplicate.
        $this->postJson('/api/auth/facebook', ['access_token' => 'tok', 'device_name' => 'test'])->assertOk();
        $this->assertSame(1, User::where('email', 'fb.person@example.com')->count());

        // Step one of the sign-in page sends this account to the Facebook button.
        $this->postJson('/api/auth/identify', ['login' => 'fb.person@example.com'])->assertJson(['exists' => true, 'social' => 'facebook']);
    }

    public function test_token_made_for_another_app_is_refused(): void
    {
        $this->fakeFacebook(appId: '999');
        $this->postJson('/api/auth/facebook', ['access_token' => 'tok', 'device_name' => 'test'])->assertStatus(422);
        $this->assertSame(0, User::where('email', 'fb.person@example.com')->count());
    }

    public function test_facebook_account_without_email_is_asked_to_use_email(): void
    {
        $this->fakeFacebook(email: null);
        $this->postJson('/api/auth/facebook', ['access_token' => 'tok', 'device_name' => 'test'])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Your Facebook account did not share an email address. Please continue with your email or phone instead.']);
    }

    public function test_staff_cannot_sign_in_with_facebook(): void
    {
        $staff = $this->staff('manager');
        $staff->email = 'fb.person@example.com';
        $staff->save();
        $this->fakeFacebook();
        $this->postJson('/api/auth/facebook', ['access_token' => 'tok', 'device_name' => 'test'])->assertStatus(422);
        $this->assertNull($staff->fresh()->facebook_id);
    }

    public function test_not_configured_until_both_keys_are_set(): void
    {
        config(['services.facebook.app_secret' => null]);
        $this->getJson('/api/shop/home')->assertJsonPath('settings.facebook_app_id', '');
        $this->postJson('/api/auth/facebook', ['access_token' => 'tok', 'device_name' => 'test'])->assertStatus(422);
    }
}
