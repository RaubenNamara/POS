<?php

namespace Tests\Feature;

use App\Models\Sale;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardCacheTest extends TestCase
{
    public function test_cached_dashboard_reflects_new_sales_and_returns_immediately(): void
    {
        config(['cache.default' => 'file']); // exercise a real persistent cache, not the per-request array store
        Cache::flush();
        $owner = $this->staff('owner');
        $this->openShift($owner);
        $p = $this->product('1000', '5000', 10);

        $this->actingAs($owner)->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('summary.today.sales_count', 0);
        $this->sell($owner, [[$p, 2]], [['cash', '10000']])->assertCreated();
        $this->actingAs($owner)->getJson('/api/admin/dashboard')->assertOk()
            ->assertJsonPath('summary.today.sales_count', 1)->assertJsonPath('summary.today.net_revenue', '10000.00');

        $sale = Sale::first();
        $this->actingAs($owner)->postJson("/api/admin/sales/{$sale->id}/returns", [
            'client_uuid' => (string) Str::uuid(), 'reason' => 'test', 'payment_method_id' => $this->method('cash'),
            'items' => [['sale_item_id' => $sale->items()->value('id'), 'quantity' => 1]],
        ])->assertCreated();
        $this->actingAs($owner)->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('summary.today.net_revenue', '5000.00');
        Cache::flush();
    }
}
