<?php
namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Branch;
use App\Models\LeaveRequest;
use App\Models\Member;
use App\Models\Shift;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MembersController extends Controller
{
    public function index(Request $request)
    {
        $query = Member::with(['branch', 'user:id,role', 'shift:id,name']);

        if ($request->user()->isSupervisor()) {
            $query->where('branch_id', $request->user()->branchId());
        }

        return $query->get()->map(function ($member) {
            $data = $member->toArray();
            $data['role'] = $member->user?->role;
            unset($data['user']);
            return $data;
        });
    }

    private function validateShiftBelongsToBranch(?int $shiftId, ?int $branchId): ?string
    {
        if (!$shiftId) {
            return null;
        }
        if (!$branchId) {
            return 'A branch must be set before assigning a shift.';
        }
        $shift = Shift::find($shiftId);
        if (!$shift || $shift->branch_id !== $branchId) {
            return "That shift does not belong to this member's branch.";
        }
        return null;
    }

    public function store(Request $request)
    {
        $request->validate([
            'first_name' => 'required|string',
            'last_name' => 'required|string',
            'phone' => 'nullable|string',
            'email' => 'nullable|email',
            'position' => 'nullable|string',
            'branch_id' => 'nullable|exists:branches,id',
            'shift_id' => 'nullable|exists:shifts,id',
        ]);

        if ($error = $this->validateShiftBelongsToBranch($request->shift_id, $request->branch_id)) {
            return response()->json(['message' => $error], 422);
        }

        $member = Member::create($request->only([
            'first_name', 'last_name', 'phone', 'email', 'position', 'branch_id', 'shift_id',
        ]));

        return response()->json($member, 201);
    }

    public function update(Request $request, Member $member)
    {
        $request->validate([
            'first_name' => 'sometimes|string',
            'last_name' => 'sometimes|string',
            'phone' => 'nullable|string',
            'email' => 'nullable|email',
            'position' => 'nullable|string',
            'branch_id' => 'nullable|exists:branches,id',
            'shift_id' => 'nullable|exists:shifts,id',
            'status' => 'sometimes|in:active,inactive',
        ]);

        $resultingBranchId = $request->has('branch_id') ? $request->branch_id : $member->branch_id;

        if ($request->has('shift_id') && ($error = $this->validateShiftBelongsToBranch($request->shift_id, $resultingBranchId))) {
            return response()->json(['message' => $error], 422);
        }

        $deactivating = $request->input('status') === 'inactive' && $member->status !== 'inactive';

        if ($deactivating && ($blocked = $this->deactivationBlocker($request, $member))) {
            return $blocked;
        }

        $member->update($request->only([
            'first_name', 'last_name', 'phone', 'email', 'position', 'branch_id', 'shift_id', 'status',
        ]));

        // Sign the person out everywhere the moment they are deactivated.
        // (The 'active' middleware also blocks any token that slips through.)
        if ($deactivating) {
            $member->user?->tokens()->delete();
        }

        return response()->json($member);
    }

    public function show(Request $request, Member $member)
    {
        if ($request->user()->isSupervisor() && $member->branch_id !== $request->user()->branchId()) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return $member->load(['branch', 'shift']);
    }

    public function bulkImport(Request $request)
    {
        $request->validate([
            'rows' => 'required|array|min:1',
            'rows.*.first_name' => 'required|string',
            'rows.*.last_name' => 'required|string',
            'rows.*.email' => 'required|email',
            'rows.*.phone' => 'nullable|string',
            'rows.*.branch_id' => 'nullable|exists:branches,id',
        ]);

        $created = [];
        $skipped = [];

        foreach ($request->rows as $row) {
            $exists = Member::where('email', $row['email'])->exists();

            if ($exists) {
                $skipped[] = $row['email'];
                continue;
            }

            $created[] = Member::create([
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'email' => $row['email'],
                'phone' => $row['phone'] ?? null,
                'branch_id' => $row['branch_id'] ?? null,
                'status' => 'active',
            ]);
        }

        return response()->json([
            'created_count' => count($created),
            'skipped_emails' => $skipped,
        ], 201);
    }

    // ------------------------------------------------------------------
    // Roles, deactivation and deletion (admin only)
    // ------------------------------------------------------------------

    /** Change a member's role. Needs confirm=true. */
    public function updateRole(Request $request, Member $member)
    {
        $request->validate([
            'role' => 'required|in:member,supervisor,manager,admin',
            'branch_id' => 'nullable|integer',
            'confirm' => 'accepted',
        ], [
            'confirm.accepted' => 'Confirmation required: send confirm=true to change this role.',
        ]);

        $user = $member->user;

        if (!$user) {
            return response()->json(['message' => 'This member has not registered yet, so they have no role to change.'], 422);
        }

        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot change your own role.'], 422);
        }

        if ($user->role === 'super_admin') {
            return response()->json(['message' => 'A super admin role cannot be changed here.'], 403);
        }

        $oldRole = $user->role;
        $newRole = $request->role;

        if ($oldRole === $newRole) {
            return response()->json(['message' => "This person is already a {$newRole}."], 422);
        }

        if ($user->isAdmin() && $newRole !== 'admin' && $this->isLastActiveAdmin($member)) {
            return response()->json(['message' => 'You cannot remove the last active admin. Make someone else an admin first.'], 422);
        }

        if ($request->filled('branch_id') && !Branch::find($request->branch_id)) {
            return response()->json(['message' => 'That branch does not belong to this company.'], 422);
        }

        $branchId = $request->filled('branch_id') ? (int) $request->branch_id : $member->branch_id;

        if (in_array($newRole, ['member', 'supervisor'], true) && !$branchId) {
            return response()->json(['message' => 'A branch is required for a member or supervisor. Send branch_id.'], 422);
        }

        DB::transaction(function () use ($member, $user, $newRole, $branchId) {
            // A shift belongs to one branch, so moving branch clears it.
            $updates = ['branch_id' => $branchId];
            if ($branchId !== $member->branch_id) {
                $updates['shift_id'] = null;
            }
            $member->update($updates);

            $user->update(['role' => $newRole]);
        });

        UserNotification::send(
            $user,
            'role_changed',
            'Your role changed',
            "Your role is now {$newRole} (it was {$oldRole}).",
            ['role' => $newRole]
        );

        $member = $member->fresh()->load(['branch', 'shift']);

        return response()->json(['member' => $member, 'role' => $newRole]);
    }

    /** Soft revoke: blocks login and clock-in, keeps all records. Needs confirm=true. */
    public function deactivate(Request $request, Member $member)
    {
        $request->validate(['confirm' => 'accepted'], [
            'confirm.accepted' => 'Confirmation required: send confirm=true to deactivate this member.',
        ]);

        if ($member->status === 'inactive') {
            return response()->json(['message' => 'This member is already inactive.'], 422);
        }

        if ($blocked = $this->deactivationBlocker($request, $member, false)) {
            return $blocked;
        }

        $member->update(['status' => 'inactive']);
        $member->user?->tokens()->delete();

        return response()->json($member->fresh());
    }

    public function reactivate(Request $request, Member $member)
    {
        if ($member->status !== 'inactive') {
            return response()->json(['message' => 'This member is already active.'], 422);
        }

        $member->update(['status' => 'active']);

        return response()->json($member->fresh());
    }

    /**
     * Permanently delete a member (and their login). Only allowed when they
     * have no attendance or leave history, so reports never lose records;
     * otherwise deactivate them. Needs confirm=true.
     */
    public function destroy(Request $request, Member $member)
    {
        $request->validate(['confirm' => 'accepted'], [
            'confirm.accepted' => 'Confirmation required: send confirm=true to delete this member.',
        ]);

        if ($member->user_id && $member->user_id === $request->user()->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        if ($this->isLastActiveAdmin($member)) {
            return response()->json(['message' => 'You cannot delete the last active admin.'], 422);
        }

        $hasHistory = Attendance::where('member_id', $member->id)->exists()
            || LeaveRequest::where('member_id', $member->id)->exists();

        if ($hasHistory) {
            return response()->json([
                'message' => 'This member has attendance or leave history. Deactivate them instead so records and reports stay intact.',
                'has_history' => true,
            ], 422);
        }

        DB::transaction(function () use ($member) {
            $user = $member->user;
            $member->delete();

            if ($user) {
                $user->tokens()->delete();
                $user->delete();
            }
        });

        return response()->json(['message' => 'Member deleted.']);
    }

    /** True when this member's login is the only active admin left. */
    private function isLastActiveAdmin(Member $member): bool
    {
        $user = $member->user;

        if (!$user || !$user->isAdmin()) {
            return false;
        }

        $otherActiveAdmins = User::whereIn('role', ['admin', 'super_admin'])
            ->where('id', '!=', $user->id)
            ->get()
            ->filter(fn (User $u) => $u->isActive())
            ->count();

        return $otherActiveAdmins === 0;
    }

    /**
     * Shared checks before deactivating: not yourself, not the last admin,
     * and (for the PUT route, which has no confirm rule of its own) an
     * explicit confirm=true.
     */
    private function deactivationBlocker(Request $request, Member $member, bool $needsConfirm = true): ?JsonResponse
    {
        if ($member->user_id && $member->user_id === $request->user()->id) {
            return response()->json(['message' => 'You cannot deactivate your own account.'], 422);
        }

        if ($this->isLastActiveAdmin($member)) {
            return response()->json(['message' => 'You cannot deactivate the last active admin.'], 422);
        }

        if ($needsConfirm && !$request->boolean('confirm')) {
            return response()->json([
                'message' => 'Confirmation required: send confirm=true to deactivate this member.',
            ], 422);
        }

        return null;
    }
}