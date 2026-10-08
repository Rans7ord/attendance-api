<?php
namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\AttendanceAttempt;
use App\Models\Member;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function clockIn(Request $request)
    {
        $request->validate(['gps_lat' => 'required|numeric', 'gps_lng' => 'required|numeric']);

        $member = $request->user()->member;

        $open = Attendance::where('member_id', $member->id)->whereNull('clock_out')->latest()->first();
        if ($open) {
            return response()->json([
                'message' => 'You are already clocked in since ' . $open->clock_in->format('H:i') . '. Clock out first.',
                'open_since' => $open->clock_in,
            ], 422);
        }

        $branch = $member->branch;

        $geofence = $this->checkGeofence($member, $request->gps_lat, $request->gps_lng);

        if (!$geofence['allowed']) {
            $this->logAttempt($member, 'clock_in', 'rejected', 'outside_geofence', $geofence['distance'], $request->gps_lat, $request->gps_lng);
            return response()->json([
                'message' => 'You are outside the allowed clock-in area.',
                'distance_m' => $geofence['distance'],
            ], 422);
        }

        $schedule = $this->checkSchedule($branch, $member);

        if (!$schedule['allowed']) {
            $this->logAttempt($member, 'clock_in', 'rejected', $schedule['reason'], $geofence['distance'], $request->gps_lat, $request->gps_lng);
            return response()->json(['message' => $schedule['message']], 422);
        }

        $this->logAttempt($member, 'clock_in', 'success', null, $geofence['distance'], $request->gps_lat, $request->gps_lng);

        $attendance = Attendance::create([
            'member_id' => $member->id,
            'clock_in' => now(),
            'gps_lat_in' => $request->gps_lat,
            'gps_lng_in' => $request->gps_lng,
            'status' => $schedule['status'], // 'present' or 'late'
        ]);

        return response()->json($attendance);
    }

    public function clockOut(Request $request)
    {
        $request->validate(['gps_lat' => 'required|numeric', 'gps_lng' => 'required|numeric']);

        $member = $request->user()->member;
        $check = $this->checkGeofence($member, $request->gps_lat, $request->gps_lng);

        $this->logAttempt(
            $member,
            'clock_out',
            $check['allowed'] ? 'success' : 'rejected',
            $check['allowed'] ? null : 'outside_geofence',
            $check['distance'],
            $request->gps_lat,
            $request->gps_lng
        );

        if (!$check['allowed']) {
            return response()->json([
                'message' => 'You are outside the allowed clock-out area.',
                'distance_m' => $check['distance'],
            ], 422);
        }

        $attendance = Attendance::where('member_id', $member->id)
            ->whereNull('clock_out')
            ->latest()
            ->first();

        if (!$attendance) {
            return response()->json(['message' => 'You are not currently clocked in.'], 404);
        }

        $clockOutAt = now();

        // Half-day: clocked in late AND is leaving well before the
        // expected end of the day. Only a "late" record can become a
        // half_day; an on-time record stays "present" however early the
        // person leaves.
        $status = $attendance->status;
        if ($status === 'late' && $this->leftEarly($member, $attendance, $clockOutAt)) {
            $status = 'half_day';
        }

        $attendance->update([
            'clock_out' => $clockOutAt,
            'gps_lat_out' => $request->gps_lat,
            'gps_lng_out' => $request->gps_lng,
            'status' => $status,
        ]);

        return response()->json($attendance);
    }

    public function status(Request $request)
    {
        $member = $request->user()->member;

        $open = Attendance::where('member_id', $member->id)->whereNull('clock_out')->latest()->first();

        if (!$open) {
            return response()->json(['clocked_in' => false]);
        }

        return response()->json([
            'clocked_in' => true,
            'attendance_id' => $open->id,
            'clock_in' => $open->clock_in,
            'is_overdue' => !$open->clock_in->isToday(),
        ]);
    }

    public function history(Request $request)
    {
        $member = $request->user()->member;

        $records = Attendance::where('member_id', $member->id)
            ->orderByDesc('clock_in')
            ->limit(50)
            ->get();

        return response()->json($records);
    }

    public function today(Request $request)
    {
        $user = $request->user();
        $query = Member::with(['branch.scheduleDays', 'shift']);

        if ($user->isSupervisor()) {
            $query->where('branch_id', $user->branchId());
        }

        $members = $query->get();
        $dayName = strtolower(now()->format('l'));
        $today = now()->toDateString();

        $results = $members->map(function ($member) use ($dayName, $today) {
            $branch = $member->branch;
            $scheduleRow = $branch?->scheduleDays->firstWhere('day', $dayName);
            $shift = ($branch?->use_shifts && $member->shift_id) ? $member->shift : null;

            $attendance = Attendance::where('member_id', $member->id)
                ->whereDate('clock_in', $today)
                ->latest()
                ->first();

            $status = $this->dayStatus($scheduleRow, $attendance, $today, true, false, $shift);

            return [
                'member_id' => $member->id,
                'first_name' => $member->first_name,
                'last_name' => $member->last_name,
                'photo_path' => $member->photo_path,
                'branch_id' => $member->branch_id,
                'branch_name' => $branch?->name,
                'shift_name' => $shift?->name,
                'status' => $status,
                'clock_in' => $attendance?->clock_in,
                'clock_out' => $attendance?->clock_out,
            ];
        });

        return response()->json($results->values());
    }

    public function memberHistory(Request $request, Member $member)
    {
        if ($request->user()->isSupervisor() && $member->branch_id !== $request->user()->branchId()) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $records = Attendance::where('member_id', $member->id)
            ->orderByDesc('clock_in')
            ->limit(100)
            ->get();

        return response()->json($records);
    }

    public function myCalendar(Request $request)
    {
        $member = $request->user()->member;
        return $this->buildCalendar($member, $request->query('month'));
    }

    public function memberCalendar(Request $request, Member $member)
    {
        if ($request->user()->isSupervisor() && $member->branch_id !== $request->user()->branchId()) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return $this->buildCalendar($member, $request->query('month'));
    }

    private function buildCalendar(Member $member, ?string $month)
    {
        $month = $month && preg_match('/^\d{4}-\d{2}$/', $month) ? $month : now()->format('Y-m');
        $start = Carbon::parse($month . '-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $branch = $member->branch;
        $scheduleRows = $branch ? $branch->scheduleDays()->get()->keyBy('day') : collect();
        $shift = ($branch?->use_shifts && $member->shift_id) ? $member->shift : null;

        $attendanceByDate = Attendance::where('member_id', $member->id)
            ->whereBetween('clock_in', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->get()
            ->keyBy(fn ($a) => $a->clock_in->toDateString());

        $joinedDate = $member->created_at->toDateString();
        $today = now()->toDateString();
        $days = [];

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $dateString = $date->toDateString();

            if ($dateString < $joinedDate) {
                $days[] = ['date' => $dateString, 'status' => 'not_joined', 'clock_in' => null, 'clock_out' => null];
                continue;
            }

            $dayName = strtolower($date->format('l'));
            $scheduleRow = $scheduleRows->get($dayName);
            $attendance = $attendanceByDate->get($dateString);

            $isToday = $dateString === $today;
            $isFutureDay = $dateString > $today;

            $days[] = [
                'date' => $dateString,
                'status' => $this->dayStatus($scheduleRow, $attendance, $dateString, $isToday, $isFutureDay, $shift),
                'clock_in' => $attendance?->clock_in,
                'clock_out' => $attendance?->clock_out,
            ];
        }

        return response()->json([
            'member_id' => $member->id,
            'month' => $month,
            'days' => $days,
        ]);
    }

    /**
     * If a shift applies, it replaces the branch day's start_time/grace —
     * used for both live clock-in enforcement and historical/calendar
     * status computation, so the two can't drift.
     */
    private function effectiveWindow($scheduleRow, $shift): array
    {
        if ($shift) {
            return [$shift->start_time, $shift->grace_minutes];
        }
        return [$scheduleRow->start_time, $scheduleRow->grace_minutes];
    }

    /**
     * When this attendance record's day is expected to end: the member's
     * shift end_time if their branch uses shifts, otherwise that weekday's
     * expected_end_time. Null when no end time is configured, which means
     * half-day detection simply doesn't apply.
     */
    private function expectedEnd(Member $member, Attendance $attendance): ?Carbon
    {
        $branch = $member->branch;
        if (!$branch) {
            return null;
        }

        $shift = ($branch->use_shifts && $member->shift_id) ? $member->shift : null;

        if ($shift) {
            if (!$shift->end_time) {
                return null;
            }
            $startRaw = $shift->start_time;
            $endRaw = $shift->end_time;
        } else {
            $row = $branch->scheduleDays()
                ->where('day', strtolower($attendance->clock_in->format('l')))
                ->first();
            if (!$row || !$row->expected_end_time) {
                return null;
            }
            $startRaw = $row->start_time;
            $endRaw = $row->expected_end_time;
        }

        $date = $attendance->clock_in->toDateString();
        $start = Carbon::parse($date . ' ' . $startRaw);
        $end = Carbon::parse($date . ' ' . $endRaw);

        // An end time at or before the start time means the day crosses
        // midnight (e.g. a 22:00-06:00 night shift) and ends tomorrow.
        if ($end->lte($start)) {
            $end->addDay();
        }

        return $end;
    }

    private function leftEarly(Member $member, Attendance $attendance, Carbon $clockOutAt): bool
    {
        $end = $this->expectedEnd($member, $attendance);
        if (!$end) {
            return false;
        }

        $cutoff = $end->copy()->subMinutes((int) config('attendance.early_leave_minutes', 60));

        return $clockOutAt->lt($cutoff);
    }

    private function dayStatus($scheduleRow, ?Attendance $attendance, string $dateString, bool $isToday, bool $isFutureDay, $shift = null): string
    {
        if (!$scheduleRow) {
            if (!$attendance) {
                return 'unscheduled';
            }
            return $attendance->clock_out ? $attendance->status : ($isToday ? 'open' : 'incomplete');
        }

        if (!$scheduleRow->is_working) {
            return 'closed';
        }

        if ($attendance) {
            return $attendance->clock_out ? $attendance->status : ($isToday ? 'open' : 'incomplete');
        }

        if ($isFutureDay) {
            return 'upcoming';
        }

        if ($isToday) {
            [$startTimeRaw] = $this->effectiveWindow($scheduleRow, $shift);
            $startTime = Carbon::parse($dateString . ' ' . $startTimeRaw);
            return now()->gte($startTime) ? 'absent' : 'upcoming';
        }

        return 'absent';
    }

    private function checkGeofence($member, $lat, $lng): array
    {
        $branch = $member->branch;

        if (!$branch) {
            return ['allowed' => true, 'distance' => null];
        }

        $distance = $this->distanceInMeters($lat, $lng, $branch->gps_lat, $branch->gps_lng);

        return [
            'allowed' => $distance <= $branch->geofence_radius_m,
            'distance' => round($distance),
        ];
    }

    private function checkSchedule($branch, $member): array
    {
        $dayName = strtolower(now()->format('l'));
        $row = $branch?->scheduleDays()->where('day', $dayName)->first();

        if (!$row) {
            return ['allowed' => true, 'status' => 'present', 'reason' => null, 'message' => null];
        }

        if (!$row->is_working) {
            return [
                'allowed' => false,
                'status' => null,
                'reason' => 'non_working_day',
                'message' => 'Today is not a working day for your branch.',
            ];
        }

        $shift = ($branch->use_shifts && $member->shift_id) ? $member->shift : null;
        [$startTimeRaw, $graceMinutes] = $this->effectiveWindow($row, $shift);

        $startTime = Carbon::parse(now()->toDateString() . ' ' . $startTimeRaw);
        $deadline = $startTime->copy()->addMinutes($graceMinutes);
        $now = now();

        if ($now->lte($startTime)) {
            return ['allowed' => true, 'status' => 'present', 'reason' => null, 'message' => null];
        }

        if ($now->lte($deadline)) {
            return ['allowed' => true, 'status' => 'late', 'reason' => null, 'message' => null];
        }

        return [
            'allowed' => false,
            'status' => null,
            'reason' => 'after_deadline',
            'message' => 'The clock-in window for today has closed.',
        ];
    }

    private function logAttempt($member, string $type, string $result, ?string $reason, $distance, $lat, $lng): void
    {
        AttendanceAttempt::create([
            'member_id' => $member->id,
            'type' => $type,
            'result' => $result,
            'reason' => $reason,
            'gps_lat' => $lat,
            'gps_lng' => $lng,
            'distance_m' => $distance,
        ]);
    }

    private function distanceInMeters($lat1, $lng1, $lat2, $lng2)
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}