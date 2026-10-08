<?php
namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Models\Member;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class LeaveController extends Controller
{
    /** The logged-in user's own requests, newest first. */
    public function mine(Request $request)
    {
        $member = $request->user()->member;
        if (!$member) {
            return response()->json([]);
        }

        return LeaveRequest::where('member_id', $member->id)
            ->orderByDesc('created_at')
            ->get();
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $member = $user->member;

        if (!$member) {
            return response()->json(['message' => 'Your account has no member profile, so it cannot request leave.'], 422);
        }

        $request->validate([
            'type' => 'required|in:full_day,half_day',
            'start_date' => 'required|date_format:Y-m-d|after_or_equal:today',
            'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date',
            'reason' => 'required|string|max:500',
        ]);

        if ($request->type === 'half_day' && $request->start_date !== $request->end_date) {
            return response()->json(['message' => 'A half-day leave must be a single date.'], 422);
        }

        $overlap = LeaveRequest::where('member_id', $member->id)
            ->whereIn('status', ['pending', 'approved'])
            ->where('start_date', '<=', $request->end_date)
            ->where('end_date', '>=', $request->start_date)
            ->exists();

        if ($overlap) {
            return response()->json(['message' => 'You already have a pending or approved leave request covering some of those dates.'], 422);
        }

        // Nobody sits above an admin, so an admin's own request is
        // approved on the spot instead of waiting for a reviewer.
        $autoApprove = $user->isAdmin();

        $leave = LeaveRequest::create([
            'member_id' => $member->id,
            'type' => $request->type,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'reason' => $request->reason,
            'status' => $autoApprove ? 'approved' : 'pending',
            'reviewed_by' => $autoApprove ? $user->id : null,
            'reviewed_at' => $autoApprove ? now() : null,
        ]);

        if (!$autoApprove) {
            $name = trim($member->first_name . ' ' . $member->last_name);
            foreach ($this->approversFor($member, $user) as $approver) {
                UserNotification::send(
                    $approver,
                    'leave_requested',
                    'New leave request',
                    "$name requested " . $this->describe($leave) . '.',
                    ['leave_request_id' => $leave->id]
                );
            }
        }

        return response()->json($leave, 201);
    }

    /**
     * Requests an admin/supervisor can act on. ?status=pending (default),
     * approved, rejected, cancelled, or all. A supervisor only ever sees
     * plain members of their own branch, matching what they can approve.
     */
    public function review(Request $request)
    {
        $user = $request->user();
        $status = $request->query('status', 'pending');

        $query = LeaveRequest::with('member:id,first_name,last_name,photo_path,branch_id,user_id')
            ->orderByDesc('created_at');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($user->isSupervisor()) {
            $query->whereHas('member', function ($q) use ($user) {
                $q->where('branch_id', $user->branchId())
                  ->whereHas('user', fn ($u) => $u->where('role', 'member'));
            });
        }

        return $query->get();
    }

    public function approve(Request $request, LeaveRequest $leaveRequest)
    {
        return $this->decide($request, $leaveRequest, 'approved');
    }

    public function reject(Request $request, LeaveRequest $leaveRequest)
    {
        return $this->decide($request, $leaveRequest, 'rejected');
    }

    private function decide(Request $request, LeaveRequest $leave, string $decision)
    {
        $reviewer = $request->user();
        $request->validate(['note' => 'nullable|string|max:500']);

        if ($leave->status !== 'pending') {
            return response()->json(['message' => "This request is already {$leave->status}."], 422);
        }

        $leave->load('member.user');
        $member = $leave->member;
        $requesterUser = $member->user;

        if ($requesterUser && $requesterUser->id === $reviewer->id) {
            return response()->json(['message' => 'You cannot review your own leave request.'], 403);
        }

        if ($reviewer->isSupervisor()) {
            $isPlainMemberOfMyBranch = $member->branch_id === $reviewer->branchId()
                && $requesterUser?->role === 'member';
            if (!$isPlainMemberOfMyBranch) {
                return response()->json(['message' => 'Not found.'], 404);
            }
        }

        $leave->update([
            'status' => $decision,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_note' => $request->note,
        ]);

        $reviewerName = $reviewer->name;
        $word = $decision === 'approved' ? 'approved' : 'rejected';
        $type = $decision === 'approved' ? 'leave_approved' : 'leave_rejected';
        $data = ['leave_request_id' => $leave->id];

        if ($requesterUser) {
            UserNotification::send(
                $requesterUser,
                $type,
                "Leave $word",
                'Your ' . $this->describe($leave) . " was $word by $reviewerName."
                    . ($request->note ? " Note: {$request->note}" : ''),
                $data
            );
        }

        // The other approver tier hears about it too: a supervisor's
        // decision reaches the admins, an admin's reaches the supervisors.
        $name = trim($member->first_name . ' ' . $member->last_name);
        foreach ($this->approversFor($member, $requesterUser ?? $reviewer) as $other) {
            if ($other->id === $reviewer->id) {
                continue;
            }
            UserNotification::send(
                $other,
                $type,
                "Leave $word",
                "$reviewerName $word $name's " . $this->describe($leave) . '.',
                $data
            );
        }

        return response()->json($leave->fresh());
    }

    /** Owner cancels a pending request, or an approved one that hasn't finished. */
    public function cancel(Request $request, LeaveRequest $leaveRequest)
    {
        $member = $request->user()->member;

        if (!$member || $leaveRequest->member_id !== $member->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $stillActive = $leaveRequest->status === 'pending'
            || ($leaveRequest->status === 'approved' && $leaveRequest->end_date->toDateString() >= now()->toDateString());

        if (!$stillActive) {
            return response()->json(['message' => "A {$leaveRequest->status} request can't be cancelled."], 422);
        }

        $leaveRequest->update(['status' => 'cancelled']);

        return response()->json($leaveRequest->fresh());
    }

    /**
     * Everyone who should hear about a leave request from this member:
     * all admins, plus the supervisors of the member's branch when the
     * requester is a plain member. The requester is never included.
     */
    private function approversFor(Member $member, User $requester): Collection
    {
        $admins = User::whereIn('role', ['admin', 'super_admin'])
            ->where('id', '!=', $requester->id)
            ->get();

        $supervisors = collect();
        if ($requester->role === 'member' && $member->branch_id) {
            $supervisors = User::where('role', 'supervisor')
                ->whereHas('member', fn ($q) => $q->where('branch_id', $member->branch_id))
                ->get();
        }

        return $admins->merge($supervisors)->unique('id')->values();
    }

    private function describe(LeaveRequest $leave): string
    {
        $start = $leave->start_date->toDateString();
        $end = $leave->end_date->toDateString();
        $kind = $leave->type === 'half_day' ? 'half-day leave' : 'leave';

        return $start === $end ? "$kind on $start" : "$kind from $start to $end";
    }
}