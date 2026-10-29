<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\FeedNotification;
use App\Models\Sale;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Flutterwave Standard (hosted checkout: MTN / Airtel mobile money, cards).
 *
 * The shopper is redirected to Flutterwave and comes back to /payment/return. Neither that
 * redirect nor the webhook body is trusted: every confirmation re-fetches the transaction from
 * Flutterwave's API with our secret key and checks status, reference, currency and amount
 * before the order is marked paid. Each gateway transaction can be applied only once.
 */
class FlutterwaveService
{
    public function __construct(private SaleService $sales) {}

    public static function configured(): bool
    {
        return (bool) (config('services.flutterwave.secret_key') && config('services.flutterwave.public_key'));
    }

    /** Start (or restart) payment for an online order; returns the hosted checkout URL. */
    public function initiate(Sale $sale, string $redirectUrl): string
    {
        if (! self::configured()) {
            throw new BusinessRuleException('Online payment is not available right now', 'gateway_not_configured');
        }
        $sale->loadMissing('customer');
        if ($sale->status !== 'pending' || $sale->payment_status === 'paid' || Money::cmp((string) $sale->balance_due, '0') <= 0) {
            throw new BusinessRuleException('This order does not need payment', 'invalid_state');
        }

        $amount = (string) $sale->balance_due;
        $currency = Settings::get('currency', 'UGX');
        $txRef = $sale->number.'-'.Str::upper(Str::random(8));
        $tx = DB::table('gateway_transactions')->insertGetId([
            'sale_id' => $sale->id, 'provider' => 'flutterwave', 'tx_ref' => $txRef, 'amount' => $amount, 'currency' => $currency,
            'status' => 'initiated', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $r = $this->http()->post('/payments', [
            'tx_ref' => $txRef,
            'amount' => $amount,
            'currency' => $currency,
            'redirect_url' => $redirectUrl,
            'payment_options' => config('services.flutterwave.payment_options'),
            'customer' => [
                'email' => $sale->customer?->email ?: 'customer'.$sale->customer_id.'@'.parse_url(config('app.url'), PHP_URL_HOST),
                'phonenumber' => $sale->delivery_phone ?? $sale->customer?->phone,
                'name' => $sale->delivery_name ?? $sale->customer?->name,
            ],
            'customizations' => ['title' => Settings::get('shop_name'), 'description' => "Order {$sale->number}"],
            'meta' => ['sale_id' => $sale->id, 'order' => $sale->number],
        ]);
        $link = $r->json('data.link');
        if (! $r->ok() || $r->json('status') !== 'success' || ! $link) {
            DB::table('gateway_transactions')->where('id', $tx)->update(['status' => 'failed', 'provider_response' => json_encode($r->json()), 'updated_at' => now()]);
            logger()->error('Flutterwave initiate failed', ['order' => $sale->number, 'response' => $r->json()]);
            throw new BusinessRuleException('Could not start the payment. Please try again.', 'gateway_error');
        }
        DB::table('gateway_transactions')->where('id', $tx)->update(['checkout_url' => $link, 'updated_at' => now()]);

        return $link;
    }

    /**
     * Confirm a payment with Flutterwave and apply it to the order (idempotent).
     *
     * @return array{status:string, sale:Sale}
     */
    public function confirm(string $txRef, ?string $transactionId = null): array
    {
        $gt = DB::table('gateway_transactions')->where('tx_ref', $txRef)->first();
        if (! $gt) {
            throw new BusinessRuleException('Unknown payment reference', 'unknown_tx');
        }
        $sale = Sale::findOrFail($gt->sale_id);
        if ($gt->status === 'successful') {
            return ['status' => 'successful', 'sale' => $sale];
        }

        $r = $transactionId
            ? $this->http()->get('/transactions/'.rawurlencode($transactionId).'/verify')
            : $this->http()->get('/transactions/verify_by_reference', ['tx_ref' => $txRef]);
        $d = $r->json('data') ?? [];
        if (! $r->ok() || $r->json('status') !== 'success' || ($d['tx_ref'] ?? null) !== $txRef) {
            return ['status' => 'pending', 'sale' => $sale]; // not found yet / still processing
        }
        if (($d['status'] ?? '') !== 'successful') {
            if (in_array($d['status'] ?? '', ['failed', 'cancelled'], true)) {
                DB::table('gateway_transactions')->where('id', $gt->id)->update(['status' => 'failed', 'provider_response' => json_encode($d), 'updated_at' => now()]);

                return ['status' => 'failed', 'sale' => $sale];
            }

            return ['status' => 'pending', 'sale' => $sale];
        }

        return DB::transaction(function () use ($gt, $d, $txRef) {
            $gt = DB::table('gateway_transactions')->where('id', $gt->id)->lockForUpdate()->first();
            $sale = Sale::whereKey($gt->sale_id)->lockForUpdate()->firstOrFail();
            if ($gt->status === 'successful') {
                return ['status' => 'successful', 'sale' => $sale];
            }
            $paid = Money::of((string) ($d['amount'] ?? '0'));
            $sameCurrency = strtoupper((string) ($d['currency'] ?? '')) === strtoupper($gt->currency);
            if (! $sameCurrency || Money::cmp($paid, (string) $gt->amount) < 0) {
                // Paid, but not what we asked for: never auto-accept; a manager must look at it.
                DB::table('gateway_transactions')->where('id', $gt->id)->update(['status' => 'mismatch', 'provider_tx_id' => (string) $d['id'],
                    'provider_response' => json_encode($d), 'verified_at' => now(), 'updated_at' => now()]);
                FeedNotification::create(['type' => 'payment_review', 'title' => "Online payment for {$sale->number} needs review",
                    'body' => "Expected {$gt->amount} {$gt->currency}, received {$paid} ".($d['currency'] ?? ''),
                    'subject_type' => $sale->getMorphClass(), 'subject_id' => $sale->id]);

                return ['status' => 'mismatch', 'sale' => $sale];
            }

            DB::table('gateway_transactions')->where('id', $gt->id)->update(['status' => 'successful', 'provider_tx_id' => (string) $d['id'],
                'provider_response' => json_encode($d), 'verified_at' => now(), 'updated_at' => now()]);
            if ($sale->status === 'cancelled') {
                // Paid after the reservation expired: money is real, goods were released. Flag for a refund or manual re-order.
                FeedNotification::create(['type' => 'payment_review', 'title' => "Payment received for cancelled order {$sale->number}",
                    'body' => "Refund {$paid} {$gt->currency} via Flutterwave or re-create the order (ref {$txRef})",
                    'subject_type' => $sale->getMorphClass(), 'subject_id' => $sale->id]);

                return ['status' => 'late', 'sale' => $sale];
            }
            $this->sales->recordGatewayPayment($sale, Money::min($paid, (string) $sale->balance_due), 'FLW '.$d['id']);

            return ['status' => 'successful', 'sale' => $sale->fresh()];
        });
    }

    /** Webhook: authenticate by the shared secret hash, then confirm via the API (body not trusted). */
    public function handleWebhook(?string $hash, array $payload): string
    {
        $expected = (string) config('services.flutterwave.webhook_hash');
        if ($expected === '' || ! is_string($hash) || ! hash_equals($expected, $hash)) {
            return 'rejected';
        }
        $data = $payload['data'] ?? $payload;
        $txRef = $data['tx_ref'] ?? $data['txRef'] ?? null;
        if (! $txRef || ! DB::table('gateway_transactions')->where('tx_ref', $txRef)->exists()) {
            return 'ignored';
        }

        return $this->confirm($txRef, isset($data['id']) ? (string) $data['id'] : null)['status'];
    }

    private function http()
    {
        return Http::baseUrl(rtrim(config('services.flutterwave.base_url'), '/'))
            ->withToken(config('services.flutterwave.secret_key'))->acceptJson()->timeout(15);
    }
}
