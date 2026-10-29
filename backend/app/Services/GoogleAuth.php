<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\Http;

/**
 * Verifies a Google Identity Services ID token (the "credential" the Sign in with Google
 * button returns). Google checks the signature; we check it was issued for OUR client id,
 * by Google, is unexpired and the email is verified.
 */
class GoogleAuth
{
    /** @return array{sub:string, email:string, name:string, picture:?string} */
    public function verify(string $idToken): array
    {
        $clientId = config('services.google.client_id');
        if (! $clientId) {
            throw new BusinessRuleException('Google sign-in is not set up yet', 'google_not_configured');
        }
        try {
            $r = Http::timeout(8)->get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => $idToken]);
        } catch (\Throwable $e) {
            report($e);
            throw new BusinessRuleException('Could not reach Google. Try again.', 'google_unreachable');
        }
        $t = $r->json() ?? [];
        $valid = $r->ok()
            && ($t['aud'] ?? null) === $clientId
            && in_array($t['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)
            && (int) ($t['exp'] ?? 0) > time()
            && in_array($t['email_verified'] ?? false, [true, 'true'], true)
            && ! empty($t['sub']) && ! empty($t['email']);
        if (! $valid) {
            throw new BusinessRuleException('Google sign-in could not be verified', 'google_invalid');
        }

        return [
            'sub' => (string) $t['sub'],
            'email' => mb_strtolower($t['email']),
            'name' => $t['name'] ?? explode('@', $t['email'])[0],
            'picture' => $t['picture'] ?? null,
        ];
    }
}
