<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Invite;
use App\Models\Member;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class MemberRegistrationController extends Controller
{
    /**
     * Public: a member joins a company using its join code. Also sets up
     * their attendance PIN — same convention as CompanyController::register()
     * for the admin: 'attendance_pin' field, hashed into attendance_pin_hash,
     * confirmation handled client-side only.
     */
    public function register(Request $request)
    {
        $request->validate([
            'join_code' => 'required|string',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'phone' => 'nullable|string|max:50',
            'branch_id' => 'required|integer',
            'attendance_pin' => ['required', 'regex:/^\d{4}$/'],
        ]);

        $company = Company::where('join_code', strtoupper($request->join_code))->first();

        if (!$company) {
            throw ValidationException::withMessages(['join_code' => ['That join code was not recognized.']]);
        }

        if (!$company->joinCodeIsUsable()) {
            throw ValidationException::withMessages(['join_code' => ['That join code has expired or reached its limit. Ask an admin for a new one.']]);
        }

        $request->validate([
            'photo' => ($company->require_selfie_on_join ? 'required' : 'nullable') . '|image|max:5120',
        ]);

        $branch = null;
        if ($request->filled('branch_id')) {
            $branch = Branch::where('company_id', $company->id)->find($request->branch_id);
            if (!$branch) {
                throw ValidationException::withMessages(['branch_id' => ['That branch does not belong to this company.']]);
            }
        }

        $photoPath = $request->hasFile('photo')
            ? $request->file('photo')->store('member-photos', 'public')
            : null;

        [$user, $member] = DB::transaction(function () use ($request, $company, $branch, $photoPath) {
            $user = User::create([
                'name' => trim($request->first_name . ' ' . $request->last_name),
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'attendance_pin_hash' => Hash::make($request->attendance_pin),
                'profile_photo_path' => $photoPath,
                'role' => 'member',
                'company_id' => $company->id,
            ]);

            $member = Member::where('company_id', $company->id)
                ->whereNull('user_id')
                ->where('email', $request->email)
                ->first();

            if ($member) {
                $member->update([
                    'user_id' => $user->id,
                    'phone' => $request->phone ?? $member->phone,
                    'branch_id' => $branch?->id ?? $member->branch_id,
                    'photo_path' => $photoPath ?? $member->photo_path,
                    'status' => 'active',
                ]);
            } else {
                $member = Member::create([
                    'user_id' => $user->id,
                    'company_id' => $company->id,
                    'first_name' => $request->first_name,
                    'last_name' => $request->last_name,
                    'email' => $request->email,
                    'phone' => $request->phone,
                    'branch_id' => $branch?->id,
                    'photo_path' => $photoPath,
                    'status' => 'active',
                ]);
            }

            $company->increment('join_code_uses_count');

            return [$user, $member];
        });

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'token' => $token,
            'role' => $user->role,
            'member' => $member,
        ], 201);
    }

    /**
     * Public: redeem a targeted, per-email invite. Same PIN convention.
     */
    public function acceptInvite(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'password' => 'required|string|min:8',
            'phone' => 'nullable|string|max:50',
            'attendance_pin' => ['required', 'regex:/^\d{4}$/'],
        ]);

        $invite = Invite::with('company')->where('token', $request->token)->first();

        if (!$invite) {
            throw ValidationException::withMessages(['token' => ['That invite link is invalid.']]);
        }

        if ($invite->isUsed()) {
            throw ValidationException::withMessages(['token' => ['That invite has already been used.']]);
        }

        if ($invite->isExpired()) {
            throw ValidationException::withMessages(['token' => ['That invite has expired. Ask an admin to send a new one.']]);
        }

        if (User::where('email', $invite->email)->exists()) {
            throw ValidationException::withMessages(['token' => ['An account already exists for this email — try logging in instead.']]);
        }

        $request->validate([
            'photo' => ($invite->company->require_selfie_on_join ? 'required' : 'nullable') . '|image|max:5120',
        ]);

        $photoPath = $request->hasFile('photo')
            ? $request->file('photo')->store('member-photos', 'public')
            : null;

        [$user, $member] = DB::transaction(function () use ($request, $invite, $photoPath) {
            $user = User::create([
                'name' => trim($request->first_name . ' ' . $request->last_name),
                'email' => $invite->email,
                'password' => Hash::make($request->password),
                'attendance_pin_hash' => Hash::make($request->attendance_pin),
                'profile_photo_path' => $photoPath,
                'role' => $invite->role,
                'company_id' => $invite->company_id,
            ]);

            $member = Member::where('company_id', $invite->company_id)
                ->whereNull('user_id')
                ->where('email', $invite->email)
                ->first();

            if ($member) {
                $member->update([
                    'user_id' => $user->id,
                    'phone' => $request->phone ?? $member->phone,
                    'branch_id' => $invite->branch_id ?? $member->branch_id,
                    'photo_path' => $photoPath ?? $member->photo_path,
                    'status' => 'active',
                ]);
            } else {
                $member = Member::create([
                    'user_id' => $user->id,
                    'company_id' => $invite->company_id,
                    'first_name' => $request->first_name,
                    'last_name' => $request->last_name,
                    'email' => $invite->email,
                    'phone' => $request->phone,
                    'branch_id' => $invite->branch_id,
                    'photo_path' => $photoPath,
                    'status' => 'active',
                ]);
            }

            $invite->update(['used_at' => now()]);

            return [$user, $member];
        });

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'token' => $token,
            'role' => $user->role,
            'member' => $member,
        ], 201);
    }
}