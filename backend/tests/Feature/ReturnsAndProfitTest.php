<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\Supplier;
use App\Services\ReportService;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReturnsAndProfitTest extends TestCase
{
    private function returnItems($user, Sale $sale, array $lines, ?string $method = 'cash')
    {
        return $this->actingAs($user)->postJson("/api/admin/sales/{$sale->id}/returns", [
            'client_uuid' => (string) Str::uuid(), 'reason' => 'Customer changed mind',
            'payment_method_id' => $method ? $this->method($method) : null, 'items' => $lines,
        ]);
    }

    public function test_partial_return_refunds_restocks_and_reverses_cost(): void
    {
        $m = $this->staff('manager');
        $this->openShift($m);
        $p = $this->product('1000', '3000', 10);
        $this->sell($m, [[$p, 3]], [['cash', '9000']])->assertCreated();
        $sale = Sale::first();
        $item = $sale->items()->first();

        $this->returnItems($m, $sale, [['sale_item_id' => $item->id, 'quantity' => 1]])->assertCreated()
            ->assertJsonPath('return.cash_refunded', '3000.00')->assertJsonPath('return.cost_reversed', '1000.00');

        $this->assertSame(8, $p->fresh()->total_stock);
        $sale->refresh();
        $this->assertSame('3000.00', (string) $sale->refunded_total);
        $this->assertSame('6000.00', (string) $sale->amount_paid);
        // 3 sold, 1 returned: only 2 more can come back.
        $this->returnItems($m, $sale, [['sale_item_id' => $item->id, 'quantity' => 3]])->assertStatus(422)->assertJsonPath('code', 'over_return');
        $this->assertReconciles();
    }

    public function test_damaged_return_is_not_restocked_and_cost_stays_in_cogs(): void
    {
        $m = $this->staff('manager');
        $this->openShift($m);
        $p = $this->product('1000', '3000', 5);
        $this->sell($m, [[$p, 2]], [['cash', '6000']])->assertCreated();
        $sale = Sale::first();

        $this->returnItems($m, $sale, [['sale_item_id' => $sale->items()->value('id'), 'quantity' => 1, 'restock' => false]])->assertCreated()
            ->assertJsonPath('return.cost_reversed', '0.00');
        $this->assertSame(3, $p->fresh()->total_stock, 'damaged unit stays out of stock');

        $pl = app(ReportService::class)->profitAndLoss(...ReportService::range(null, null));
        $this->assertSame('3000.00', $pl['net_revenue']);
        $this->assertSame('2000.00', $pl['cogs'], 'the shop absorbed the damaged unit');
        $this->assertSame('1000.00', $pl['gross_profit']);
    }

    public function test_return_on_credit_sale_reduces_debt_before_refunding_cash(): void
    {
        $m = $this->staff('manager');
        $this->openShift($m);
        $p = $this->product('1000', '5000', 10);
        $c = $this->customer('100000');
        $this->sell($m, [[$p, 4]], [['cash', '5000']], ['customer_id' => $c->id])->assertCreated(); // 20,000 total, owes 15,000
        $sale = Sale::first();

        // Return 2 units (10,000): debt 15,000 -> 5,000, no cash handed back.
        $this->returnItems($m, $sale, [['sale_item_id' => $sale->items()->value('id'), 'quantity' => 2]], null)->assertCreated()
            ->assertJsonPath('return.cash_refunded', '0.00');
        $this->assertSame('5000.00', (string) $sale->fresh()->balance_due);

        // Return the last 2 (10,000): debt cleared and 5,000 of the paid cash comes back.
        $this->returnItems($m, $sale, [['sale_item_id' => $sale->items()->value('id'), 'quantity' => 2]])->assertCreated()
            ->assertJsonPath('return.cash_refunded', '5000.00');
        $sale->refresh();
        $this->assertSame('0.00', (string) $sale->balance_due);
        $this->assertSame('refunded', $sale->payment_status);
        $this->assertSame(10, $p->fresh()->total_stock);
        $this->assertReconciles();
    }

    public function test_cashier_cannot_process_returns(): void
    {
        $c = $this->staff('cashier');
        $this->openShift($c);
        $p = $this->product('1000', '3000', 5);
        $this->sell($c, [[$p, 1]], [['cash', '3000']])->assertCreated();
        $this->returnItems($c, Sale::first(), [['sale_item_id' => Sale::first()->items()->value('id'), 'quantity' => 1]])->assertForbidden();
    }

    public function test_profit_and_loss_matches_hand_calculation(): void
    {
        $owner = $this->staff('owner');
        $this->openShift($owner);
        $supplier = Supplier::create(['name' => 'Supplier']);
        $a = $this->product('600', '1000', 0);
        $b = $this->product('0', '5000', 0);

        // Buy stock: 20 A @ 600 = 12,000 and 4 B @ 3,000 = 12,000. NOT an expense.
        $this->actingAs($owner)->postJson('/api/admin/goods-receipts', [
            'client_uuid' => (string) Str::uuid(), 'supplier_id' => $supplier->id,
            'items' => [['product_id' => $a->id, 'quantity' => 20, 'unit_cost' => '600'], ['product_id' => $b->id, 'quantity' => 4, 'unit_cost' => '3000']],
            'payment' => ['payment_method_id' => $this->method('bank'), 'amount' => '10000', 'reference' => 'BANK1'],
        ])->assertCreated();

        // Sales: 10 A (10,000, cost 6,000) cash; 2 B with 1,000 discount (9,000, cost 6,000) MoMo.
        $this->sell($owner, [[$a, 10]], [['cash', '10000']])->assertCreated();
        $this->sell($owner, [[$b, 2, '1000']], [['mtn_momo', '9000', 'MM1']])->assertCreated();
        // Return 1 A (1,000 revenue, 600 cost back).
        $sale = Sale::orderBy('id')->first();
        $this->returnItems($owner, $sale, [['sale_item_id' => $sale->items()->value('id'), 'quantity' => 1]])->assertCreated();
        // Expense paid in cash: 2,500.
        $this->actingAs($owner)->postJson('/api/admin/expenses', [
            'client_uuid' => (string) Str::uuid(), 'expense_category_id' => \App\Models\ExpenseCategory::value('id'),
            'payment_method_id' => $this->method('cash'), 'amount' => '2500', 'description' => 'Electricity', 'expense_date' => now()->toDateString(),
        ])->assertCreated();

        $pl = $this->actingAs($owner)->getJson('/api/admin/reports/profit_loss')->assertOk()->json();
        $this->assertSame('20000.00', $pl['gross_sales']);
        $this->assertSame('1000.00', $pl['discounts']);
        $this->assertSame('1000.00', $pl['returns_value']);
        $this->assertSame('18000.00', $pl['net_revenue']);   // 10,000 + 9,000 − 1,000
        $this->assertSame('11400.00', $pl['cogs']);          // 6,000 + 6,000 − 600
        $this->assertSame('6600.00', $pl['gross_profit']);
        $this->assertSame('2500.00', $pl['expenses']);
        $this->assertSame('4100.00', $pl['net_profit']);     // stock purchase (24,000) not deducted

        $inv = $this->actingAs($owner)->getJson('/api/admin/reports/inventory')->json();
        $this->assertSame('12600.00', $inv['cost_value']);   // 11 A × 600 + 2 B × 3,000
        $this->assertReconciles();
    }

    public function test_inventory_and_balances_report(): void
    {
        $owner = $this->staff('owner');
        $this->openShift($owner);
        $supplier = Supplier::create(['name' => 'Supplier', 'opening_balance' => '5000']);
        $a = $this->product('600', '1000', 10);
        $this->actingAs($owner)->postJson('/api/admin/goods-receipts', [
            'client_uuid' => (string) Str::uuid(), 'supplier_id' => $supplier->id,
            'items' => [['product_id' => $a->id, 'quantity' => 10, 'unit_cost' => '600']],
        ])->assertCreated();
        $c = $this->customer('50000');
        $this->sell($owner, [[$a, 5]], [['cash', '1000']], ['customer_id' => $c->id])->assertCreated();

        $inv = $this->actingAs($owner)->getJson('/api/admin/reports/inventory')->json();
        $this->assertSame(15, $inv['units']);
        $this->assertSame('9000.00', $inv['cost_value']);
        $this->assertSame('15000.00', $inv['retail_value']);
        $bal = $this->actingAs($owner)->getJson('/api/admin/reports/balances')->json();
        $this->assertSame('4000.00', $bal['receivable']);
        $this->assertSame('11000.00', $bal['payable']); // 5,000 opening + 6,000 received
    }

    public function test_cash_flow_separates_credit_from_cash(): void
    {
        $owner = $this->staff('owner');
        $this->openShift($owner);
        $a = $this->product('600', '1000', 10);
        $c = $this->customer('50000');
        $this->sell($owner, [[$a, 5]], [['cash', '2000']], ['customer_id' => $c->id])->assertCreated();

        $cf = $this->actingAs($owner)->getJson('/api/admin/reports/cash_flow')->json();
        $this->assertSame('2000.00', $cf['totals']['received'], 'credit portion is not cash');
        $pl = $this->actingAs($owner)->getJson('/api/admin/reports/profit_loss')->json();
        $this->assertSame('5000.00', $pl['net_revenue'], 'credit sale is still revenue');
    }
}
