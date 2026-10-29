<?php

namespace App\Console\Commands;

use App\Exceptions\BusinessRuleException;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\SaleService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * One checkout attempt, used by scripts/concurrency-test.php which launches many of these
 * at the same instant (separate processes = separate DB connections, like separate tills).
 * Prints one line: OK <number> | REPLAY <number> | REJECT <code>.
 */
class ConcurrencyProbe extends Command
{
    protected $signature = 'shop:probe-sale {user?} {product?} {qty=1} {--uuid=} {--start-at=} {--setup} {--reconcile}';

    protected $description = 'Testing aid: attempt a single cash sale (refuses to run in production)';

    public function handle(SaleService $sales): int
    {
        if (app()->isProduction()) {
            $this->error('Not available in production');

            return 1;
        }
        if ($this->option('reconcile')) {
            $this->line(json_encode(app(\App\Services\ReportService::class)->reconcile()));

            return 0;
        }
        if ($this->option('setup')) {
            // Fixture: a cashier with an open shift and a product with exactly 10 in stock.
            $u = new User(['name' => 'Probe Cashier', 'email' => 'probe'.Str::random(6).'@x.local', 'password' => Str::random(20)]);
            $u->role = 'cashier';
            $u->save();
            auth()->setUser($u);
            $loc = $sales->resolveLocation(null);
            \App\Models\Shift::create(['user_id' => $u->id, 'location_id' => $loc->id, 'opening_float' => 0, 'opened_at' => now(), 'status' => 'open']);
            $p = new \App\Models\Product(['name' => 'Contested item', 'selling_price' => 1000]);
            $p->sku = 'CONTESTED-'.Str::random(4);
            $p->slug = 'contested-'.Str::lower(Str::random(6));
            $p->save();
            \Illuminate\Support\Facades\DB::transaction(fn () => app(\App\Services\InventoryService::class)
                ->move('opening', $loc->id, [['product_id' => $p->id, 'quantity' => 10, 'unit_cost' => '400']], $p, 'probe'));
            $this->line("FIXTURE {$u->id} {$p->id}");

            return 0;
        }
        $user = User::findOrFail($this->argument('user'));
        auth()->setUser($user);
        if ($t = (float) $this->option('start-at')) {
            // Spin until the shared start instant so all processes hit the DB together.
            while (microtime(true) < $t) {
                usleep(200);
            }
        }
        try {
            $r = $sales->checkout([
                'client_uuid' => $this->option('uuid') ?: (string) Str::uuid(),
                'items' => [['product_id' => (int) $this->argument('product'), 'quantity' => (int) $this->argument('qty')]],
                'payments' => [['payment_method_id' => PaymentMethod::where('code', 'cash')->value('id'), 'amount' => '100000000']],
            ], $user);
            $this->line(($r['replayed'] ? 'REPLAY ' : 'OK ').$r['sale']->number);
        } catch (BusinessRuleException $e) {
            $this->line('REJECT '.$e->errorCode);
        }

        return 0;
    }
}
