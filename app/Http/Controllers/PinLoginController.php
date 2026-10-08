<?php

namespace App\Http\Controllers;

use App\Models\PinLoginAttempt;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class PinLoginController extends Controller
{
    /**
     * Public: sign in on a device that isn't the person's own — no
     * password, just email + their attendance PIN + a fresh selfie every
     * time. This intentionally does NOT trust the device at all (unlike
     * the normal password login, which relies on the phone's own lock
     * screen / biometrics to gate access afterward) — the selfie is what
     * stands in for that trust here.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'pin' => 'required|digits:4',
            'photo' => 'required|image|max:5120',
        ]);

        $rateLimitKey = 'pin-login:' . strtolower($request->email);

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            throw ValidationException::withMessages([
                'pin' => ["Too many attempts. Try again in " . ceil($seconds / 60) . " minute(s)."],
            ]);
        }

        $user = User::where('email', $request->email)->first();
        $photoPath = $request->file('photo')->store('pin-login-photos', 'public');

        $success = $user
            && $user->attendance_pin_hash
            && Hash::check($request->pin, $user->attendance_pin_hash);

        PinLoginAttempt::create([
            'user_id' => $user?->id,
            'company_id' => $user?->company_id,
            'email_attempted' => $request->email,
            'photo_path' => $photoPath,
            'success' => $success,
            'ip_address' => $request->ip(),
        ]);

        if (!$success) {
            RateLimiter::hit($rateLimitKey, 900); // 15 minute lockout window
            throw ValidationException::withMessages([
                'pin' => ['That email/PIN combination is not correct.'],
            ]);
        }

        RateLimiter::clear($rateLimitKey);

        if (!$user->isActive()) {
            throw ValidationException::withMessages([
                'pin' => ['This account has been deactivated. Contact your admin.'],
            ]);
        }

        $token = $user->createToken('mobile-pin-login')->plainTextToken;

        return response()->json([
            'token' => $token,
            'role' => $user->role,
        ]);
    }
}