<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

/** Step one of sign-in: "email or phone" decides between password, sign-up and Google. */
class IdentifyTest extends TestCase
{
    public function test_known_email_and_phone_ask_for_password(): void
    {
        $u = $this->staff();
        $u->phone = '0700111222';
        $u->save();

        $this->postJson('/api/auth/identify', ['login' => $u->email])
            ->assertOk()->assertJson(['exists' => true, 'type' => 'email', 'google_only' => false]);
        $this->postJson('/api/auth/identify', ['login' => '0700111222'])
            ->assertOk()->assertJson(['exists' => true, 'type' => 'phone']);
    }

    public function test_unknown_address_goes_to_sign_up(): void
    {
        $this->postJson('/api/auth/identify', ['login' => 'someone.new@example.com'])
            ->assertOk()->assertJson(['exists' => false, 'type' => 'email']);
        $this->postJson('/api/auth/identify', ['login' => '+256 799 000 111'])
            ->assertOk()->assertJson(['exists' => false, 'type' => 'phone']);
    }

    public function test_google_only_account_is_sent_to_google(): void
    {
        $u = new User(['name' => 'G User', 'email' => 'g.user@example.com']);
        $u->role = 'customer';
        $u->is_active = true;
        $u->google_id = 'g-123';
        $u->save();

        $this->postJson('/api/auth/identify', ['login' => 'g.user@example.com'])
            ->assertOk()->assertJson(['exists' => true, 'google_only' => true]);
    }

    public function test_garbage_is_refused_and_lookups_are_throttled(): void
    {
        $this->postJson('/api/auth/identify', ['login' => 'not-an-email@'])->assertStatus(422);
        $this->postJson('/api/auth/identify', ['login' => 'abc'])->assertStatus(422);

        for ($i = 0; $i < 18; $i++) {
            $this->postJson('/api/auth/identify', ['login' => "probe{$i}@example.com"]);
        }
        $this->postJson('/api/auth/identify', ['login' => 'probe-last@example.com'])->assertStatus(429);
    }
}
