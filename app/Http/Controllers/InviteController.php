<?php

namespace App\Http\Controllers;

use App\Mail\InviteMail;
use App\Models\Branch;
use App\Models\Invite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class InviteController extends Controller
{
    private const ROLES = ['member', 'supervisor', 'manager', 'admin'];

    /**
     * Admin-only: create a one-time invite for a specific email address
     * and email it to that address (needs SMTP set up in .env — with the
     * default "log" mailer the email is only written to the log file).
     *
     * The token is still returned in the response so the admin can share
     * it by hand (WhatsApp, text) if the email doesn't arrive.
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
        // somewhere to belong. Admins and managers oversee the whole
        // company and aren't required to have one (they can be given a
        // home branch later if they also need to clock in).
        if (in_array($role, ['member', 'supervisor'], true) && !$request->filled('branch_id')) {
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

        $emailed = $this->sendInviteEmail($invite, $company->name);

        return response()->json([
            'invite' => $invite,
            'token' => $invite->token,
            // false means the mail server refused it (see storage/logs) —
            // the app can tell the admin to share the token manually.
            'emailed' => $emailed,
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

    /**
     * A mail-server problem must not stop the invite from being created,
     * so failures are logged and reported as emailed=false instead of
     * turning into a 500.
     */
    private function sendInviteEmail(Invite $invite, string $companyName): bool
    {
        try {
            Mail::to($invite->email)->send(new InviteMail($invite, $companyName));

            return true;
        } catch (\Throwable $e) {
            Log::error('Invite email failed: ' . $e->getMessage());

            return false;
        }
    }
}