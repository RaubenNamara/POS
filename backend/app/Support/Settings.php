<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Shop settings (name, receipt text, tax, limits). Cached as one small array; flushed on save. */
final class Settings
{
    public const DEFAULTS = [
        'shop_name' => 'My Shop',
        'shop_address' => '',
        'shop_phone' => '',
        'shop_email' => '',
        'shop_whatsapp' => '',
        'play_store_url' => '',          // Android app on Google Play (footer badge shows when set)
        'app_store_url' => '',           // iPhone app on the App Store          // e.g. 256772000000 (international format, digits only)
        'shop_logo' => '',
        'currency' => 'UGX',
        'currency_decimals' => '0',
        'tax_rate' => '0',            // percent
        'tax_inclusive' => '1',       // 1 = shelf prices already include tax
        'tax_label' => 'VAT',
        'receipt_footer' => 'Thank you for shopping with us!',
        'return_policy' => 'Goods in original condition may be returned within 7 days with this receipt.',
        'max_cashier_discount_pct' => '10',
        'require_shift' => '1',
        'offline_max_sale_total' => '500000',
        'offline_max_queue' => '50',
        'delivery_fee' => '0',
        'online_orders_enabled' => '1',
        'cod_enabled' => '1',                     // pay on delivery (in COD districts) / pay at pickup
        'online_payment_expiry_minutes' => '60',  // unpaid online-payment orders release stock after this
        // Integrations, entered by the owner in Settings (a value in .env takes priority).
        'google_client_id' => '',
        'google_maps_browser_key' => '',
        'google_maps_server_key' => '',
        'facebook_app_id' => '',
        'facebook_app_secret' => '',
        'flutterwave_public_key' => '',
        'flutterwave_secret_key' => '',
        'flutterwave_webhook_hash' => '',
    ];

    /** Stored encrypted; never returned to any browser, not even the owner's. */
    public const SECRET_KEYS = ['google_maps_server_key', 'facebook_app_secret', 'flutterwave_secret_key', 'flutterwave_webhook_hash'];

    /** Where each integration setting lives in config/services.php. */
    public const CONFIG_MAP = [
        'google_client_id' => 'services.google.client_id',
        'google_maps_browser_key' => 'services.google.maps_browser_key',
        'google_maps_server_key' => 'services.google.maps_server_key',
        'facebook_app_id' => 'services.facebook.app_id',
        'facebook_app_secret' => 'services.facebook.app_secret',
        'flutterwave_public_key' => 'services.flutterwave.public_key',
        'flutterwave_secret_key' => 'services.flutterwave.secret_key',
        'flutterwave_webhook_hash' => 'services.flutterwave.webhook_hash',
    ];

    /**
     * Fill integration config from saved settings where .env leaves it empty.
     * Called once per request from AppServiceProvider.
     */
    /** Integration keys that came from .env (those always win and are never overwritten). */
    private static ?array $fromEnv = null;

    public static function applyIntegrations(): void
    {
        self::$fromEnv ??= array_map(fn ($c) => (bool) config($c), self::CONFIG_MAP);
        try {
            $all = self::all();
        } catch (\Throwable) {
            return; // fresh install / migrations not run yet
        }
        foreach (self::CONFIG_MAP as $key => $configKey) {
            if (self::$fromEnv[$key]) {
                continue;
            }
            if (($all[$key] ?? '') === '') {
                config([$configKey => null]); // removed in Settings: switch off straight away
                continue;
            }
            $value = $all[$key];
            if (in_array($key, self::SECRET_KEYS, true)) {
                try {
                    $value = \Illuminate\Support\Facades\Crypt::decryptString($value);
                } catch (\Throwable) {
                    continue; // APP_KEY changed: the owner must re-enter it
                }
            }
            config([$configKey => $value]);
        }
    }

    public static function integrationFromEnv(string $key): bool
    {
        return (bool) (self::$fromEnv[$key] ?? false);
    }

    /** Settings safe to send to the owner's browser: secrets reduced to "is it set". */
    public static function forAdmin(): array
    {
        $all = self::all();
        foreach (self::SECRET_KEYS as $k) {
            $all[$k] = '';
        }

        return $all;
    }

    /** Keys safe to expose to the public storefront. */
    public const PUBLIC_KEYS = ['shop_name', 'shop_address', 'shop_phone', 'shop_email', 'shop_whatsapp', 'play_store_url', 'app_store_url', 'shop_logo', 'currency',
        'currency_decimals', 'tax_rate', 'tax_inclusive', 'tax_label', 'delivery_fee', 'online_orders_enabled', 'return_policy', 'cod_enabled'];

    public static function all(): array
    {
        return Cache::rememberForever('settings', function () {
            $rows = DB::table('settings')->pluck('value', 'key')->all();

            return array_merge(self::DEFAULTS, $rows);
        });
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::all()[$key] ?? $default;
    }

    public static function public(): array
    {
        // Integration settings the browser needs. These keys are public by design (Google browser
        // keys must be restricted by HTTP referrer in Google Cloud Console). Secrets never go here.
        return array_intersect_key(self::all(), array_flip(self::PUBLIC_KEYS)) + [
            'google_client_id' => (string) config('services.google.client_id'),
            'google_maps_key' => (string) config('services.google.maps_browser_key'),
            // The Facebook button only appears when both halves are set (the secret verifies logins).
            'facebook_app_id' => config('services.facebook.app_secret') ? (string) config('services.facebook.app_id') : '',
            'online_payments' => \App\Services\FlutterwaveService::configured() ? '1' : '0',
            'cod_districts' => implode(', ', config('services.delivery.cod_districts')),
        ];
    }

    public static function set(array $values): void
    {
        $now = now();
        foreach ($values as $k => $v) {
            if (! array_key_exists($k, self::DEFAULTS)) {
                continue;
            }
            if (in_array($k, self::SECRET_KEYS, true) && (string) $v !== '') {
                $v = \Illuminate\Support\Facades\Crypt::encryptString((string) $v);
            }
            DB::table('settings')->updateOrInsert(['key' => $k], ['value' => (string) $v, 'updated_at' => $now, 'created_at' => $now]);
        }
        Cache::forget('settings');
    }
}
