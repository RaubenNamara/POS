<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\LocationService;
use App\Services\SaleService;
use Illuminate\Http\Request;

/** A shopper's own profile and location. */
class CustomerAccountController extends Controller
{
    /**
     * Save the customer's location (from the phone's GPS or a pin on the map). The zone and
     * district are worked out here from Google's data, never taken from the browser.
     */
    public function saveLocation(Request $request, LocationService $locations)
    {
        $d = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'address' => 'nullable|string|max:255', // directions in the customer's words (landmark, building)
            'phone' => 'nullable|string|max:30',
        ]);
        $u = $request->user();
        abort_if($u->isStaff(), 403, 'Staff accounts have no delivery location');
        $c = $u->customer ?? Customer::create(['user_id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone]);

        $zone = $locations->classify((float) $d['lat'], (float) $d['lng']);
        $c->fill([
            'latitude' => $d['lat'], 'longitude' => $d['lng'], 'location_label' => $zone['label'],
            'district' => $zone['district'], 'delivery_zone' => $zone['zone'], 'location_checked_at' => now(),
            'address' => $d['address'] ?? $c->address, 'phone' => $d['phone'] ?? $c->phone,
        ])->save();

        return [
            'location' => ['lat' => (float) $c->latitude, 'lng' => (float) $c->longitude, 'label' => $c->location_label,
                'district' => $c->district, 'zone' => $c->delivery_zone, 'address' => $c->address],
            'options' => SaleService::paymentOptions(true, $zone['zone']),
            'cod_districts' => config('services.delivery.cod_districts'),
        ];
    }
}
