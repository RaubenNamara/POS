<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\Http;

/**
 * Verifies a Facebook Login access token from the browser. Facebook confirms the token is
 * valid and was issued to OUR app (debug_token with the app secret), then gives us the
 * person's id, name and email. A token made for another app is refused.
 */
class FacebookAuth
{
    private const GRAPH = 'https://graph.facebook.com/v21.0';

    public static function configured(): bool
    {
        return (bool) config('services.facebook.app_id') && (bool) config('services.facebook.app_secret');
    }

    /** @return array{sub:string, email:string, name:string, picture:?string} */
    public function verify(string $accessToken): array
    {
        if (! self::configured()) {
            throw new BusinessRuleException('Facebook sign-in is not set up yet', 'facebook_not_configured');
        }
        $appId = (string) config('services.facebook.app_id');
        $secret = (string) config('services.facebook.app_secret');
        try {
            $check = Http::timeout(8)->get(self::GRAPH.'/debug_token', ['input_token' => $accessToken, 'access_token' => $appId.'|'.$secret]);
            $t = $check->json('data') ?? [];
            $valid = $check->ok() && ($t['is_valid'] ?? false) === true && (string) ($t['app_id'] ?? '') === $appId && ! empty($t['user_id']);
            if (! $valid) {
                throw new BusinessRuleException('Facebook sign-in could not be verified', 'facebook_invalid');
            }
            $me = Http::timeout(8)->get(self::GRAPH.'/me', [
                'fields' => 'id,name,email,picture.type(large)',
                'access_token' => $accessToken,
                'appsecret_proof' => hash_hmac('sha256', $accessToken, $secret),
            ]);
        } catch (BusinessRuleException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            throw new BusinessRuleException('Could not reach Facebook. Try again.', 'facebook_unreachable');
        }
        $u = $me->json() ?? [];
        if (! $me->ok() || (string) ($u['id'] ?? '') !== (string) $t['user_id']) {
            throw new BusinessRuleException('Facebook sign-in could not be verified', 'facebook_invalid');
        }
        // Facebook only shares a confirmed email, and only if the person allowed it.
        if (empty($u['email'])) {
            throw new BusinessRuleException('Your Facebook account did not share an email address. Please continue with your email or phone instead.', 'facebook_no_email');
        }

        return [
            'sub' => (string) $u['id'],
            'email' => mb_strtolower($u['email']),
            'name' => $u['name'] ?? explode('@', $u['email'])[0],
            'picture' => $u['picture']['data']['url'] ?? null,
        ];
    }
}
