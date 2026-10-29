<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\StockMovement;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosCheckoutTest extends TestCase
{
    public function test_cash_sale_deducts_stock_records_cost_and_change(): void
    {
        $cashier = $this->staff('cashier');
        $this->openShift($cashier);
        $p = $this->product('1000', '1500', 10);

        $res = $this->sell($cashier, [[$p, 3]], [['cash', '5000']])->assertCreated();

        $res->assertJsonPath('sale.total', '4500.00')
            ->assertJsonPath('sale.change_given', '500.00')
            ->assertJsonPath('sale.amount_paid', '4500.00')
            ->assertJsonPath('sale.is_final_paid', true);
        $this->assertSame(7, $p->fresh()->total_stock);
        $sale = Sale::first();
        $this->assertSame('3000.00', (string) $sale->cost_total);
        $m = StockMovement::where('type', 'sale')->first();
        $this->assertSame(-3, $m->quantity);
        $this->assertSame(7, $m->balance_after);
        $this->assertSame($cashier->id, $m->user_id);
        $this->assertSame('sale', $m->reference_type);
        $this->assertReconciles();
    }

    public function test_client_cannot_set_prices_or_totals(): void
    {
        $cashier = $this->staff();
        $this->openShift($cashier);
        $p = $this->product('1000', '1500', 5);

        $this->sell($cashier, [[$p, 1]], [['cash', '1500']], [
            'total' => '1', 'subtotal' => '1', 'items' => [['product_id' => $p->id, 'quantity' => 1, 'unit_price' => '1', 'cost' => '0']],
        ])->assertCreated()->assertJsonPath('sale.total', '1500.00')->assertJsonPath('sale.items.0.unit_price', '1500.00');
    }

    public function test_insufficient_stock_is_rejected_atomically(): void
    {
        $cashier = $this->staff();
        $this->openShift($cashier);
        $a = $this->product('100', '200', 5);
        $b = $this->product('100', '200', 1);

        $this->sell($cashier, [[$a, 2], [$b, 2]], [['cash', '800']])
            ->assertStatus(422)->assertJsonPath('code', 'insufficient_stock');

        $this->assertSame(0, Sale::count());
        $this->assertSame(5, $a->fresh()->total_stock, 'no partial deduction');
        $this->assertSame(1, $b->fresh()->total_stock);
    }

    public function test_same_product_on_two_lines_is_checked_against_combined_quantity(): void
    {
        $cashier = $this->staff();
        $this->openShift($cashier);
        $p = $this->product('100', '200', 3);
        $this->sell($cashier, [[$p, 2], [$p, 2]], [['cash', '800']])->assertStatus(422);
        $this->assertSame(3, $p->fresh()->total_stock);
    }

    public function test_duplicate_submission_is_processed_once(): void
    {
        $cashier = $this->staff();
        $this->openShift($cashier);
        $p = $this->product('100', '200', 10);
        $uuid = (string) Str::uuid();

        $first = $this->sell($cashier, [[$p, 2]], [['cash', '400']], ['client_uuid' => $uuid])->assertCreated();
        $second = $this->sell($cashier, [[$p, 2]], [['cash', '400']], ['client_uuid' => $uuid])->assertOk();

        $second->assertJsonPath('replayed', true)->assertJsonPath('sale.number', $first->json('sale.number'));
        $this->assertSame(1, Sale::count());
        $this->assertSame(8, $p->fresh()->total_stock);
    }

    public function test_bundle_sale_deducts_every_component_and_costs_them(): void
    {
        $cashier = $this->staff();
        $this->openShift($cashier);
        $laptop = $this->product('1000000', '1300000', 5);
        $mouse = $this->product('10000', '25000', 20);
        $bag = $this->product('20000', '40000', 2);
        $bundle = $this->bundle([[$laptop, 1], [$mouse, 2], [$bag, 1]], '1350000');

        $this->sell($cashier, [[$bundle, 2]], [['cash', '2700000']])->assertCreated()->assertJsonPath('sale.total', '2700000.00');

        $this->assertSame(3, $laptop->fresh()->total_stock);
        $this->assertSame(16, $mouse->fresh()->total_stock);
        $this->assertSame(0, $bag->fresh()->total_stock);
        $this->assertSame('2080000.00', (string) Sale::first()->cost_total); // 2 × (1,000,000 + 2×10,000 + 20,000)
        $this->assertSame(0, $bundle->fresh()->total_stock, 'bundle holds no stock of its own');

        // Bag is now gone, so another bundle cannot be sold.
        $this->sell($cashier, [[$bundle, 1]], [['cash', '1350000']])->assertStatus(422)->assertJsonPath('code', 'insufficient_stock');
        $this->assertReconciles();
    }

    public function test_split_payment_rules(): void
    {
        $cashier = $this->staff();
        $this->openShift($cashier);
        $p = $this->product('1000', '10000', 10);

        // Mobile money must carry a reference.
        $this->sell($cashier, [[$p, 1]], [['mtn_momo', '10000']])->assertStatus(422)->assertJsonPath('code', 'reference_required');
        // Mobile money cannot exceed what is due (no change from MoMo).
        $this->sell($cashier, [[$p, 1]], [['mtn_momo', '12000', 'TX1']])->assertStatus(422)->assertJsonPath('code', 'overpayment');
        // 6,000 MoMo + 5,000 cash on a 10,000 sale: 1,000 change from the cash only.
        $this->sell($cashier, [[$p, 1]], [['cash', '5000'], ['mtn_momo', '6000', 'TX2']])->assertCreated()
            ->assertJsonPath('sale.change_given', '1000.00')->assertJsonPath('sale.payment_status', 'paid');
    }

    public function test_credit_sales_need_a_customer_with_allowance(): void
    {
        $cashier = $this->staff();
        $this->openShift($cashier);
        $p = $this->product('1000', '10000', 10);
        $noCredit = $this->customer('0');
        $credit = $this->customer('15000');

        $this->sell($cashier, [[$p, 1]], [['cash', '4000']])->assertStatus(422)->assertJsonPath('code', 'credit_needs_customer');
        $this->sell($cashier, [[$p, 1]], [['cash', '4000']], ['customer_id' => $noCredit->id])->assertStatus(422)->assertJsonPath('code', 'credit_not_allowed');

        $this->sell($cashier, [[$p, 1]], [['cash', '4000']], ['customer_id' => $credit->id])->assertCreated()
            ->assertJsonPath('sale.balance_due', '6000.00')->assertJsonPath('sale.payment_status', 'partial')->assertJsonPath('sale.is_final_paid', false);
        // 6,000 owed + 10,000 more would exceed the 15,000 limit.
        $this->sell($cashier, [[$p, 1]], [], ['customer_id' => $credit->id])->assertStatus(422)->assertJsonPath('code', 'credit_limit');
        $this->assertSame(9, $p->fresh()->total_stock);
    }

    public function test_collecting_customer_debt_allocates_oldest_first_and_is_idempotent(): void
    {
        $cashier = $this->staff();
        $this->openShift($cashier);
        $p = $this->product('1000', '10000', 10);
        $c = $this->customer('100000');
        $this->sell($cashier, [[$p, 1]], [], ['customer_id' => $c->id])->assertCreated();
        $this->sell($cashier, [[$p, 1]], [['cash', '2000']], ['customer_id' => $c->id])->assertCreated();

        $uuid = (string) Str::uuid();
        $body = ['client_uuid' => $uuid, 'payment_method_id' => $this->method('cash'), 'amount' => '13000'];
        $this->actingAs($cashier)->postJson("/api/admin/customers/{$c->id}/collect", $body)->assertCreated()->assertJsonPath('balance', '5000.00');
        $this->actingAs($cashier)->postJson("/api/admin/customers/{$c->id}/collect", $body)->assertCreated()->assertJsonPath('balance', '5000.00');

        [$s1, $s2] = Sale::orderBy('id')->get();
        $this->assertSame('0.00', (string) $s1->balance_due);
        $this->assertSame('paid', $s1->payment_status);
        $this->assertSame('5000.00', (string) $s2->balance_due);

        $this->actingAs($cashier)->postJson("/api/admin/customers/{$c->id}/collect", ['client_uuid' => (string) Str::uuid(), 'payment_method_id' => $this->method('cash'), 'amount' => '5001'])
            ->assertStatus(422)->assertJsonPath('code', 'overpayment');
        $this->assertReconciles();
    }

    public function test_cashier_discount_is_capped_but_manager_is_not(): void
    {
        $this->setting(['max_cashier_discount_pct' => '10']);
        $cashier = $this->staff('cashier');
        $manager = $this->staff('manager');
        $this->openShift($cashier);
        $this->openShift($manager);
        $p = $this->product('500', '10000', 10);

        $this->sell($cashier, [[$p, 1, '1000']], [['cash', '9000']])->assertCreated()->assertJsonPath('sale.total', '9000.00');
        $this->sell($cashier, [[$p, 1, '2000']], [['cash', '8000']])->assertStatus(422)->assertJsonPath('code', 'discount_limit');
        $this->sell($cashier, [[$p, 1]], [['cash', '8000']], ['discount' => '2000'])->assertStatus(422)->assertJsonPath('code', 'discount_limit');
        $this->sell($manager, [[$p, 1, '3000']], [['cash', '7000']])->assertCreated();
        // A discount can never exceed the line value, even for a manager.
        $this->sell($manager, [[$p, 1, '10001']], [])->assertStatus(422)->assertJsonPath('code', 'invalid_discount');
    }

    public function test_cart_discount_is_spread_across_lines(): void
    {
        $manager = $this->staff('manager');
        $this->openShift($manager);
        $a = $this->product('100', '3000', 5);
        $b = $this->product('100', '1000', 5);

        $res = $this->sell($manager, [[$a, 1], [$b, 1]], [['cash', '3000']], ['discount' => '1000'])->assertCreated();
        $res->assertJsonPath('sale.total', '3000.00')->assertJsonPath('sale.discount_total', '1000.00');
        $this->assertSame(['750.00', '250.00'], collect($res->json('sale.items'))->pluck('discount')->all());
    }

    public function test_selling_requires_an_open_shift(): void
    {
        $cashier = $this->staff();
        $p = $this->product('100', '200', 5);
        $this->sell($cashier, [[$p, 1]], [['cash', '200']])->assertStatus(422)->assertJsonPath('code', 'no_shift');
        $this->assertSame(5, $p->fresh()->total_stock);
    }

    public function test_exclusive_tax_is_added_and_inclusive_tax_is_extracted(): void
    {
        $cashier = $this->staff();
        $this->openShift($cashier);
        $p = $this->product('1000', '10000', 10);

        $this->setting(['tax_rate' => '18', 'tax_inclusive' => '0']);
        $this->sell($cashier, [[$p, 1]], [['cash', '11800']])->assertCreated()
            ->assertJsonPath('sale.total', '11800.00')->assertJsonPath('sale.tax_total', '1800.00');

        $this->setting(['tax_rate' => '18', 'tax_inclusive' => '1']);
        $this->sell($cashier, [[$p, 1]], [['cash', '10000']])->assertCreated()
            ->assertJsonPath('sale.total', '10000.00')->assertJsonPath('sale.tax_total', '1525.42');
    }

    public function test_weighted_average_cost_is_used_for_cogs(): void
    {
        $owner = $this->staff('owner');
        $this->openShift($owner);
        $p = $this->product('1000', '3000', 10); // 10 @ 1,000

        $this->actingAs($owner)->postJson('/api/admin/goods-receipts', [
            'client_uuid' => (string) Str::uuid(), 'supplier_id' => \App\Models\Supplier::create(['name' => 'S'])->id,
            'items' => [['product_id' => $p->id, 'quantity' => 10, 'unit_cost' => '2000']], // 10 @ 2,000
        ])->assertCreated();
        $this->assertSame('1500.0000', (string) $p->fresh()->cost_price);

        $this->sell($owner, [[$p, 5]], [['cash', '15000']])->assertCreated();
        $this->assertSame('7500.00', (string) Sale::first()->cost_total);
        // Historical cost is frozen: a later, pricier purchase does not change that sale's COGS.
        $this->actingAs($owner)->postJson('/api/admin/goods-receipts', [
            'client_uuid' => (string) Str::uuid(), 'supplier_id' => \App\Models\Supplier::first()->id,
            'items' => [['product_id' => $p->id, 'quantity' => 5, 'unit_cost' => '4000']],
        ])->assertCreated();
        $this->assertSame('7500.00', (string) Sale::first()->fresh()->cost_total);
        $this->assertSame('2125.0000', (string) $p->fresh()->cost_price); // (15×1500 + 5×4000) / 20
        $this->assertReconciles();
    }
}
