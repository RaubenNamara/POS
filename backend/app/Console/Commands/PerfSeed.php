<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bulk synthetic data for load/latency testing ONLY (refuses production, refuses a DB that
 * already has sales). Inserts in large batches directly, so it is fast; numbers are
 * internally consistent per sale but this is not a ledger to keep.
 *
 *   DB_DATABASE=shop_perf php artisan migrate:fresh --seed && DB_DATABASE=shop_perf php artisan shop:perf-seed
 */
class PerfSeed extends Command
{
    protected $signature = 'shop:perf-seed {--products=10000} {--sales=60000} {--customers=3000}';

    protected $description = 'Testing aid: fill a throwaway database with a large synthetic catalog and sales history';

    public function handle(): int
    {
        if (app()->isProduction() || DB::table('sales')->exists()) {
            $this->error('Refusing: production environment or database already has sales.');

            return 1;
        }
        $now = now();
        $loc = DB::table('locations')->value('id');
        $user = DB::table('users')->value('id');
        $methods = DB::table('payment_methods')->pluck('id')->all();

        $cats = [];
        foreach (range(1, 40) as $i) {
            $cats[] = DB::table('categories')->insertGetId(['name' => "Perf category {$i}", 'slug' => "perf-category-{$i}", 'sort_order' => $i, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        }
        $words = ['Phone', 'Laptop', 'Charger', 'Cable', 'Speaker', 'Kettle', 'Shirt', 'Shoe', 'Watch', 'Bag', 'Lamp', 'Fan', 'Blender', 'Router', 'Mouse', 'Keyboard', 'Monitor', 'Printer', 'Battery', 'Solar'];
        $adj = ['Pro', 'Max', 'Lite', 'Plus', 'Mini', 'Ultra', 'Classic', 'Smart', 'Eco', 'Prime'];

        $this->info('Products…');
        $bar = $this->output->createProgressBar((int) $this->option('products'));
        foreach (array_chunk(range(1, (int) $this->option('products')), 1000) as $chunk) {
            $rows = [];
            foreach ($chunk as $i) {
                $cost = random_int(2, 3000) * 1000;
                $name = $words[array_rand($words)].' '.$adj[array_rand($adj)].' '.Str::upper(Str::random(3)).$i;
                $rows[] = ['type' => 'standard', 'category_id' => $cats[array_rand($cats)], 'name' => $name, 'slug' => Str::slug($name),
                    'sku' => 'P'.str_pad((string) $i, 7, '0', STR_PAD_LEFT), 'barcode' => '7'.str_pad((string) $i, 12, '0', STR_PAD_LEFT),
                    'cost_price' => $cost, 'selling_price' => (int) ($cost * 1.3), 'compare_at_price' => $i % 7 === 0 ? (int) ($cost * 1.5) : null,
                    'min_stock' => 5, 'total_stock' => random_int(0, 200), 'is_active' => 1, 'show_online' => 1, 'is_featured' => $i % 50 === 0 ? 1 : 0,
                    'created_at' => $now, 'updated_at' => $now];
            }
            DB::table('products')->insert($rows);
            $bar->advance(count($chunk));
        }
        $bar->finish();
        DB::statement("INSERT INTO stock_levels (product_id, location_id, quantity, created_at, updated_at) SELECT id, {$loc}, total_stock, NOW(), NOW() FROM products WHERE sku LIKE 'P0%'");
        DB::statement("INSERT INTO stock_movements (product_id, location_id, type, quantity, balance_after, unit_cost, reason, created_at) SELECT id, {$loc}, 'opening', total_stock, total_stock, cost_price, 'perf seed', NOW() FROM products WHERE sku LIKE 'P0%'");

        $this->newLine();
        $this->info('Customers…');
        $cust = [];
        foreach (range(1, (int) $this->option('customers')) as $i) {
            $cust[] = ['name' => "Customer {$i}", 'phone' => '07'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), 'credit_limit' => 500000, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($cust, 1000) as $c) {
            DB::table('customers')->insert($c);
        }
        $custIds = DB::table('customers')->pluck('id')->all();
        $prices = DB::table('products')->pluck('selling_price', 'id')->all();
        $costs = DB::table('products')->pluck('cost_price', 'id')->all();
        $pids = array_keys($prices);

        $this->info('Sales (last 365 days)…');
        $n = (int) $this->option('sales');
        $bar = $this->output->createProgressBar($n);
        $saleId = 0;
        for ($done = 0; $done < $n; $done += 500) {
            $sales = $items = $pays = [];
            for ($k = 0; $k < min(500, $n - $done); $k++) {
                $saleId++;
                $when = $now->copy()->subSeconds(random_int(0, 365 * 86400))->toDateTimeString();
                $total = 0;
                $cost = 0;
                foreach (range(1, random_int(1, 4)) as $_) {
                    $pid = $pids[array_rand($pids)];
                    $q = random_int(1, 3);
                    $amt = $prices[$pid] * $q;
                    $total += $amt;
                    $cost += $costs[$pid] * $q;
                    $items[] = ['sale_id' => $saleId, 'product_id' => $pid, 'name' => 'Item '.$pid, 'sku' => 'P'.$pid, 'quantity' => $q, 'unit_price' => $prices[$pid],
                        'line_total' => $amt, 'amount' => $amt, 'unit_cost' => $costs[$pid], 'cost_total' => $costs[$pid] * $q, 'created_at' => $when, 'updated_at' => $when];
                }
                $credit = $saleId % 20 === 0;
                $paid = $credit ? (int) ($total / 2) : $total;
                $sales[] = ['id' => $saleId, 'client_uuid' => (string) Str::uuid(), 'number' => 'S'.str_pad((string) $saleId, 7, '0', STR_PAD_LEFT), 'channel' => 'pos',
                    'status' => 'completed', 'payment_status' => $credit ? 'partial' : 'paid', 'location_id' => $loc, 'user_id' => $user,
                    'customer_id' => $credit || $saleId % 3 === 0 ? $custIds[array_rand($custIds)] : null, 'subtotal' => $total, 'total' => $total,
                    'amount_paid' => $paid, 'balance_due' => $total - $paid, 'cost_total' => $cost, 'completed_at' => $when, 'created_at' => $when, 'updated_at' => $when];
                $pays[] = ['sale_id' => $saleId, 'payment_method_id' => $methods[array_rand($methods)], 'kind' => 'payment', 'amount' => $paid, 'user_id' => $user,
                    'paid_at' => $when, 'created_at' => $when, 'updated_at' => $when];
            }
            DB::table('sales')->insert($sales);
            DB::table('sale_items')->insert($items);
            DB::table('payments')->insert($pays);
            $bar->advance(count($sales));
        }
        $bar->finish();
        $this->newLine();
        $this->info(sprintf('Done: %d products, %d sales, %d sale lines.', count($pids), $saleId, DB::table('sale_items')->count()));

        return 0;
    }
}
