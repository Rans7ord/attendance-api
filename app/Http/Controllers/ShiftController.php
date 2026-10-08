<?php
namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Shift;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    /**
     * Admin or supervisor (own branch only) — lists this branch's shift
     * templates, plus whether shift-based scheduling is turned on at all.
     */
    public function index(Request $request, Branch $branch)
    {
        if ($request->user()->isSupervisor() && $branch->id !== $request->user()->branchId()) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json([
            'branch_id' => $branch->id,
            'use_shifts' => $branch->use_shifts,
            'shifts' => $branch->shifts()->orderBy('start_time')->get(),
        ]);
    }

    public function store(Request $request, Branch $branch)
    {
        $request->validate([
            'name' => 'required|string|max:50',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
            'grace_minutes' => 'nullable|integer|min:0|max:180',
        ]);

        $shift = $branch->shifts()->create([
            'name' => $request->name,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'grace_minutes' => $request->grace_minutes ?? 15,
        ]);

        return response()->json($shift, 201);
    }

    public function update(Request $request, Branch $branch, Shift $shift)
    {
        if ($shift->branch_id !== $branch->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $request->validate([
            'name' => 'sometimes|string|max:50',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
            'grace_minutes' => 'nullable|integer|min:0|max:180',
        ]);

        $shift->update($request->only(['name', 'start_time', 'end_time', 'grace_minutes']));

        return response()->json($shift);
    }

    /**
     * Deleting a shift unassigns (never deletes) any members on it —
     * members.shift_id is nullOnDelete, so this can't leave a dangling
     * reference.
     */
    public function destroy(Request $request, Branch $branch, Shift $shift)
    {
        if ($shift->branch_id !== $branch->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $shift->delete();

        return response()->json(['message' => 'Shift deleted.']);
    }
}