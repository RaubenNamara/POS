<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Sale;
use App\Services\Audit;
use App\Services\SaleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $v = $request->validate(['q' => 'nullable|string|max:100', 'owing' => 'nullable|boolean', 'per_page' => 'nullable|integer|max:100']);
        $owed = DB::table('sales')->selectRaw('customer_id, SUM(balance_due) AS balance, COUNT(*) AS sales_count, MAX(created_at) AS last_purchase')
            ->where('status', '!=', 'cancelled')->groupBy('customer_id');
        $q = Customer::query()->whereNull('archived_at')
            ->leftJoinSub($owed, 'o', 'o.customer_id', '=', 'customers.id')
            ->select('customers.*', DB::raw('COALESCE(o.balance,0) AS balance'), DB::raw('COALESCE(o.sales_count,0) AS sales_count'), 'o.last_purchase');
        if (! empty($v['q'])) {
            $like = '%'.addcslashes($v['q'], '%_\\').'%';
            $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('email', 'like', $like));
        }
        if (! empty($v['owing'])) {
            $q->where('o.balance', '>', 0)->orderByDesc('o.balance');
        }

        return $q->orderBy('name')->paginate($v['per_page'] ?? 25);
    }

    public function show(Customer $customer)
    {
        return [
            'customer' => $customer,
            'balance' => (string) (Sale::where('customer_id', $customer->id)->where('status', '!=', 'cancelled')->sum('balance_due') ?: '0.00'),
            'sales' => Sale::where('customer_id', $customer->id)->orderByDesc('id')->limit(50)
                ->get(['id', 'number', 'channel', 'status', 'payment_status', 'total', 'amount_paid', 'balance_due', 'refunded_total', 'created_at']),
            'payments' => Payment::whereIn('sale_id', Sale::where('customer_id', $customer->id)->select('id'))
                ->with(['method:id,name', 'sale:id,number'])->orderByDesc('id')->limit(50)->get(),
        ];
    }

    public function store(Request $request)
    {
        $c = Customer::create($this->validated($request));
        Audit::log('customer.created', $c);

        return response()->json($c, 201);
    }

    public function update(Request $request, Customer $customer)
    {
        $d = $this->validated($request);
        // Credit limits are a financial control: only users who can grant discounts/refunds may change them.
        if (array_key_exists('credit_limit', $d) && (string) $d['credit_limit'] !== (string) $customer->credit_limit && ! $request->user()->can('sales.refund')) {
            abort(403, 'You cannot change credit limits');
        }
        $old = $customer->only(array_keys($d));
        $customer->update($d);
        Audit::log('customer.updated', $customer, $old, $d);

        return $customer;
    }

    public function archive(Customer $customer)
    {
        $customer->update(['archived_at' => now()]);

        return response()->noContent();
    }

    public function collect(Request $request, Customer $customer, SaleService $sales)
    {
        $d = $request->validate([
            'client_uuid' => 'required|uuid', 'payment_method_id' => 'required|integer|exists:payment_methods,id',
            'amount' => 'required|numeric|min:0.01', 'reference' => 'nullable|string|max:100',
        ]);
        $payments = $sales->collectFromCustomer($customer, $d, $request->user());

        return response()->json(['payments' => $payments, 'balance' => (string) (Sale::where('customer_id', $customer->id)->where('status', '!=', 'cancelled')->sum('balance_due') ?: '0.00')], 201);
    }

    private function validated(Request $request): array
    {
        $d = $request->validate([
            'name' => 'required|string|max:120',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'credit_limit' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:2000',
        ]);
        if (! $request->user()->can('sales.refund')) {
            unset($d['credit_limit']);
        }

        return $d;
    }
}
