<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\FeedNotification;
use App\Models\OfflineConflict;
use App\Models\User;
use App\Services\Audit;
use App\Services\OfflineSyncService;
use App\Support\Permissions;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Staff accounts, settings, notifications, offline conflicts, audit log. */
class SystemController extends Controller
{
    public function users(Request $request)
    {
        return User::whereIn('role', Permissions::STAFF_ROLES)->orderBy('name')
            ->get(['id', 'name', 'email', 'phone', 'role', 'is_active', 'last_login_at']);
    }

    public function saveUser(Request $request, ?User $user = null)
    {
        $d = $request->validate([
            'name' => 'required|string|max:120',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'phone' => ['nullable', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($user?->id)],
            'role' => ['required', Rule::in(Permissions::STAFF_ROLES)],
            'is_active' => 'nullable|boolean',
            'password' => [$user ? 'nullable' : 'required', Password::min(8)],
        ]);
        if ($user && $user->id === $request->user()->id && ($d['role'] !== $user->role || ($d['is_active'] ?? true) === false)) {
            throw new BusinessRuleException('You cannot change your own role or disable yourself', 'self_change');
        }
        if ($user && $user->role === 'owner' && $d['role'] !== 'owner' && User::where('role', 'owner')->where('is_active', true)->count() <= 1) {
            throw new BusinessRuleException('The shop must keep at least one active owner', 'last_owner');
        }
        $user ??= new User;
        $old = $user->exists ? $user->only(['role', 'is_active', 'email']) : null;
        $user->fill(array_intersect_key($d, array_flip(['name', 'email', 'phone'])));
        if (! empty($d['password'])) {
            $user->password = $d['password'];
        }
        $user->role = $d['role'];
        $user->is_active = $d['is_active'] ?? true;
        $user->save();
        if (! $user->is_active) {
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }
        Audit::log('user.saved', $user, $old, $user->only(['role', 'is_active', 'email']));

        return response()->json($user->only(['id', 'name', 'email', 'phone', 'role', 'is_active']), $old ? 200 : 201);
    }

    public function settings()
    {
        return Settings::forAdmin() + ['integrations' => $this->integrations()];
    }

    /** What is connected, without revealing any secret. */
    private function integrations(): array
    {
        $fwSecret = (string) config('services.flutterwave.secret_key');
        $fromEnv = fn (string $key) => Settings::integrationFromEnv($key);

        return [
            'google_signin' => (bool) config('services.google.client_id'),
            'google_maps_browser' => (bool) config('services.google.maps_browser_key'),
            'google_maps_server' => (bool) config('services.google.maps_server_key'),
            'facebook_signin' => \App\Services\FacebookAuth::configured(),
            'flutterwave' => \App\Services\FlutterwaveService::configured(),
            'flutterwave_mode' => $fwSecret === '' ? null : (str_contains($fwSecret, '_TEST') ? 'test' : 'live'),
            'flutterwave_webhook' => (bool) config('services.flutterwave.webhook_hash'),
            'webhook_url' => rtrim(config('app.url') !== 'http://localhost' ? config('app.url') : request()->getSchemeAndHttpHost(), '/').'/api/webhooks/flutterwave',
            'secrets_set' => array_combine(Settings::SECRET_KEYS, array_map(fn ($k) => (bool) config(Settings::CONFIG_MAP[$k]), Settings::SECRET_KEYS)),
            'from_env' => array_combine(array_keys(Settings::CONFIG_MAP), array_map($fromEnv, array_keys(Settings::CONFIG_MAP))),
        ];
    }

    public function saveSettings(Request $request)
    {
        $d = $request->validate([
            'shop_name' => 'sometimes|string|max:120', 'shop_address' => 'sometimes|nullable|string|max:255',
            'shop_phone' => 'sometimes|nullable|string|max:60', 'shop_email' => 'sometimes|nullable|email|max:120',
            'shop_whatsapp' => ['sometimes', 'nullable', 'regex:/^\d{9,15}$/'],
            'play_store_url' => ['sometimes', 'nullable', 'url', 'regex:#^https://play\.google\.com/#'],
            'app_store_url' => ['sometimes', 'nullable', 'url', 'regex:#^https://apps\.apple\.com/#'],
            'shop_logo' => 'sometimes|nullable|string|max:255', 'currency' => 'sometimes|string|max:10',
            'currency_decimals' => 'sometimes|integer|in:0,2', 'tax_rate' => 'sometimes|numeric|min:0|max:100',
            'tax_inclusive' => 'sometimes|boolean', 'tax_label' => 'sometimes|string|max:20',
            'receipt_footer' => 'sometimes|nullable|string|max:500', 'return_policy' => 'sometimes|nullable|string|max:1000',
            'max_cashier_discount_pct' => 'sometimes|numeric|min:0|max:100', 'require_shift' => 'sometimes|boolean',
            'offline_max_sale_total' => 'sometimes|numeric|min:0', 'offline_max_queue' => 'sometimes|integer|min:0|max:500',
            'delivery_fee' => 'sometimes|numeric|min:0', 'online_orders_enabled' => 'sometimes|boolean',
            'cod_enabled' => 'sometimes|boolean', 'online_payment_expiry_minutes' => 'sometimes|integer|min:10|max:1440',
            'google_client_id' => ['sometimes', 'nullable', 'string', 'max:200', 'regex:/^$|\.apps\.googleusercontent\.com$/'],
            'google_maps_browser_key' => 'sometimes|nullable|string|max:100',
            'google_maps_server_key' => 'sometimes|nullable|string|max:100',
            'facebook_app_id' => ['sometimes', 'nullable', 'regex:/^$|^\d{6,20}$/'],
            'facebook_app_secret' => ['sometimes', 'nullable', 'regex:/^$|^[0-9a-fA-F]{32}$/'],
            'flutterwave_public_key' => ['sometimes', 'nullable', 'string', 'max:120', 'regex:/^$|^FLWPUBK/'],
            'flutterwave_secret_key' => ['sometimes', 'nullable', 'string', 'max:120', 'regex:/^$|^FLWSECK/'],
            'flutterwave_webhook_hash' => 'sometimes|nullable|string|min:8|max:120',
            'clear_secrets' => 'sometimes|array', 'clear_secrets.*' => 'in:google_maps_server_key,facebook_app_secret,flutterwave_secret_key,flutterwave_webhook_hash',
        ], [
            'play_store_url.regex' => 'The Android link should start with https://play.google.com/',
            'app_store_url.regex' => 'The iPhone link should start with https://apps.apple.com/',
            'google_client_id.regex' => 'Google Client ID should end with ".apps.googleusercontent.com". Check the box was not filled in by your browser.',
            'facebook_app_id.regex' => 'Facebook App ID is a number (digits only).',
            'facebook_app_secret.regex' => 'Facebook App Secret is 32 letters and numbers. Copy it from App settings, Basic.',
            'flutterwave_public_key.regex' => 'Flutterwave public key should start with FLWPUBK.',
            'flutterwave_secret_key.regex' => 'Flutterwave secret key should start with FLWSECK (use the Secret key, not the Encryption key).',
        ]);
        $clear = $d['clear_secrets'] ?? [];
        unset($d['clear_secrets']);
        // A blank secret field means "keep the saved one"; clearing is explicit.
        foreach (Settings::SECRET_KEYS as $k) {
            if (array_key_exists($k, $d) && (string) $d[$k] === '') {
                unset($d[$k]);
            }
        }
        foreach ($clear as $k) {
            $d[$k] = '';
        }
        $d = array_map(fn ($v) => is_bool($v) ? ($v ? '1' : '0') : ($v ?? ''), $d);
        $old = array_intersect_key(Settings::forAdmin(), $d);
        Settings::set($d);
        // Audit which settings changed, never the secret values.
        $logged = $d;
        foreach (Settings::SECRET_KEYS as $k) {
            if (array_key_exists($k, $logged)) {
                $logged[$k] = $logged[$k] === '' ? '(cleared)' : '(changed)';
            }
        }
        Audit::log('settings.updated', null, $old, $logged);
        \Illuminate\Support\Facades\Cache::increment('catalog_version');
        Settings::applyIntegrations();

        return Settings::forAdmin() + ['integrations' => $this->integrations()];
    }

    /** Ask Flutterwave whether the saved keys work (a harmless read of the account's balances). */
    public function testFlutterwave()
    {
        if (! \App\Services\FlutterwaveService::configured()) {
            return ['ok' => false, 'message' => 'Save both the Public key and the Secret key first.'];
        }
        try {
            $r = \Illuminate\Support\Facades\Http::baseUrl(rtrim(config('services.flutterwave.base_url'), '/'))
                ->withToken(config('services.flutterwave.secret_key'))->acceptJson()->timeout(15)->get('/balances');
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Could not reach Flutterwave. Check the internet connection on this computer and try again.'];
        }
        if ($r->ok() && $r->json('status') === 'success') {
            $mode = str_contains((string) config('services.flutterwave.secret_key'), '_TEST') ? 'test' : 'live';

            return ['ok' => true, 'mode' => $mode, 'message' => "Flutterwave accepted the keys ({$mode} mode). Online payment is ready."];
        }
        $why = $r->json('message') ?: "HTTP {$r->status()}";

        return ['ok' => false, 'message' => "Flutterwave refused the keys: {$why}. Copy the Secret key again with Flutterwave's Copy button."];
    }

    public function uploadLogo(Request $request)
    {
        $request->validate(['logo' => 'required|file|mimes:webp,png,jpg,jpeg,svg|max:512']);
        $path = $request->file('logo')->store('branding', 'public');
        Settings::set(['shop_logo' => $path]);

        return ['shop_logo' => $path];
    }

    public function notifications(Request $request)
    {
        return FeedNotification::orderByDesc('id')->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))->paginate(30);
    }

    public function markNotificationsRead(Request $request)
    {
        $ids = $request->input('ids');
        FeedNotification::whereNull('read_at')->when(is_array($ids), fn ($q) => $q->whereIn('id', $ids))->update(['read_at' => now()]);

        return response()->noContent();
    }

    public function conflicts()
    {
        return OfflineConflict::with('user:id,name', 'sale:id,number')->orderByRaw("status = 'open' DESC")->orderByDesc('id')->paginate(25);
    }

    public function retryConflict(Request $request, OfflineConflict $conflict, OfflineSyncService $sync)
    {
        return $sync->retry($conflict, $request->user());
    }

    public function voidConflict(Request $request, OfflineConflict $conflict, OfflineSyncService $sync)
    {
        $d = $request->validate(['note' => 'required|string|max:255']);

        return $sync->void($conflict, $request->user(), $d['note']);
    }

    public function audit(Request $request)
    {
        return DB::table('audit_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->when($request->query('action'), fn ($q, $a) => $q->where('a.action', 'like', addcslashes($a, '%_\\').'%'))
            ->orderByDesc('a.id')->select('a.*', 'u.name as user_name')->paginate(50);
    }
}
