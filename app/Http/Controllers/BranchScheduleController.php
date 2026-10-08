<?php
namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\BranchScheduleDay;
use Illuminate\Http\Request;

class BranchScheduleController extends Controller
{
    private const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    private const DEFAULT_WORKING = [
        'monday' => true, 'tuesday' => true, 'wednesday' => true, 'thursday' => true,
        'friday' => true, 'saturday' => false, 'sunday' => false,
    ];

    public function show(Request $request, Branch $branch)
    {
        if ($request->user()->isSupervisor() && $branch->id !== $request->user()->branchId()) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $rows = $branch->scheduleDays()->get()->keyBy('day');
        $configured = $rows->isNotEmpty();

        $days = [];
        foreach (self::DAYS as $day) {
            $row = $rows->get($day);
            $days[] = [
                'day' => $day,
                'is_working' => $row ? $row->is_working : self::DEFAULT_WORKING[$day],
                'start_time' => $row ? $row->start_time : '08:00:00',
                'grace_minutes' => $row ? $row->grace_minutes : 15,
                'expected_end_time' => $row ? $row->expected_end_time : null,
            ];
        }

        return response()->json(['branch_id' => $branch->id, 'configured' => $configured, 'days' => $days]);
    }

    public function update(Request $request, Branch $branch)
    {
        $request->validate([
            'days' => 'required|array|size:7',
            'days.*.day' => 'required|in:' . implode(',', self::DAYS),
            'days.*.is_working' => 'required|boolean',
            'days.*.start_time' => 'required|date_format:H:i',
            'days.*.grace_minutes' => 'nullable|integer|min:0|max:180',
            'days.*.expected_end_time' => 'nullable|date_format:H:i',
        ]);

        $seen = [];
        foreach ($request->days as $d) {
            if (in_array($d['day'], $seen, true)) {
                return response()->json(['message' => "Duplicate day: {$d['day']}"], 422);
            }
            $seen[] = $d['day'];
        }
        if (count($seen) !== 7) {
            return response()->json(['message' => 'All 7 days must be included, each exactly once.'], 422);
        }

        foreach ($request->days as $d) {
            $values = [
                'is_working' => $d['is_working'],
                'start_time' => $d['start_time'],
                'grace_minutes' => $d['grace_minutes'] ?? 15,
            ];

            // Absent key = leave the stored end time alone. Explicit null
            // = clear it. This stops an older client that doesn't know
            // about expected_end_time from wiping it on every save.
            if (array_key_exists('expected_end_time', $d)) {
                $values['expected_end_time'] = $d['expected_end_time'];
            }

            BranchScheduleDay::updateOrCreate(
                ['branch_id' => $branch->id, 'day' => $d['day']],
                $values
            );
        }

        return $this->show($request, $branch);
    }
}