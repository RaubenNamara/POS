<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Services\Audit;
use App\Services\SaleService;
use Illuminate\Http\Request;

/** Operating expenses. Never deleted: a mistaken entry is voided (kept for the audit trail). */
class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $v = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date', 'category_id' => 'nullable|integer', 'voided' => 'nullable|boolean']);

        return Expense::with(['category:id,name', 'method:id,name', 'user:id,name'])
            ->when(empty($v['voided']), fn ($q) => $q->whereNull('voided_at'))
            ->when($v['from'] ?? null, fn ($q, $f) => $q->where('expense_date', '>=', $f))
            ->when($v['to'] ?? null, fn ($q, $t) => $q->where('expense_date', '<=', $t))
            ->when($v['category_id'] ?? null, fn ($q, $c) => $q->where('expense_category_id', $c))
            ->orderByDesc('expense_date')->orderByDesc('id')->paginate(25);
    }

    public function categories()
    {
        return ExpenseCategory::orderBy('name')->get();
    }

    public function saveCategory(Request $request, ?ExpenseCategory $category = null)
    {
        $d = $request->validate(['name' => 'required|string|max:80|unique:expense_categories,name,'.($category?->id ?? 'NULL')]);
        $category ??= new ExpenseCategory;
        $category->fill($d)->save();

        return response()->json($category, $category->wasRecentlyCreated ? 201 : 200);
    }

    public function store(Request $request, SaleService $sales)
    {
        $d = $request->validate([
            'client_uuid' => 'required|uuid',
            'expense_category_id' => 'required|exists:expense_categories,id',
            'payment_method_id' => 'required|exists:payment_methods,id',
            'amount' => 'required|numeric|min:0.01|max:9999999999999',
            'description' => 'required|string|max:255',
            'expense_date' => 'required|date|before_or_equal:today',
            'reference' => 'nullable|string|max:100',
        ]);
        if ($e = Expense::where('client_uuid', $d['client_uuid'])->first()) {
            return $e;
        }
        $method = PaymentMethod::findOrFail($d['payment_method_id']);
        // Cash paid out of the till is linked to the open shift so the drawer still balances.
        $shiftId = $method->type === 'cash' ? $sales->openShift($request->user(), $sales->resolveLocation(null)->id)?->id : null;
        $e = Expense::create($d + ['user_id' => $request->user()->id, 'shift_id' => $shiftId]);
        Audit::log('expense.created', $e, null, $d);

        return response()->json($e->load('category:id,name', 'method:id,name'), 201);
    }

    public function void(Request $request, Expense $expense)
    {
        $d = $request->validate(['reason' => 'required|string|max:255']);
        if ($expense->voided_at) {
            throw new BusinessRuleException('Already voided', 'invalid_state');
        }
        $expense->update(['voided_at' => now()]);
        Audit::log('expense.voided', $expense, null, ['reason' => $d['reason']]);

        return $expense;
    }
}
