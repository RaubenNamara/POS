<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Shift;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/** Cashier shifts and drawer reconciliation. */
class ShiftService
{
    public function open(User $user, int $locationId, string $float): Shift
    {
        return DB::transaction(function () use ($user, $locationId, $float) {
            // Lock the user row so two taps on "Open shift" can't create two open shifts.
            User::whereKey($user->id)->lockForUpdate()->first();
            if (Shift::where('user_id', $user->id)->where('status', 'open')->exists()) {
                throw new BusinessRuleException('You already have an open shift', 'shift_open');
            }
            $shift = Shift::create([
                'user_id' => $user->id, 'location_id' => $locationId,
                'opening_float' => Money::of($float), 'opened_at' => now(), 'status' => 'open',
            ]);
            Audit::log('shift.opened', $shift, null, ['float' => $shift->opening_float]);

            return $shift;
        });
    }

    public function close(Shift $shift, string $counted, ?string $notes): Shift
    {
        return DB::transaction(function () use ($shift, $counted, $notes) {
            $shift = Shift::whereKey($shift->id)->lockForUpdate()->firstOrFail();
            if ($shift->status !== 'open') {
                throw new BusinessRuleException('Shift is already closed', 'shift_closed');
            }
            $summary = $this->summary($shift);
            $counted = Money::of($counted);
            $shift->fill([
                'status' => 'closed',
                'closed_at' => now(),
                'expected_cash' => $summary['expected_cash'],
                'counted_cash' => $counted,
                'cash_difference' => Money::sub($counted, $summary['expected_cash']),
                'closing_notes' => $notes,
            ])->save();
            Audit::log('shift.closed', $shift, null, ['expected' => $shift->expected_cash, 'counted' => $counted, 'difference' => $shift->cash_difference]);

            return $shift;
        });
    }

    /** Totals for a shift, computed from the payment/expense rows that reference it. */
    public function summary(Shift $shift): array
    {
        $byMethod = DB::table('payments as p')->join('payment_methods as m', 'm.id', '=', 'p.payment_method_id')
            ->where('p.shift_id', $shift->id)
            ->groupBy('m.id', 'm.name', 'm.type')
            ->selectRaw("m.id, m.name, m.type,
                SUM(CASE WHEN p.kind='payment' THEN p.amount ELSE 0 END) AS received,
                SUM(CASE WHEN p.kind='refund' THEN p.amount ELSE 0 END) AS refunded")
            ->get();
        $cashIn = '0.00';
        $cashRefunds = '0.00';
        foreach ($byMethod as $m) {
            $m->received = Money::of((string) $m->received);
            $m->refunded = Money::of((string) $m->refunded);
            $m->net = Money::sub($m->received, $m->refunded);
            if ($m->type === 'cash') {
                $cashIn = Money::add($cashIn, $m->received);
                $cashRefunds = Money::add($cashRefunds, $m->refunded);
            }
        }
        $cashExpenses = Money::of((string) (DB::table('expenses as e')->join('payment_methods as m', 'm.id', '=', 'e.payment_method_id')
            ->where('e.shift_id', $shift->id)->where('m.type', 'cash')->whereNull('e.voided_at')->sum('e.amount') ?: '0'));
        $cashSupplier = Money::of((string) (DB::table('supplier_payments as s')->join('payment_methods as m', 'm.id', '=', 's.payment_method_id')
            ->where('s.shift_id', $shift->id)->where('m.type', 'cash')->sum('s.amount') ?: '0'));

        $sales = DB::table('sales')->where('shift_id', $shift->id)->where('status', 'completed')
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(total),0) AS total, COALESCE(SUM(balance_due),0) AS credit')->first();

        return [
            'shift' => $shift,
            'sales_count' => (int) $sales->count,
            'sales_total' => Money::of((string) $sales->total),
            'credit_given' => Money::of((string) $sales->credit),
            'by_method' => $byMethod,
            'opening_float' => (string) $shift->opening_float,
            'cash_received' => $cashIn,
            'cash_refunded' => $cashRefunds,
            'cash_expenses' => $cashExpenses,
            'cash_supplier_payments' => $cashSupplier,
            'expected_cash' => Money::sub(Money::sub(Money::sub(Money::add((string) $shift->opening_float, $cashIn), $cashRefunds), $cashExpenses), $cashSupplier),
        ];
    }
}
