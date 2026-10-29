<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Support\Str;
use Tests\TestCase;

class OnlineOrderAndPurchasingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.delivery.fallback_radius_km' => 20]); // central Kampala pin = pay-on-delivery zone
    }

    private function shopper(): User
    {
        $u = User::factory()->create();
        Customer::create(['user_id' => $u->id, 'name' => $u->name, 'email' => $u->email]);

        return $u;
    }

    private function order(User $u, $product, int $qty, array $extra = [])
    {
        return $this->actingAs($u)->postJson('/api/my/orders', array_merge([
            'client_uuid' => (string) Str::uuid(), 'items' => [['product_id' => $product->id, 'quantity' => $qty]],
            'fulfillment' => 'delivery', 'delivery_name' => 'Jane', 'delivery_phone' => '0700111222', 'delivery_address' => 'Plot 1, Kampala',
            'delivery_lat' => 0.3136, 'delivery_lng' => 32.5811, 'payment_option' => 'cod',
        ], $extra));
    }

    public function test_storefront_lists_only_online_products_with_availability(): void
    {
        $visible = $this->product('100', '1000', 3, ['name' => 'Visible Phone']);
        $this->product('100', '1000', 3, ['name' => 'Hidden Phone', 'show_online' => false]);
        $res = $this->getJson('/api/shop/products?q=phone')->assertOk();
        $this->assertSame(['Visible Phone'], array_column($res->json('data'), 'name'));
        $res->assertJsonPath('data.0.available', 3)->assertJsonMissingPath('data.0.cost_price');
        $this->getJson("/api/shop/products/{$visible->slug}")->assertOk()->assertJsonMissingPath('product.cost_price');
        $this->assertStringContainsString('max-age', $res->headers->get('Cache-Control'));
    }

    public function test_online_order_reserves_stock_and_counts_as_revenue_only_when_delivered(): void
    {
        $this->setting(['delivery_fee' => '5000']);
        $p = $this->product('1000', '10000', 5);
        $u = $this->shopper();

        $res = $this->order($u, $p, 2, ['total' => '1'])->assertCreated();
        $res->assertJsonPath('order.total', '25000.00')->assertJsonPath('order.payment_status', 'unpaid');
        $this->assertSame(3, $p->fresh()->total_stock);
        $range = ReportService::range(null, null);
        $this->assertSame('0.00', app(ReportService::class)->profitAndLoss(...$range)['net_revenue']);

        $sale = Sale::first();
        $m = $this->staff('manager');
        $this->openShift($m);
        $this->actingAs($m)->postJson("/api/admin/orders/{$sale->id}/status", ['status' => 'out_for_delivery'])->assertOk();
        // Paid on delivery: staff record the money; the order is not "paid" before that.
        $this->actingAs($m)->postJson("/api/admin/sales/{$sale->id}/payments", [
            'client_uuid' => (string) Str::uuid(), 'payment_method_id' => $this->method('cash'), 'amount' => '25000',
        ])->assertOk()->assertJsonPath('payment_status', 'paid');
        $this->actingAs($m)->postJson("/api/admin/orders/{$sale->id}/status", ['status' => 'delivered'])->assertOk();

        $pl = app(ReportService::class)->profitAndLoss(...$range);
        $this->assertSame('25000.00', $pl['net_revenue']);
        $this->assertSame('5000.00', $pl['delivery_fees']);
        $this->assertSame('2000.00', $pl['cogs']);
        $this->assertReconciles();
    }

    public function test_cancelling_an_order_restocks(): void
    {
        $p = $this->product('1000', '10000', 5);
        $this->order($this->shopper(), $p, 5)->assertCreated();
        $this->assertSame(0, $p->fresh()->total_stock);
        $this->order($this->shopper(), $p, 1)->assertStatus(422)->assertJsonPath('code', 'insufficient_stock');

        $this->actingAs($this->staff('manager'))->postJson('/api/admin/orders/'.Sale::first()->id.'/status', ['status' => 'cancelled'])->assertOk();
        $this->assertSame(5, $p->fresh()->total_stock);
        $this->assertSame('cancelled', Sale::first()->status);
        $this->assertReconciles();
    }

    public function test_customers_only_see_their_own_orders(): void
    {
        $p = $this->product('1000', '10000', 5);
        $alice = $this->shopper();
        $number = $this->order($alice, $p, 1)->json('order.number');
        $this->actingAs($alice)->getJson("/api/my/orders/{$number}")->assertOk();
        $this->actingAs($this->shopper())->getJson("/api/my/orders/{$number}")->assertNotFound();
    }

    public function test_purchase_order_partial_receipt_and_supplier_payment(): void
    {
        $owner = $this->staff('owner');
        $supplier = Supplier::create(['name' => 'Acme']);
        $p = $this->product('0', '1000', 0);

        $po = $this->actingAs($owner)->postJson('/api/admin/purchase-orders', [
            'supplier_id' => $supplier->id, 'items' => [['product_id' => $p->id, 'quantity' => 10, 'unit_cost' => '500']],
        ])->assertCreated()->json();
        $this->assertSame('5000.00', $po['total']);

        $this->actingAs($owner)->postJson('/api/admin/goods-receipts', [
            'client_uuid' => (string) Str::uuid(), 'purchase_order_id' => $po['id'], 'supplier_invoice_no' => 'INV-77',
            'items' => [['product_id' => $p->id, 'quantity' => 6, 'unit_cost' => '500', 'purchase_order_item_id' => $po['items'][0]['id']]],
        ])->assertCreated();
        $this->assertSame('partial', PurchaseOrder::find($po['id'])->status);
        $this->assertSame(6, $p->fresh()->total_stock);

        $this->actingAs($owner)->postJson('/api/admin/goods-receipts', [
            'client_uuid' => (string) Str::uuid(), 'purchase_order_id' => $po['id'],
            'items' => [['product_id' => $p->id, 'quantity' => 4, 'unit_cost' => '500', 'purchase_order_item_id' => $po['items'][0]['id']]],
        ])->assertCreated();
        $this->assertSame('received', PurchaseOrder::find($po['id'])->status);

        $pay = fn ($amount) => $this->actingAs($owner)->postJson('/api/admin/supplier-payments', [
            'client_uuid' => (string) Str::uuid(), 'supplier_id' => $supplier->id, 'payment_method_id' => $this->method('bank'), 'amount' => $amount,
        ]);
        $pay('3000')->assertCreated();
        $pay('2001')->assertStatus(422)->assertJsonPath('code', 'overpayment');
        $this->actingAs($owner)->getJson("/api/admin/suppliers/{$supplier->id}")->assertOk()->assertJsonPath('balance', '2000.00');
        $this->assertReconciles();
    }

    public function test_shift_reconciliation(): void
    {
        $cashier = $this->staff('cashier');
        $shift = $this->actingAs($cashier)->postJson('/api/admin/shifts', ['opening_float' => '10000'])->assertCreated()->json();
        $this->actingAs($cashier)->postJson('/api/admin/shifts', ['opening_float' => '10000'])->assertStatus(422);
        $p = $this->product('100', '2500', 10);
        $this->sell($cashier, [[$p, 2]], [['cash', '10000']])->assertCreated();          // 5,000 cash in (5,000 change)
        $this->sell($cashier, [[$p, 1]], [['mtn_momo', '2500', 'MM9']])->assertCreated(); // not in the drawer

        $res = $this->actingAs($cashier)->postJson("/api/admin/shifts/{$shift['id']}/close", ['counted_cash' => '14500'])->assertOk();
        $res->assertJsonPath('expected_cash', '15000.00')->assertJsonPath('shift.cash_difference', '-500.00')->assertJsonPath('sales_count', 2);
    }

    public function test_customer_can_cancel_own_order_only_before_it_is_confirmed(): void
    {
        $p = $this->product('1000', '10000', 5);
        $alice = $this->shopper();
        $a = $this->order($alice, $p, 2)->assertCreated()->assertJsonPath('order.can_cancel', true)->json('order.number');
        $this->assertSame(3, $p->fresh()->total_stock);

        // Someone else cannot cancel it.
        $this->actingAs($this->shopper())->postJson("/api/my/orders/{$a}/cancel")->assertNotFound();
        $this->actingAs($alice)->postJson("/api/my/orders/{$a}/cancel")->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('can_cancel', false);
        $this->assertSame(5, $p->fresh()->total_stock, 'stock released');

        // Once the shop has confirmed it, the customer must contact the shop.
        $b = $this->order($alice, $p, 1)->assertCreated()->json('order.number');
        $this->actingAs($this->staff('manager'))->postJson('/api/admin/orders/'.Sale::where('number', $b)->value('id').'/status', ['status' => 'confirmed'])->assertOk();
        $this->actingAs($alice)->postJson("/api/my/orders/{$b}/cancel")->assertStatus(422)->assertJsonPath('code', 'cannot_cancel');
        $this->assertReconciles();
    }
}
