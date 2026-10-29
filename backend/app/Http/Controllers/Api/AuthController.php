<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Web SPA: cookie session (call GET /sanctum/csrf-cookie first). Mobile apps: send
 * `device_name` and receive a bearer token instead.
 */
class AuthController extends Controller
{
    /**
     * Step one of "log in or sign up": tells the sign-in page whether this email/phone already
     * has an account (ask for the password) or not (show the sign-up form). Throttled like login.
     */
    public function identify(Request $request)
    {
        $data = $request->validate(['login' => 'required|string|max:255']);
        $login = trim($data['login']);
        $isEmail = str_contains($login, '@');
        if ($isEmail ? ! filter_var($login, FILTER_VALIDATE_EMAIL) : ! preg_match('/^\+?[0-9 ]{9,16}$/', $login)) {
            throw ValidationException::withMessages(['login' => 'Enter a valid email address or mobile number.']);
        }
        $user = User::where($isEmail ? 'email' : 'phone', $login)->first();

        return response()->json([
            'exists' => (bool) $user,
            'type' => $isEmail ? 'email' : 'phone',
            // Accounts created with Google or Facebook have no password: send them to that button.
            'google_only' => $user && ! $user->password && $user->google_id,
            'social' => $user && ! $user->password ? ($user->google_id ? 'google' : ($user->facebook_id ? 'facebook' : null)) : null,
        ]);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'login' => 'required|string|max:255',
            'password' => 'required|string|max:255',
            'device_name' => 'nullable|string|max:100',
        ]);
        $field = str_contains($data['login'], '@') ? 'email' : 'phone';
        $user = User::where($field, $data['login'])->first();

        if ($user && ! $user->password && ($user->google_id || $user->facebook_id)) {
            $via = $user->google_id ? 'Google' : 'Facebook';
            throw ValidationException::withMessages(['login' => "This account signs in with {$via}. Use the {$via} button."]);
        }
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['login' => 'These credentials do not match our records.']);
        }
        if (! $user->is_active) {
            throw ValidationException::withMessages(['login' => 'This account is disabled.']);
        }
        $user->forceFill(['last_login_at' => now()])->save();

        // Mobile apps / non-browser clients (no session from the SPA's origin) get a bearer token.
        if (! empty($data['device_name']) || ! $request->hasSession()) {
            $token = $user->createToken($data['device_name'] ?? 'api', $user->abilities() ?: ['customer'])->plainTextToken;

            return response()->json(['token' => $token, 'user' => $this->present($user)]);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json(['user' => $this->present($user)]);
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:255|unique:users,email',
            'phone' => 'nullable|string|max:30|unique:users,phone',
            'password' => ['required', 'confirmed', Password::min(8)],
            'device_name' => 'nullable|string|max:100',
        ]);
        $user = DB::transaction(function () use ($data) {
            $user = new User($data); // role is not fillable: a sign-up is always a customer
            $user->role = 'customer';
            $user->is_active = true;
            $user->save();
            Customer::create(['user_id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone]);

            return $user;
        });

        if (! empty($data['device_name']) || ! $request->hasSession()) {
            return response()->json(['token' => $user->createToken($data['device_name'] ?? 'api', ['customer'])->plainTextToken, 'user' => $this->present($user)], 201);
        }
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json(['user' => $this->present($user)], 201);
    }

    /**
     * Sign in (or sign up) with Google or Facebook. Only customer accounts may use them: staff
     * keep password sign-in, so a social account can never take over a till or the back office.
     */
    public function google(Request $request, \App\Services\GoogleAuth $google)
    {
        $data = $request->validate(['credential' => 'required|string|max:4096', 'device_name' => 'nullable|string|max:100']);

        return $this->socialLogin($request, 'google_id', $google->verify($data['credential']), $data['device_name'] ?? null);
    }

    public function facebook(Request $request, \App\Services\FacebookAuth $facebook)
    {
        $data = $request->validate(['access_token' => 'required|string|max:4096', 'device_name' => 'nullable|string|max:100']);

        return $this->socialLogin($request, 'facebook_id', $facebook->verify($data['access_token']), $data['device_name'] ?? null);
    }

    /** @param array{sub:string, email:string, name:string, picture:?string} $p  a profile the provider verified */
    private function socialLogin(Request $request, string $idColumn, array $p, ?string $deviceName)
    {
        $user = DB::transaction(function () use ($idColumn, $p) {
            $user = User::where($idColumn, $p['sub'])->lockForUpdate()->first()
                ?? User::where('email', $p['email'])->lockForUpdate()->first();
            if ($user && $user->isStaff()) {
                throw ValidationException::withMessages(['login' => 'Staff accounts sign in with their password.']);
            }
            if (! $user) {
                $user = new User(['name' => $p['name'], 'email' => $p['email']]);
                $user->role = 'customer';
                $user->is_active = true;
                $user->email_verified_at = now(); // the provider verified the address
            }
            // Linking by email is safe: the provider confirmed this person controls that address.
            $user->{$idColumn} = $p['sub'];
            $user->avatar_url = $user->avatar_url ?: $p['picture'];
            $user->save();
            if (! $user->customer) {
                Customer::create(['user_id' => $user->id, 'name' => $user->name, 'email' => $user->email]);
            }

            return $user;
        });
        if (! $user->is_active) {
            throw ValidationException::withMessages(['login' => 'This account is disabled.']);
        }
        $user->forceFill(['last_login_at' => now()])->save();

        if (! empty($deviceName) || ! $request->hasSession()) {
            return response()->json(['token' => $user->createToken($deviceName ?? 'api', ['customer'])->plainTextToken, 'user' => $this->present($user)]);
        }
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json(['user' => $this->present($user)]);
    }

    public function me(Request $request)
    {
        return response()->json(['user' => $this->present($request->user())]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        if ($token && method_exists($token, 'delete') && ! $token instanceof \Laravel\Sanctum\TransientToken) {
            $token->delete();
        } else {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    private function present(User $u): array
    {
        $c = $u->isStaff() ? null : $u->customer()->first();

        return [
            // A customer's delivery phone lives on their profile (Google sign-ups have no account phone).
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone ?? $c?->phone,
            'role' => $u->role, 'is_staff' => $u->isStaff(), 'abilities' => $u->abilities(),
            'avatar_url' => $u->avatar_url, 'uses_google' => (bool) $u->google_id, 'uses_facebook' => (bool) $u->facebook_id,
            // Customers are asked for their location once after signing up.
            'needs_location' => $c !== null && $c->latitude === null,
            'location' => $c && $c->latitude !== null ? [
                'lat' => (float) $c->latitude, 'lng' => (float) $c->longitude, 'label' => $c->location_label,
                'district' => $c->district, 'zone' => $c->delivery_zone, 'address' => $c->address,
            ] : null,
        ];
    }
}
