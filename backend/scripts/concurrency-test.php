<?php

/**
 * Concurrency acceptance test against a throwaway database (default: shop_concurrency).
 *
 *   php scripts/concurrency-test.php [processes=24]
 *
 * Scenario A: N tills each try to sell 1 unit of a product that has only 10 in stock.
 *             Expect exactly 10 OK, N-10 REJECT insufficient_stock, final stock 0.
 * Scenario B: N retries of the SAME sale (one idempotency key) arrive at once.
 *             Expect exactly 1 sale created, all others REPLAY, stock reduced once.
 * Then the reconciliation check must report no problems.
 */
$n = (int) ($argv[1] ?? 24);
$db = getenv('PROBE_DB') ?: 'shop_concurrency';
$root = dirname(__DIR__);
$php = PHP_BINARY;
$env = array_merge(getenv(), ['DB_DATABASE' => $db, 'APP_ENV' => 'local', 'CACHE_STORE' => 'array', 'LOG_CHANNEL' => 'stderr']);

function run(string $cmd, array $env, string $cwd): string
{
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
    $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    proc_close($p);

    return trim($out);
}

$mysql = 'C:\\xampp\\mysql\\bin\\mysql.exe';
run("\"{$mysql}\" -uroot -e \"DROP DATABASE IF EXISTS {$db}; CREATE DATABASE {$db} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci\"", $env, $root);
echo run("\"{$php}\" artisan migrate --seed --force", $env, $root) ? "migrated {$db}\n" : '';

// Fixture: cashier with open shift + product with 10 in stock (via the real inventory service).
preg_match('/FIXTURE (\d+) (\d+)/', run("\"{$php}\" artisan shop:probe-sale --setup", $env, $root), $fx);
[$userId, $productId] = [(int) ($fx[1] ?? 0), (int) ($fx[2] ?? 0)];
echo "fixture: user {$userId}, product {$productId} (stock 10)\n";

function burst(int $n, string $base, array $env, string $root): array
{
    $start = microtime(true) + 3.0; // give every process time to boot Laravel first
    $procs = [];
    for ($i = 0; $i < $n; $i++) {
        $proc = proc_open($base." --start-at={$start}", [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
        $procs[] = [$proc, $pipes];
    }
    $lines = [];
    foreach ($procs as [$proc, $pipes]) {
        $lines[] = trim(stream_get_contents($pipes[1]).' '.stream_get_contents($pipes[2]));
        proc_close($proc);
    }

    return $lines;
}

function tally(array $lines): array
{
    $t = [];
    foreach ($lines as $l) {
        $k = preg_match('/^(OK|REPLAY|REJECT \w+)/', $l, $m) ? $m[1] : 'ERROR: '.substr($l, 0, 160);
        $t[$k] = ($t[$k] ?? 0) + 1;
    }
    ksort($t);

    return $t;
}

$q = fn (string $sql) => trim(run("\"{$mysql}\" -uroot -N {$db} -e \"{$sql}\"", $env, $root));

echo "\nScenario A: {$n} simultaneous single-unit sales, 10 in stock\n";
$a = tally(burst($n, "\"{$php}\" artisan shop:probe-sale {$userId} {$productId} 1", $env, $root));
print_r($a);
$stockA = (int) $q("SELECT total_stock FROM products WHERE id={$productId}");
$salesA = (int) $q('SELECT COUNT(*) FROM sales');
$passA = ($a['OK'] ?? 0) === 10 && ($a['REJECT insufficient_stock'] ?? 0) === $n - 10 && $stockA === 0 && $salesA === 10;
echo "stock now {$stockA}, sales {$salesA} => ".($passA ? 'PASS' : 'FAIL')."\n";

// Restock 5 for scenario B.
$q("UPDATE products SET total_stock = total_stock + 5 WHERE id={$productId}; UPDATE stock_levels SET quantity = quantity + 5 WHERE product_id={$productId}; INSERT INTO stock_movements (product_id, location_id, type, quantity, balance_after, unit_cost, reason, created_at) VALUES ({$productId}, 1, 'adjustment', 5, 5, 400, 'probe restock', NOW())");
$uuid = sprintf('%04x%04x-%04x-4%03x-a%03x-%04x%04x%04x', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 4095), mt_rand(0, 4095), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
echo "\nScenario B: {$n} simultaneous retries of one sale (uuid {$uuid})\n";
$b = tally(burst($n, "\"{$php}\" artisan shop:probe-sale {$userId} {$productId} 2 --uuid={$uuid}", $env, $root));
print_r($b);
$stockB = (int) $q("SELECT total_stock FROM products WHERE id={$productId}");
$dupes = (int) $q("SELECT COUNT(*) FROM sales WHERE client_uuid='{$uuid}'");
$passB = ($b['OK'] ?? 0) === 1 && ($b['REPLAY'] ?? 0) === $n - 1 && $dupes === 1 && $stockB === 3;
echo "stock now {$stockB}, sales with that key {$dupes} => ".($passB ? 'PASS' : 'FAIL')."\n";

$recon = run("\"{$php}\" artisan shop:probe-sale --reconcile", $env, $root);
echo "\nReconciliation: {$recon}\n";
$passR = str_contains($recon, '"ok":true');

echo "\nRESULT: ".($passA && $passB && $passR ? 'ALL PASS' : 'FAILURES')."\n";
exit($passA && $passB && $passR ? 0 : 1);
