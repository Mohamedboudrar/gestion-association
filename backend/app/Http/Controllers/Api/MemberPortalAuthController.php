<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Services\MailerSendService;
use App\Services\PasskeyService;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Passkey-based authentication for the public Member Portal — completely
 * separate from AuthController's email/password bureau login, but issuing
 * the exact same kind of Sanctum personal access token against the exact
 * same User model. No parallel auth system, no parallel user table.
 */
class MemberPortalAuthController extends Controller
{
    private const MAX_LOGIN_ATTEMPTS = 5;

    private const LOGIN_LOCKOUT_SECONDS = 900; // 15 minutes

    private const MAX_RESET_ATTEMPTS = 3;

    private const RESET_LOCKOUT_SECONDS = 900; // 15 minutes

    public function login(Request $request, PasskeyService $passkeys)
    {
        $request->validate([
            'passkey' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        $key = 'member-portal-login:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_LOGIN_ATTEMPTS)) {
            return response()->json([
                'message' => __('messages.member_portal.too_many_login_attempts'),
            ], 429);
        }

        $user = $passkeys->resolve($request->input('passkey'));

        if (! $user) {
            RateLimiter::hit($key, self::LOGIN_LOCKOUT_SECONDS);

            // Deliberately generic — never reveals whether the passkey was
            // simply wrong or belonged to nobody.
            return response()->json([
                'message' => __('messages.member_portal.invalid_passkey'),
            ], 401);
        }

        RateLimiter::clear($key);

        $token = $user->createToken('member-portal')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function forgotPasskey(Request $request, PasskeyService $passkeys, MailerSendService $mailer)
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $key = 'member-portal-forgot:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_RESET_ATTEMPTS)) {
            return response()->json([
                'message' => __('messages.member_portal.too_many_requests'),
            ], 429);
        }

        RateLimiter::hit($key, self::RESET_LOCKOUT_SECONDS);

        $member = Member::whereHas('user', function ($query) use ($request) {
            $query->where('email', $request->input('email'));
        })->with('user')->first();

        // A pending (never-verified) subscriber has no passkey to reset —
        // silently skip issuing one, but still return the same generic
        // response so this can't be used to probe which emails exist or
        // which subscribers are verified.
        if ($member?->user?->hasPortalAccess()) {
            $user = $member->user;
            $passkey = $passkeys->issueFor($user);
            $settings = SettingsService::get();

            $mailer->send(
                $user->email,
                $user->name,
                __('emails.passkey_reset.subject', ['association' => $settings->association_name]),
                view('emails.passkey-reset', [
                    'name' => $user->name,
                    'passkey' => $passkey,
                    'portalUrl' => rtrim(config('app.frontend_url'), '/').'/member',
                    'associationName' => $settings->association_name,
                    'associationLogoUrl' => $settings->association_logo ? asset('storage/'.$settings->association_logo) : null,
                    'associationAddress' => $settings->address,
                    'associationPhone' => $settings->phone,
                    'associationEmail' => $settings->email,
                ])->render(),
            );
        }

        return response()->json([
            'message' => __('messages.member_portal.passkey_reset_sent'),
        ]);
    }
}
