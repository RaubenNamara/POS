<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Services\SaleService;
use App\Services\ShiftService;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    public function __construct(private ShiftService $shifts) {}

    public function index(Request $request)
    {
        $q = Shift::with(['user:id,name', 'location:id,name'])->orderByDesc('id');
        if (! $request->user()->can('sales.view_all')) {
            $q->where('user_id', $request->user()->id);
        }

        return $q->paginate(25);
    }

    public function current(Request $request, SaleService $sales)
    {
        $loc = $sales->resolveLocation($request->integer('location_id') ?: null);
        $shift = $sales->openShift($request->user(), $loc->id);

        return response()->json($shift ? $this->shifts->summary($shift) : null);
    }

    public function open(Request $request, SaleService $sales)
    {
        $d = $request->validate(['opening_float' => 'required|numeric|min:0', 'location_id' => 'nullable|exists:locations,id']);
        $loc = $sales->resolveLocation($d['location_id'] ?? null);

        return response()->json($this->shifts->open($request->user(), $loc->id, (string) $d['opening_float']), 201);
    }

    public function show(Request $request, Shift $shift)
    {
        $this->authorizeShift($request, $shift);

        return $this->shifts->summary($shift->load('user:id,name'));
    }

    public function close(Request $request, Shift $shift)
    {
        $this->authorizeShift($request, $shift);
        $d = $request->validate(['counted_cash' => 'required|numeric|min:0', 'notes' => 'nullable|string|max:500']);
        $closed = $this->shifts->close($shift, (string) $d['counted_cash'], $d['notes'] ?? null);

        return $this->shifts->summary($closed);
    }

    private function authorizeShift(Request $request, Shift $shift): void
    {
        abort_unless($shift->user_id === $request->user()->id || $request->user()->can('sales.view_all'), 403);
    }
}
