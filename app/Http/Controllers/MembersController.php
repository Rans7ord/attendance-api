<?php
namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\Shift;
use Illuminate\Http\Request;

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

        $member->update($request->only([
            'first_name', 'last_name', 'phone', 'email', 'position', 'branch_id', 'shift_id', 'status',
        ]));

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
}