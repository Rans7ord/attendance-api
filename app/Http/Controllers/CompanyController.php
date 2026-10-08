<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class CompanyController extends Controller
{
    /**
     * Public: a new company signs up and its first admin account is created
     * in the same step.
     */
    public function register(Request $request)
    {
        $request->validate([
            'company_name' => 'required|string|max:255',
            'industry' => 'nullable|string|max:255',
            'admin_name' => 'required|string|max:255',
            'admin_email' => 'required|email|unique:users,email',
            'admin_password' => 'required|string|min:8',
            'admin_attendance_pin' => ['required', 'regex:/^\\d{4}$/'],
            'photo' => 'required|image|max:5120',
        ]);

        $photoPath = $request->file('photo')->store('profile-photos', 'public');

        [$company, $admin] = DB::transaction(function () use ($request, $photoPath) {
            $company = Company::create([
                'name' => $request->company_name,
                'industry' => $request->industry,
                'join_code' => Company::generateUniqueJoinCode(),
                'status' => 'active',
            ]);

            $admin = User::create([
                'name' => $request->admin_name,
                'email' => $request->admin_email,
                'password' => Hash::make($request->admin_password),
                'role' => 'admin',
                'company_id' => $company->id,
                'attendance_pin_hash' => Hash::make($request->admin_attendance_pin),
                'profile_photo_path' => $photoPath,
            ]);

            return [$company, $admin];
        });

        $token = $admin->createToken('mobile')->plainTextToken;

        return response()->json([
            'token' => $token,
            'role' => $admin->role,
            'company' => $company,
        ], 201);
    }

    /**
     * Admin-only: view the company's current join-code settings and
     * registration policy (e.g. selfie requirement).
     */
    public function show(Request $request)
    {
        $company = $request->user()->company;
        $hasBranch = $company->branches()->exists();

        return array_merge($company->only([
            'id', 'name', 'join_code', 'join_code_expires_at',
            'join_code_max_uses', 'join_code_uses_count', 'require_selfie_on_join',
        ]), [
            // A join code is only usable once there is a branch to put
            // new members in, so don't hand it out before then.
            'join_code' => $hasBranch ? $company->join_code : null,
            'needs_branch' => !$hasBranch,
        ]);
    }

    /**
     * Admin-only: toggle registration policy for this company. Currently
     * just the selfie requirement, but this is the natural place to add
     * more per-company onboarding toggles later.
     */
    public function updateSettings(Request $request)
    {
        $request->validate([
            'require_selfie_on_join' => 'required|boolean',
        ]);

        $company = $request->user()->company;
        $company->update(['require_selfie_on_join' => $request->boolean('require_selfie_on_join')]);

        return response()->json($company->only(['require_selfie_on_join']));
    }

    /**
     * Public: given a join code, list that company's branches so someone
     * self-registering can pick one, and tell the app whether a selfie is
     * required so the form can say so upfront.
     */
    public function branchesForCode(Request $request, string $code)
    {
        $company = Company::where('join_code', strtoupper($code))->first();

        if (!$company || !$company->joinCodeIsUsable()) {
            return response()->json(['message' => 'Invalid or expired join code.'], 404);
        }

        $branches = $company->branches()->get(['id', 'name']);

        if ($branches->isEmpty()) {
            return response()->json(['message' => 'This company is not accepting registrations yet.'], 404);
        }

        return response()->json([
            'company_name' => $company->name,
            'require_selfie_on_join' => $company->require_selfie_on_join,
            'branches' => $branches,
        ]);
    }

    /**
     * Public: lets the invite screen apply the company's registration-photo
     * policy before it shows the rest of the form. The acceptance endpoint
     * still validates the same policy as the source of truth.
     */
    public function inviteRegistrationDetails(Request $request, string $token)
    {
        $invite = \App\Models\Invite::with('company')->where('token', $token)->first();

        if (!$invite || $invite->isUsed() || $invite->isExpired()) {
            throw ValidationException::withMessages(['token' => ['That invite link is invalid or expired.']]);
        }

        return response()->json([
            'require_selfie_on_join' => $invite->company->require_selfie_on_join,
        ]);
    }

    /**
     * Admin-only: rotate the join code, optionally setting a new expiry
     * and/or max-use cap in the same call.
     */
    public function regenerateJoinCode(Request $request)
    {
        $request->validate([
            // Rotating the code makes the old one stop working at once, so
            // the app has to ask the admin first and send confirm=true.
            'confirm' => 'accepted',
            'expires_in_days' => 'nullable|integer|min:1|max:365',
            'max_uses' => 'nullable|integer|min:1',
        ], [
            'confirm.accepted' => 'Confirmation required: send confirm=true to replace the current join code.',
        ]);

        $company = $request->user()->company;

        if (!$company->branches()->exists()) {
            return response()->json([
                'message' => 'Add at least one branch before generating a join code.',
            ], 422);
        }

        $company->update([
            'join_code' => Company::generateUniqueJoinCode(),
            'join_code_expires_at' => $request->filled('expires_in_days')
                ? now()->addDays($request->expires_in_days)
                : null,
            'join_code_max_uses' => $request->max_uses,
            'join_code_uses_count' => 0,
        ]);

        return response()->json($company->only([
            'join_code', 'join_code_expires_at', 'join_code_max_uses', 'join_code_uses_count',
        ]));
    }
}