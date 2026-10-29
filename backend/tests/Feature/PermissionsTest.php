<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class PermissionsTest extends TestCase
{
    public function test_guests_get_401_on_protected_endpoints(): void
    {
        foreach (['/api/admin/dashboard', '/api/admin/products', '/api/admin/pos/bootstrap', '/api/my/orders', '/api/auth/me'] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        $this->postJson('/api/admin/pos/checkout', [])->assertUnauthorized();
    }

    public function test_customers_cannot_reach_staff_api(): void
    {
        $shopper = User::factory()->create(); // role defaults to customer
        $this->actingAs($shopper)->getJson('/api/admin/products')->assertForbidden();
        $this->actingAs($shopper)->postJson('/api/admin/pos/checkout', [])->assertForbidden();
    }

    public function test_role_matrix(): void
    {
        $cases = [
            // [role, method, url, allowed]
            ['cashier', 'get', '/api/admin/pos/bootstrap', true],
            ['cashier', 'get', '/api/admin/dashboard', false],
            ['cashier', 'get', '/api/admin/reports/profit_loss', false],
            ['cashier', 'post', '/api/admin/products', false],
            ['cashier', 'post', '/api/admin/inventory/adjust', false],
            ['cashier', 'get', '/api/admin/users', false],
            ['stock_manager', 'post', '/api/admin/inventory/adjust', true],
            ['stock_manager', 'get', '/api/admin/pos/bootstrap', false],
            ['stock_manager', 'get', '/api/admin/reports/profit_loss', false],
            ['stock_manager', 'get', '/api/admin/dashboard', true],
            ['manager', 'get', '/api/admin/reports/profit_loss', false],
            ['manager', 'get', '/api/admin/dashboard', true],
            ['manager', 'get', '/api/admin/users', false],
            ['manager', 'put', '/api/admin/settings', false],
            ['owner', 'get', '/api/admin/reports/profit_loss', true],
            ['owner', 'get', '/api/admin/users', true],
        ];
        foreach ($cases as [$role, $verb, $url, $allowed]) {
            $res = $this->actingAs($this->staff($role))->json(strtoupper($verb), $url, []);
            $allowed
                ? $this->assertNotContains($res->status(), [401, 403], "{$role} should reach {$verb} {$url}")
                : $this->assertSame(403, $res->status(), "{$role} must be refused {$verb} {$url}");
        }
    }

    public function test_dashboard_hides_money_from_non_financial_roles(): void
    {
        $res = $this->actingAs($this->staff('manager'))->getJson('/api/admin/dashboard')->assertOk();
        $res->assertJsonPath('financial', false)->assertJsonMissingPath('summary.today.gross_profit')->assertJsonMissingPath('inventory.cost_value');
        $this->actingAs($this->staff('owner'))->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('financial', true)
            ->assertJsonStructure(['summary' => ['today' => ['gross_profit', 'net_profit']], 'inventory' => ['cost_value', 'retail_value']]);
    }

    public function test_disabled_staff_are_locked_out(): void
    {
        $u = $this->staff('manager');
        $u->is_active = false;
        $u->save();
        $this->actingAs($u)->getJson('/api/admin/products')->assertForbidden();
        $this->postJson('/api/auth/login', ['login' => $u->email, 'password' => 'password'])->assertStatus(422);
    }

    public function test_role_cannot_be_mass_assigned_on_registration(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Sneaky', 'email' => 'sneaky@example.com', 'password' => 'Password123', 'password_confirmation' => 'Password123', 'role' => 'owner',
        ])->assertCreated()->assertJsonPath('user.role', 'customer');
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/auth/login', ['login' => 'nobody@example.com', 'password' => 'wrong'])->assertStatus(422);
        }
        $this->postJson('/api/auth/login', ['login' => 'nobody@example.com', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_mobile_token_login(): void
    {
        $u = $this->staff('cashier');
        $token = $this->postJson('/api/auth/login', ['login' => $u->email, 'password' => 'password', 'device_name' => 'Android till'])
            ->assertOk()->json('token');
        $this->assertNotEmpty($token);
        $this->withToken($token)->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.role', 'cashier');
    }

    public function test_cashiers_do_not_see_cost_prices(): void
    {
        $p = $this->product('1234', '2000', 5);
        $this->actingAs($this->staff('cashier'))->getJson('/api/admin/products')->assertOk()->assertJsonMissingPath('data.0.cost_price');
        $this->actingAs($this->staff('cashier'))->getJson("/api/admin/products/{$p->id}")->assertOk()->assertJsonMissingPath('cost_price');
        $this->actingAs($this->staff('stock_manager'))->getJson("/api/admin/products/{$p->id}")->assertOk()->assertJsonPath('cost_price', '1234.0000');
    }
}
