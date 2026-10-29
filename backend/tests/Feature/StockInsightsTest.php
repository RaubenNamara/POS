<?php

namespace Tests\Feature;

use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Stock insights: stock left, selling speed, days left, restock list, most used, profit and losses. */
class StockInsightsTest extends TestCase
{
    public function test_speed_days_left_restock_profit_and_losses(): void
    {
        $this->setting(['require_shift' => '0']);
        $fast = $this->product('1000', '1500', 40, ['min_stock' => 5]);   // sells 30 in the period
        $slow = $this->product('2000', '3000', 10, ['min_stock' => 2]);   // never sells
        $owner = $this->staff('owner');

        $this->sell($owner, [[$fast, 30]], [['cash', '45000']])->assertCreated();
        // 2 units broken: a loss at cost (2 x 1000)
        DB::transaction(fn () => app(InventoryService::class)->move('damaged', $this->locationId(), [['product_id' => $fast->id, 'quantity' => -2]], null, 'dropped'));

        $r = $this->actingAs($owner)->getJson('/api/admin/reports/stock_insights?days=30')->assertOk()->json();
        $row = collect($r['products'])->firstWhere('id', $fast->id);

        $this->assertSame(8, $row['stock']);                 // 40 - 30 sold - 2 damaged
        $this->assertSame(30, $row['used']);
        $this->assertEquals(1.0, $row['per_day']);           // 30 used over 30 days
        $this->assertSame(8, $row['days_left']);             // 8 left at 1 a day
        $this->assertSame('running_out', $row['status']);
        $this->assertSame(22, $row['reorder']);              // 30 days of sales (30) minus the 8 on hand
        $this->assertSame($fast->id, $r['restock'][0]['id']);
        $this->assertSame($fast->id, $r['most_used'][0]['id']);

        $this->assertSame('not_selling', collect($r['products'])->firstWhere('id', $slow->id)['status']);

        $this->assertEquals('15000.00', $r['profit']['gross_profit']); // 30 x (1500 - 1000)
        $this->assertEquals('2000.00', $r['losses']['write_off_total']);
        $this->assertEquals('2000.00', $r['losses']['total']);
        $this->assertSame('damaged', $r['losses']['write_offs'][0]['reason']);
    }

    public function test_stock_staff_see_stock_figures_but_not_money(): void
    {
        $this->product('1000', '1500', 5);
        $stock = $this->staff('stock_manager');
        $allowed = $stock->can('reports.operational');
        $res = $this->actingAs($stock)->getJson('/api/admin/reports/stock_insights');
        if (! $allowed) {
            $res->assertForbidden();

            return;
        }
        $r = $res->assertOk()->json();
        $this->assertFalse($r['financial']);
        $this->assertArrayNotHasKey('profit', $r);
        $this->assertArrayNotHasKey('losses', $r);
        $this->assertArrayNotHasKey('cost_value', $r['summary']);
        $this->assertNull($r['products'][0]['stock_value']);
    }
}
