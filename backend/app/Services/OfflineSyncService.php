<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\OfflineConflict;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\User;
use App\Support\Money;
use App\Support\Settings;

/**
 * Sales rung up while the till was offline.
 *
 * Rules (also enforced in the POS before a sale may be queued):
 *  - cash only, at most `offline_max_sale_total` per sale, at most `offline_max_queue` queued;
 *  - an offline sale is PROVISIONAL until this service accepts it, its receipt says so;
 *  - the sale's client_uuid makes replays harmless: an already-synced sale is reported, not re-sold;
 *  - if stock is now insufficient (sold elsewhere meanwhile) or a rule is broken, nothing is
 *    deducted: the sale is parked as an OfflineConflict for a manager to resolve.
 */
class OfflineSyncService
{
    public function __construct(private SaleService $sales) {}

    /** @return array{client_uuid:string, status:string, sale_id?:int, number?:string, message?:string} */
    public function syncOne(array $payload, User $user): array
    {
        $uuid = $payload['client_uuid'];
        if ($sale = Sale::where('client_uuid', $uuid)->first()) {
            return ['client_uuid' => $uuid, 'status' => 'duplicate', 'sale_id' => $sale->id, 'number' => $sale->number];
        }
        if ($c = OfflineConflict::where('client_uuid', $uuid)->first()) {
            return ['client_uuid' => $uuid, 'status' => 'conflict', 'message' => $c->reason];
        }

        try {
            $this->assertWithinOfflineLimits($payload, $user);
            $result = $this->sales->checkout($payload, $user, offline: true);

            return ['client_uuid' => $uuid, 'status' => $result['replayed'] ? 'duplicate' : 'synced', 'sale_id' => $result['sale']->id, 'number' => $result['sale']->number];
        } catch (BusinessRuleException $e) {
            OfflineConflict::firstOrCreate(['client_uuid' => $uuid], [
                'user_id' => $user->id, 'payload' => $payload, 'reason' => $e->getMessage(), 'status' => 'open',
            ]);
            \App\Models\FeedNotification::create(['type' => 'offline_conflict', 'title' => 'Offline sale needs review', 'body' => $e->getMessage()]);

            return ['client_uuid' => $uuid, 'status' => 'conflict', 'message' => $e->getMessage()];
        }
    }

    /** Manager retries a parked sale (e.g. after correcting stock with a count). */
    public function retry(OfflineConflict $conflict, User $manager): OfflineConflict
    {
        if ($conflict->status !== 'open') {
            throw new BusinessRuleException('Conflict already resolved', 'invalid_state');
        }
        $cashier = User::findOrFail($conflict->user_id);
        $result = $this->sales->checkout($conflict->payload, $cashier, offline: true);
        $conflict->update(['status' => 'resolved', 'sale_id' => $result['sale']->id, 'resolved_by' => $manager->id, 'resolved_at' => now()]);
        Audit::log('offline.resolved', $conflict, null, ['sale' => $result['sale']->number]);

        return $conflict;
    }

    public function void(OfflineConflict $conflict, User $manager, string $note): OfflineConflict
    {
        if ($conflict->status !== 'open') {
            throw new BusinessRuleException('Conflict already resolved', 'invalid_state');
        }
        $conflict->update(['status' => 'voided', 'resolved_by' => $manager->id, 'resolved_at' => now()]);
        Audit::log('offline.voided', $conflict, null, ['note' => $note]);

        return $conflict;
    }

    private function assertWithinOfflineLimits(array $payload, User $user): void
    {
        $cashIds = PaymentMethod::where('type', 'cash')->pluck('id')->all();
        foreach ($payload['payments'] ?? [] as $p) {
            if (! in_array((int) $p['payment_method_id'], $cashIds, true)) {
                throw new BusinessRuleException('Offline sales may only be paid in cash', 'offline_rule');
            }
        }
        $priced = $this->sales->priceCart($payload['items'], $payload['discount'] ?? null, $user);
        $max = Money::of(Settings::get('offline_max_sale_total', '0'));
        if (! Money::isZero($max) && Money::cmp($priced['total'], $max) > 0) {
            throw new BusinessRuleException("Offline sale total {$priced['total']} is above the offline limit {$max}", 'offline_rule');
        }
    }
}
