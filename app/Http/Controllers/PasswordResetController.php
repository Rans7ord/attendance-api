<?php

namespace App\Http\Controllers;

use App\Mail\PasswordResetCodeMail;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Public "forgot password" flow built for a mobile app: the user gets a
 * 6-digit code by email (no web page or deep link needed), types it into
 * the app together with a new password. Codes live in the
 * password_reset_tokens table Laravel already ships, hashed like a
 * password, and expire after CODE_TTL_MINUTES.
 */
class PasswordResetController extends Controller
{
    private const CODE_TTL_MINUTES = 15;
    private const MAX_CODE_ATTEMPTS = 5;

    /**
     * Always answers the same way whether or not the email exists, so this
     * endpoint can't be used to find out who has an account.
     */
    public function forgot(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $email = strtolower($request->email);

        // Max 3 codes per email per 15 minutes, so nobody can use this
        // to flood someone's inbox.
        $sendKey = 'password-reset-send:' . $email;
        if (RateLimiter::tooManyAttempts($sendKey, 3)) {
            return $this->genericResponse();
        }
        RateLimiter::hit($sendKey, self::CODE_TTL_MINUTES * 60);

        $user = User::whereRaw('lower(email) = ?', [$email])->first();

        if ($user && $user->isActive()) {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                ['token' => Hash::make($code), 'created_at' => now()]
            );

            // A mail-server hiccup must not change the response (that
            // would leak which emails exist) — log it and move on.
            try {
                Mail::to($user->email)->send(new PasswordResetCodeMail($user->name, $code, self::CODE_TTL_MINUTES));
            } catch (\Throwable $e) {
                Log::error('Password reset email failed: ' . $e->getMessage());
            }
        }

        return $this->genericResponse();
    }

    public function reset(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => ['required', 'regex:/^\d{6}$/'],
            'password' => 'required|string|min:8|confirmed',
        ]);

        $email = strtolower($request->email);
        $attemptKey = 'password-reset-attempt:' . $email;

        if (RateLimiter::tooManyAttempts($attemptKey, self::MAX_CODE_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'code' => ['Too many attempts. Request a new code and try again.'],
            ]);
        }

        $user = User::whereRaw('lower(email) = ?', [$email])->first();
        $row = $user ? DB::table('password_reset_tokens')->where('email', $user->email)->first() : null;

        $valid = $row
            && $row->created_at
            && Carbon::parse($row->created_at)->gt(now()->subMinutes(self::CODE_TTL_MINUTES))
            && Hash::check($request->code, $row->token);

        if (!$valid) {
            RateLimiter::hit($attemptKey, self::CODE_TTL_MINUTES * 60);
            throw ValidationException::withMessages([
                'code' => ['That code is invalid or has expired.'],
            ]);
        }

        // Works the same way an admin's deactivation does: the password
        // can't be reset into a usable session for a deactivated account.
        if (!$user->isActive()) {
            throw ValidationException::withMessages([
                'email' => ['This account has been deactivated. Contact your admin.'],
            ]);
        }

        $user->update(['password' => $request->password]); // 'hashed' cast hashes it

        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        RateLimiter::clear($attemptKey);

        // Whoever had the old password (or a stolen token) is signed out
        // everywhere; the user logs in fresh with the new one.
        $user->tokens()->delete();

        return response()->json(['message' => 'Password updated. You can log in with your new password.']);
    }

    private function genericResponse()
    {
        return response()->json([
            'message' => 'If that email has an account, a reset code is on its way.',
        ]);
    }
}