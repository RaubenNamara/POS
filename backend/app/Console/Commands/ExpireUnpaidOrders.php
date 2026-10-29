<?php

namespace App\Console\Commands;

use App\Models\Sale;
use App\Services\FlutterwaveService;
use App\Services\SaleService;
use Illuminate\Console\Command;

/**
 * Online-payment orders hold stock while the shopper pays. If payment never arrives the
 * stock goes back on sale. Before cancelling, ask Flutterwave once more (the shopper may have
 * paid but closed the browser before returning, and the webhook may be late).
 */
class ExpireUnpaidOrders extends Command
{
    protected $signature = 'shop:expire-unpaid-orders';

    protected $description = 'Cancel online-payment orders whose payment window has passed (re-checking the gateway first)';

    public function handle(SaleService $sales): int
    {
        $due = Sale::where('payment_option', 'online')->where('status', 'pending')->where('fulfillment_status', 'awaiting_payment')
            ->where('payment_expires_at', '<', now())->limit(100)->get();
        $gateway = FlutterwaveService::configured() ? app(FlutterwaveService::class) : null;
        $cancelled = 0;
        foreach ($due as $sale) {
            if ($gateway) {
                $paid = false;
                foreach (\Illuminate\Support\Facades\DB::table('gateway_transactions')->where('sale_id', $sale->id)->whereIn('status', ['initiated'])->pluck('tx_ref') as $ref) {
                    try {
                        $paid = $gateway->confirm($ref)['status'] === 'successful' || $paid;
                    } catch (\Throwable $e) {
                        report($e);

                        continue 2; // gateway unreachable: try again next run rather than cancel a possibly-paid order
                    }
                }
                if ($paid) {
                    continue;
                }
            }
            try {
                $sales->updateFulfillment($sale, 'cancelled');
                $cancelled++;
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $this->info("Expired {$cancelled} unpaid order(s).");

        return 0;
    }
}
