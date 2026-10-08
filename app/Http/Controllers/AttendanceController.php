<?php
namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\AttendanceAttempt;
use App\Models\DayOff;
use App\Models\LeaveRequest;
use App\Models\Member;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    /** Approved full-day leave covering this date? (Half-day leave still allows clocking in.) */
    private function onFullDayLeave(int $memberId, string $date): bool
    {
        return LeaveRequest::where('member_id', $memberId)
            ->where('status', 'approved')
            ->where('type', 'full_day')
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->exists();
    }

    /** date (Y-m-d) => true for every day in the range covered by approved leave. */
    private function leaveDatesFor(int $memberId, Carbon $from, Carbon $to): array
    {
        $leaves = LeaveRequest::where('member_id', $memberId)
            ->where('status', 'approved')
            ->where('start_date', '<=', $to->toDateString())
            ->where('end_date', '>=', $from->toDateString())
            ->get();

        $dates = [];
        foreach ($leaves as $leave) {
            $start = Carbon::parse(max($leave->start_date->toDateString(), $from->toDateString()));
            $end = Carbon::parse(min($leave->end_date->toDateString(), $to->toDateString()));
            for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                $dates[$d->toDateString()] = true;
            }
        }

        return $dates;
    }

    private function noMemberResponse()
    {
        return response()->json([
            'message' => 'Your account has no member profile yet. Ask an admin to set one up.',
        ], 422);
    }

    public function clockIn(Request $request)
    {
        $request->validate(['gps_lat' => 'required|numeric', 'gps_lng' => 'required|numeric']);

        $member = $request->user()->member;
        if (!$member) {
            return $this->noMemberResponse();
        }

        $open = Attendance::where('member_id', $member->id)->whereNull('clock_out')->latest()->first();
        if ($open) {
            return response()->json([
                'message' => 'You are already clocked in since ' . $open->clock_in->format('H:i') . '. Clock out first.',
                'open_since' => $open->clock_in,
            ], 422);
        }

        $branch = $member->branch;

        // Without a branch there is no geofence or schedule to check, which
        // would let the person clock in from anywhere at any time.
        if (!$branch) {
            $this->logAttempt($member, 'clock_in', 'rejected', 'no_branch', null, $request->gps_lat, $request->gps_lng);
            return response()->json([
                'message' => 'You are not assigned to a branch yet. Ask an admin to assign you one before clocking in.',
            ], 422);
        }

        // A holiday / break / closure set for this person, or their own
        // approved full-day leave, means there is nothing to clock in to.
        $today = now()->toDateString();

        if ($dayOff = DayOff::forMemberOn($member, $today)) {
            $this->logAttempt($member, 'clock_in', 'rejected', 'day_off', null, $request->gps_lat, $request->gps_lng);
            return response()->json([
                'message' => "Today is a day off ({$dayOff->title}), so clock-in is closed.",
            ], 422);
        }

        if ($this->onFullDayLeave($member->id, $today)) {
            $this->logAttempt($member, 'clock_in', 'rejected', 'on_leave', null, $request->gps_lat, $request->gps_lng);
            return response()->json([
                'message' => 'You are on approved leave today, so you cannot clock in. If that is wrong, speak to your manager.',
            ], 422);
        }

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
        if (!$member) {
            return $this->noMemberResponse();
        }
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
        if (!$member) {
            return $this->noMemberResponse();
        }

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

        if (!$member) {
            return response()->json([]);
        }

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
        $todayStart = now()->startOfDay();

        $dayOffs = DayOff::loadFor($todayStart, $todayStart);
        $onLeaveIds = array_flip(
            LeaveRequest::whereIn('member_id', $members->pluck('id'))
                ->where('status', 'approved')
                ->where('start_date', '<=', $today)
                ->where('end_date', '>=', $today)
                ->pluck('member_id')
                ->all()
        );

        $results = $members->map(function ($member) use ($dayName, $today, $todayStart, $dayOffs, $onLeaveIds) {
            $branch = $member->branch;
            $scheduleRow = $branch?->scheduleDays->firstWhere('day', $dayName);
            $shift = ($branch?->use_shifts && $member->shift_id) ? $member->shift : null;

            $attendance = Attendance::where('member_id', $member->id)
                ->whereDate('clock_in', $today)
                ->latest()
                ->first();

            $offTitle = DayOff::expand($dayOffs, $member, $todayStart, $todayStart)[$today] ?? null;

            $status = $this->dayStatus(
                $scheduleRow, $attendance, $today, true, false, $shift,
                $offTitle !== null, isset($onLeaveIds[$member->id])
            );

            return [
                'member_id' => $member->id,
                'first_name' => $member->first_name,
                'last_name' => $member->last_name,
                'photo_path' => $member->photo_path,
                'branch_id' => $member->branch_id,
                'branch_name' => $branch?->name,
                'shift_name' => $shift?->name,
                'status' => $status,
                'note' => $status === 'day_off' ? $offTitle : null,
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
        if (!$member) {
            return $this->noMemberResponse();
        }
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

        $offDates = DayOff::expand(DayOff::loadFor($start, $end), $member, $start, $end);
        $leaveDates = $this->leaveDatesFor($member->id, $start, $end);

        $joinedDate = $member->created_at->toDateString();
        $today = now()->toDateString();
        $days = [];

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $dateString = $date->toDateString();

            if ($dateString < $joinedDate) {
                $days[] = ['date' => $dateString, 'status' => 'not_joined', 'note' => null, 'clock_in' => null, 'clock_out' => null];
                continue;
            }

            $dayName = strtolower($date->format('l'));
            $scheduleRow = $scheduleRows->get($dayName);
            $attendance = $attendanceByDate->get($dateString);

            $isToday = $dateString === $today;
            $isFutureDay = $dateString > $today;

            $status = $this->dayStatus(
                $scheduleRow, $attendance, $dateString, $isToday, $isFutureDay, $shift,
                isset($offDates[$dateString]), isset($leaveDates[$dateString])
            );

            $days[] = [
                'date' => $dateString,
                'status' => $status,
                'note' => $status === 'day_off' ? ($offDates[$dateString] ?? null) : null,
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

    /**
     * Order matters: a clock-in record always wins, a closed weekday beats
     * everything else, then a day off, then approved leave, and only then
     * does a working day with nothing on it become absent.
     */
    private function dayStatus($scheduleRow, ?Attendance $attendance, string $dateString, bool $isToday, bool $isFutureDay, $shift = null, bool $isDayOff = false, bool $onLeave = false): string
    {
        if (!$scheduleRow) {
            if (!$attendance) {
                if ($isDayOff) {
                    return 'day_off';
                }
                if ($onLeave) {
                    return 'leave';
                }
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

        if ($isDayOff) {
            return 'day_off';
        }

        if ($onLeave) {
            return 'leave';
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