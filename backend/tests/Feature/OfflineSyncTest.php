<?php

namespace Tests\Feature;

use App\Models\OfflineConflict;
use App\Models\Sale;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfflineSyncTest extends TestCase
{
    private function offlineSale($product, int $qty, string $paid, string $method = 'cash', array $extra = []): array
    {
        return array_merge([
            'client_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => $qty]],
            'payments' => [['payment_method_id' => $this->method($method), 'amount' => $paid, 'reference' => $method === 'cash' ? null : 'X']],
            'offline_created_at' => now()->subMinutes(10)->toDateTimeString(),
        ], $extra);
    }

    public function test_queued_sales_sync_once_even_when_replayed(): void
    {
        $cashier = $this->staff();
        $shift = $this->openShift($cashier);
        $p = $this->product('100', '1000', 10);
        $a = $this->offlineSale($p, 2, '2000', extra: ['shift_id' => $shift->id]);
        $b = $this->offlineSale($p, 1, '1000');

        $res = $this->actingAs($cashier)->postJson('/api/admin/pos/sync', ['sales' => [$a, $b]])->assertOk();
        $this->assertSame(['synced', 'synced'], array_column($res->json('results'), 'status'));

        // Connection dropped before the client saw the response: it uploads the same queue again.
        $res = $this->actingAs($cashier)->postJson('/api/admin/pos/sync', ['sales' => [$a, $b]])->assertOk();
        $this->assertSame(['duplicate', 'duplicate'], array_column($res->json('results'), 'status'));

        $this->assertSame(2, Sale::count());
        $this->assertSame(7, $p->fresh()->total_stock);
        $this->assertTrue(Sale::first()->offline);
        $this->assertSame($shift->id, Sale::first()->shift_id);
        $this->assertReconciles();
    }

    public function test_offline_sale_that_no_longer_fits_stock_is_parked_not_deducted(): void
    {
        $cashier = $this->staff();
        $this->openShift($cashier);
        $p = $this->product('100', '1000', 3);
        $offline = $this->offlineSale($p, 2, '2000');
        // Meanwhile an online till sold 2 of the 3.
        $this->sell($cashier, [[$p, 2]], [['cash', '2000']])->assertCreated();

        $res = $this->actingAs($cashier)->postJson('/api/admin/pos/sync', ['sales' => [$offline]])->assertOk();
        $this->assertSame('conflict', $res->json('results.0.status'));
        $this->assertSame(1, $p->fresh()->total_stock, 'nothing deducted for the conflicting sale');
        $this->assertSame(1, OfflineConflict::where('status', 'open')->count());

        // Manager finds 1 more unit in a count, then retries the parked sale.
        $manager = $this->staff('manager');
        $this->actingAs($manager)->postJson('/api/admin/inventory/adjust', [
            'type' => 'count', 'reason' => 'Shelf recount', 'lines' => [['product_id' => $p->id, 'quantity' => 2]],
        ])->assertOk();
        $conflict = OfflineConflict::first();
        $this->actingAs($manager)->postJson("/api/admin/offline-conflicts/{$conflict->id}/retry")->assertOk()->assertJsonPath('status', 'resolved');
        $this->assertSame(0, $p->fresh()->total_stock);
        $this->assertSame($cashier->id, Sale::latest('id')->first()->user_id, 'sale stays attributed to the cashier who made it');

        // Replaying the original upload now reports the resolved sale as a duplicate.
        $res = $this->actingAs($cashier)->postJson('/api/admin/pos/sync', ['sales' => [$offline]])->assertOk();
        $this->assertSame('duplicate', $res->json('results.0.status'));
        $this->assertReconciles();
    }

    public function test_offline_limits_are_enforced_on_sync(): void
    {
        $this->setting(['offline_max_sale_total' => '5000']);
        $cashier = $this->staff();
        $this->openShift($cashier);
        $p = $this->product('100', '3000', 10);

        $res = $this->actingAs($cashier)->postJson('/api/admin/pos/sync', ['sales' => [
            $this->offlineSale($p, 2, '6000'),                     // over the limit
            $this->offlineSale($p, 1, '3000', 'mtn_momo'),         // non-cash offline
        ]])->assertOk();
        $this->assertSame(['conflict', 'conflict'], array_column($res->json('results'), 'status'));
        $this->assertSame(10, $p->fresh()->total_stock);
    }

    public function test_invalid_payload_is_rejected_individually(): void
    {
        $cashier = $this->staff();
        $this->openShift($cashier);
        $p = $this->product('100', '1000', 10);
        $good = $this->offlineSale($p, 1, '1000');
        $bad = ['client_uuid' => 'not-a-uuid', 'items' => []];

        $res = $this->actingAs($cashier)->postJson('/api/admin/pos/sync', ['sales' => [$bad, $good]])->assertOk();
        $this->assertSame(['rejected', 'synced'], array_column($res->json('results'), 'status'));
    }
}
