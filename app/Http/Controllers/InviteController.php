<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Invite;
use Illuminate\Http\Request;

class InviteController extends Controller
{
    private const ROLES = ['member', 'supervisor', 'admin'];

    /**
     * Admin-only: create a one-time invite for a specific email address.
     * Doesn't send anything itself — email delivery needs SMTP credentials
     * configured in .env first (config/mail.php is still on the default
     * "log" driver). Until that's set up, the admin copies the returned
     * link and sends it however they already communicate with people
     * (email, WhatsApp, text). Wiring up actual sending is a small follow-up
     * once you've picked a mail provider.
     */
    public function store(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'role' => 'nullable|in:' . implode(',', self::ROLES),
            'branch_id' => 'nullable|exists:branches,id',
            'expires_in_days' => 'nullable|integer|min:1|max:90',
        ]);

        $role = $request->input('role', 'member');

        // Supervisors and members are tied to one branch, so they need
        // somewhere to belong. Admins oversee the whole company and
        // aren't required to have one.
        if ($role !== 'admin' && !$request->filled('branch_id')) {
            return response()->json([
                'message' => 'A branch is required when inviting a member or supervisor.',
            ], 422);
        }

        $company = $request->user()->company;

        if ($request->filled('branch_id')) {
            $belongs = Branch::where('company_id', $company->id)->where('id', $request->branch_id)->exists();
            if (!$belongs) {
                return response()->json(['message' => 'That branch does not belong to this company.'], 422);
            }
        }

        // Reuse a still-valid pending invite for this email instead of
        // stacking up duplicates every time an admin re-sends it.
        $existing = Invite::where('company_id', $company->id)
            ->where('email', $request->email)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        $invite = $existing ?: Invite::create([
            'company_id' => $company->id,
            'branch_id' => $request->branch_id,
            'email' => $request->email,
            'role' => $role,
            'token' => Invite::generateToken(),
            'expires_at' => now()->addDays($request->input('expires_in_days', 7)),
        ]);

        return response()->json([
            'invite' => $invite,
            // Frontend (web admin / Flutter) should build the real deep
            // link around this token, e.g. ontimeapp://accept-invite/{token}
            'token' => $invite->token,
        ], 201);
    }

    public function index(Request $request)
    {
        $company = $request->user()->company;

        return Invite::where('company_id', $company->id)
            ->orderByDesc('created_at')
            ->get();
    }

    public function destroy(Request $request, Invite $invite)
    {
        if ($invite->company_id !== $request->user()->company_id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $invite->delete();

        return response()->json(['message' => 'Invite revoked.']);
    }
}