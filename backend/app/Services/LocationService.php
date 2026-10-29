<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Decides which delivery zone a point is in. Done on the server (a browser can report any
 * coordinates it likes, but it cannot change what Google says those coordinates are).
 *
 *   metro   = inside a pay-on-delivery district (default Kampala, Wakiso)
 *   outside = anywhere else
 *   unknown = could not be determined: treated like "outside" (no pay on delivery), fail-safe
 */
class LocationService
{
    /** @return array{zone:string, district:?string, label:?string, source:string} */
    public function classify(float $lat, float $lng): array
    {
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return ['zone' => 'unknown', 'district' => null, 'label' => null, 'source' => 'invalid'];
        }
        // Coarse Uganda bounding box: far-away points need no API call.
        if ($lat < -1.6 || $lat > 4.4 || $lng < 29.4 || $lng > 35.2) {
            return ['zone' => 'outside', 'district' => null, 'label' => null, 'source' => 'bbox'];
        }

        $key = config('services.google.maps_server_key');
        if ($key) {
            // ~11 m grid: neighbours share a cached answer, keeping Geocoding API costs low.
            $cacheKey = sprintf('geo:%.4f:%.4f', $lat, $lng);
            $cached = Cache::get($cacheKey);
            if ($cached) {
                return $cached;
            }
            $result = $this->google($lat, $lng, $key);
            if ($result) {
                Cache::put($cacheKey, $result, now()->addDays(30));

                return $result;
            }
        }

        $radius = (float) config('services.delivery.fallback_radius_km');
        if ($radius > 0) {
            $km = $this->distanceKm($lat, $lng, (float) config('services.delivery.shop_lat'), (float) config('services.delivery.shop_lng'));

            return ['zone' => $km <= $radius ? 'metro' : 'outside', 'district' => null,
                'label' => sprintf('%.1f km from the shop', $km), 'source' => 'radius'];
        }

        return ['zone' => 'unknown', 'district' => null, 'label' => null, 'source' => 'none'];
    }

    private function google(float $lat, float $lng, string $key): ?array
    {
        try {
            $r = Http::timeout(6)->get('https://maps.googleapis.com/maps/api/geocode/json', [
                'latlng' => "{$lat},{$lng}", 'key' => $key, 'language' => 'en',
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
        $body = $r->json();
        if (! $r->ok() || ($body['status'] ?? '') !== 'OK') {
            if (($body['status'] ?? '') === 'ZERO_RESULTS') {
                return ['zone' => 'outside', 'district' => null, 'label' => null, 'source' => 'google'];
            }
            logger()->warning('Geocoding failed', ['status' => $body['status'] ?? $r->status(), 'error' => $body['error_message'] ?? null]);

            return null;
        }

        // Look at the administrative areas / localities of the best results for a COD district.
        $districts = array_map('mb_strtolower', config('services.delivery.cod_districts'));
        $wanted = ['administrative_area_level_1', 'administrative_area_level_2', 'administrative_area_level_3', 'locality', 'postal_town'];
        $found = null;
        $firstArea = null;
        foreach (array_slice($body['results'], 0, 5) as $res) {
            foreach ($res['address_components'] ?? [] as $c) {
                if (! array_intersect($wanted, $c['types'] ?? [])) {
                    continue;
                }
                $name = (string) ($c['long_name'] ?? '');
                $firstArea ??= in_array('administrative_area_level_2', $c['types'], true) || in_array('administrative_area_level_1', $c['types'], true) ? $name : null;
                foreach ($districts as $d) {
                    // Google may say "Kampala", "Kampala Capital City" or "Wakiso District".
                    if (preg_match('/\b'.preg_quote($d, '/').'\b/u', mb_strtolower($name))) {
                        $found = ucfirst($d);
                        break 3;
                    }
                }
            }
        }

        return [
            'zone' => $found ? 'metro' : 'outside',
            'district' => $found ?? $firstArea,
            'label' => $body['results'][0]['formatted_address'] ?? null,
            'source' => 'google',
        ];
    }

    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }
}
