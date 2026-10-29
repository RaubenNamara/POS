<?php

namespace Tests;

use App\Models\Customer;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Services\InventoryService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected bool $seed = true; // base seeder: location, payment methods, owner

    protected function staff(string $role = 'cashier'): User
    {
        $u = User::factory()->create();
        $u->role = $role;
        $u->is_active = true;
        $u->save();

        return $u;
    }

    protected function product(string $cost = '1000', string $price = '1500', int $stock = 10, array $attrs = []): Product
    {
        $p = new Product(array_merge(['name' => 'Item '.Str::random(5), 'selling_price' => $price], $attrs));
        $p->sku = 'T-'.Str::upper(Str::random(8));
        $p->slug = Str::slug($p->name).'-'.Str::lower(Str::random(5));
        $p->save();
        if ($stock > 0) {
            DB::transaction(fn () => app(InventoryService::class)->move('opening', $this->locationId(), [['product_id' => $p->id, 'quantity' => $stock, 'unit_cost' => $cost]], $p, 'test'));
        }

        return $p->fresh();
    }

    protected function bundle(array $components, string $price): Product
    {
        $b = new Product(['type' => 'bundle', 'name' => 'Bundle '.Str::random(4), 'selling_price' => $price]);
        $b->sku = 'B-'.Str::upper(Str::random(8));
        $b->slug = 'bundle-'.Str::lower(Str::random(8));
        $b->save();
        foreach ($components as [$product, $qty]) {
            $b->bundleItems()->create(['product_id' => $product->id, 'quantity' => $qty]);
        }

        return $b->fresh();
    }

    protected function locationId(): int
    {
        return Location::where('is_default', true)->value('id');
    }

    protected function method(string $code = 'cash'): int
    {
        return PaymentMethod::where('code', $code)->value('id');
    }

    protected function openShift(User $u, string $float = '0'): Shift
    {
        return Shift::create(['user_id' => $u->id, 'location_id' => $this->locationId(), 'opening_float' => $float, 'opened_at' => now(), 'status' => 'open']);
    }

    protected function customer(string $limit = '0'): Customer
    {
        return Customer::create(['name' => 'Cust '.Str::random(4), 'phone' => '07'.random_int(10000000, 99999999), 'credit_limit' => $limit]);
    }

    /** POST a POS sale as $user. */
    protected function sell(User $u, array $items, array $payments, array $extra = [])
    {
        return $this->actingAs($u)->postJson('/api/admin/pos/checkout', array_merge([
            'client_uuid' => (string) Str::uuid(),
            'items' => array_map(fn ($i) => ['product_id' => $i[0]->id, 'quantity' => $i[1]] + (isset($i[2]) ? ['discount' => $i[2]] : []), $items),
            'payments' => array_map(fn ($p) => ['payment_method_id' => $this->method($p[0]), 'amount' => $p[1]] + (isset($p[2]) ? ['reference' => $p[2]] : []), $payments),
        ], $extra));
    }

    protected function setting(array $v): void
    {
        Settings::set($v);
    }

    protected function assertReconciles(): void
    {
        $r = app(\App\Services\ReportService::class)->reconcile();
        $this->assertTrue($r['ok'], "Reconciliation problems:\n".implode("\n", $r['problems']));
    }
}
