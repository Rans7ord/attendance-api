<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Branch;
use App\Models\DayOff;
use App\Models\LeaveRequest;
use App\Models\Member;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Attendance reports over a date range.
 *
 *   GET /reports/summary        per-branch and per-member counts
 *   GET /reports/export         the same data as a CSV download
 *   GET /reports/members/{id}   one member, with a day-by-day breakdown
 *
 * Query params (all optional): from, to (Y-m-d; default = first of this
 * month .. today), branch_id, member_id, include_inactive=1.
 * export also takes group=member (default) | branch | daily.
 *
 * How each day is counted, per member (days before the member joined and
 * days after today are skipped):
 *   - has an attendance record  -> its status (present / late / half_day);
 *                                  the first record of the day decides
 *   - branch is closed that day -> closed (not counted)
 *   - a day off applies to them -> day_off (not counted as absent)
 *   - approved leave covers it  -> leave
 *   - no schedule for that day  -> unscheduled (not counted)
 *   - working day, nothing else -> absent (today only once the clock-in
 *                                  deadline has passed)
 * Records with no clock-out on a past day are also counted as "incomplete".
 */
class ReportsController extends Controller
{
    private const MAX_RANGE_DAYS = 366;
    private const COUNT_KEYS = ['present', 'late', 'half_day', 'absent', 'leave', 'day_off', 'incomplete'];

    public function summary(Request $request)
    {
        [$from, $to, $error] = $this->resolveRange($request);
        if ($error) {
            return $error;
        }

        $rows = $this->buildRows($request, $from, $to);
        if ($rows instanceof JsonResponse) {
            return $rows;
        }

        $members = $rows->map(fn ($r) => $this->memberRow($r))->values();

        return response()->json([
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => $this->totals($members),
            'by_branch' => $this->byBranch($members),
            'by_member' => $members,
        ]);
    }

    public function memberDetail(Request $request, Member $member)
    {
        if ($request->user()->isSupervisor() && $member->branch_id !== $request->user()->branchId()) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        [$from, $to, $error] = $this->resolveRange($request);
        if ($error) {
            return $error;
        }

        $member->load(['branch.scheduleDays', 'shift']);
        $result = $this->analyseMembers(collect([$member]), $from, $to)->first();

        return response()->json([
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'member' => $this->memberRow($result),
            'days' => array_values($result['days']),
        ]);
    }

    public function exportCsv(Request $request)
    {
        [$from, $to, $error] = $this->resolveRange($request);
        if ($error) {
            return $error;
        }

        $group = $request->query('group', 'member');
        if (!in_array($group, ['member', 'branch', 'daily'], true)) {
            return response()->json(['message' => 'group must be member, branch or daily.'], 422);
        }

        $rows = $this->buildRows($request, $from, $to);
        if ($rows instanceof JsonResponse) {
            return $rows;
        }

        $members = $rows->map(fn ($r) => $this->memberRow($r))->values();
        $filename = "attendance-{$group}-{$from->toDateString()}-to-{$to->toDateString()}.csv";

        return $this->streamCsv($filename, function ($out) use ($group, $members, $rows) {
            if ($group === 'branch') {
                fputcsv($out, ['Branch', 'Members', 'Present', 'Late', 'Half day', 'Absent', 'Leave', 'Day off', 'Incomplete', 'Hours worked', 'Attendance rate %']);
                foreach ($this->byBranch($members) as $b) {
                    fputcsv($out, $this->safe([
                        $b['branch_name'], $b['member_count'], $b['present'], $b['late'], $b['half_day'],
                        $b['absent'], $b['leave'], $b['day_off'], $b['incomplete'], $b['hours_worked'], $b['attendance_rate'],
                    ]));
                }
                return;
            }

            if ($group === 'daily') {
                fputcsv($out, ['Date', 'Member', 'Branch', 'Status', 'Note', 'Clock in', 'Clock out', 'Hours', 'Incomplete']);
                foreach ($rows as $r) {
                    foreach ($r['days'] as $day) {
                        if (in_array($day['status'], ['closed', 'unscheduled', 'upcoming'], true)) {
                            continue;
                        }
                        fputcsv($out, $this->safe([
                            $day['date'], $r['name'], $r['branch_name'], $day['status'], $day['note'],
                            $day['clock_in'], $day['clock_out'], $day['hours'], $day['incomplete'] ? 'yes' : '',
                        ]));
                    }
                }
                return;
            }

            fputcsv($out, ['Member', 'Branch', 'Present', 'Late', 'Half day', 'Absent', 'Leave', 'Day off', 'Incomplete', 'Hours worked', 'Attendance rate %']);
            foreach ($members as $m) {
                fputcsv($out, $this->safe([
                    $m['name'], $m['branch_name'], $m['present'], $m['late'], $m['half_day'],
                    $m['absent'], $m['leave'], $m['day_off'], $m['incomplete'], $m['hours_worked'], $m['attendance_rate'],
                ]));
            }
        });
    }

    // ------------------------------------------------------------------
    // Building the data
    // ------------------------------------------------------------------

    /** Validates from/to. Returns [from, to, errorResponse]. */
    private function resolveRange(Request $request): array
    {
        $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
        ]);

        $today = now()->startOfDay();
        $from = $request->filled('from') ? Carbon::parse($request->from)->startOfDay() : now()->startOfMonth()->startOfDay();
        $to = $request->filled('to') ? Carbon::parse($request->to)->startOfDay() : $today->copy();

        if ($to->lt($from)) {
            return [null, null, response()->json(['message' => 'The "to" date must be on or after "from".'], 422)];
        }

        // Future days can't be absent yet, so the range stops at today.
        if ($to->gt($today)) {
            $to = $today->copy();
        }

        if ($from->gt($to)) {
            return [null, null, response()->json(['message' => 'That range is entirely in the future.'], 422)];
        }

        if ($from->diffInDays($to) + 1 > self::MAX_RANGE_DAYS) {
            return [null, null, response()->json(['message' => 'Pick a range of at most ' . self::MAX_RANGE_DAYS . ' days.'], 422)];
        }

        return [$from, $to, null];
    }

    /** Applies the branch/member/inactive filters and analyses the members. */
    private function buildRows(Request $request, Carbon $from, Carbon $to)
    {
        $user = $request->user();
        $query = Member::with(['branch.scheduleDays', 'shift'])->orderBy('first_name')->orderBy('last_name');

        // A supervisor only ever reports on their own branch.
        if ($user->isSupervisor()) {
            $query->where('branch_id', $user->branchId());
        } elseif ($request->filled('branch_id')) {
            if (!Branch::find($request->branch_id)) {
                return response()->json(['message' => 'That branch was not found.'], 422);
            }
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('member_id')) {
            $query->where('id', $request->member_id);
        }

        if (!$request->boolean('include_inactive')) {
            $query->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'inactive'));
        }

        return $this->analyseMembers($query->get(), $from, $to);
    }

    /**
     * For each member, work out the status of every day in the range and
     * the resulting counts. Two extra queries total, however many members.
     */
    private function analyseMembers(Collection $members, Carbon $from, Carbon $to): Collection
    {
        $ids = $members->pluck('id');

        $attendanceByMember = Attendance::whereIn('member_id', $ids)
            ->whereBetween('clock_in', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->orderBy('clock_in')
            ->get()
            ->groupBy('member_id');

        $leaves = LeaveRequest::whereIn('member_id', $ids)
            ->where('status', 'approved')
            ->where('start_date', '<=', $to->toDateString())
            ->where('end_date', '>=', $from->toDateString())
            ->get()
            ->groupBy('member_id');

        $dayOffs = DayOff::loadFor($from, $to);

        return $members->map(function (Member $member) use ($from, $to, $attendanceByMember, $leaves, $dayOffs) {
            $records = $attendanceByMember->get($member->id) ?? collect();

            $attendanceByDate = $records
                ->groupBy(fn ($a) => $a->clock_in->toDateString())
                ->map(fn ($day) => $day->first());

            $leaveDates = [];
            foreach ($leaves->get($member->id) ?? [] as $leave) {
                $start = Carbon::parse(max($leave->start_date->toDateString(), $from->toDateString()));
                $end = Carbon::parse(min($leave->end_date->toDateString(), $to->toDateString()));
                for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                    $leaveDates[$d->toDateString()] = true;
                }
            }

            $hours = 0.0;
            foreach ($records as $a) {
                if ($a->clock_out) {
                    $hours += $a->clock_in->diffInSeconds($a->clock_out) / 3600;
                }
            }

            $offDates = DayOff::expand($dayOffs, $member, $from, $to);

            [$days, $counts] = $this->classifyDays($member, $from, $to, $attendanceByDate, $leaveDates, $offDates);

            return [
                'member_id' => $member->id,
                'name' => trim($member->first_name . ' ' . $member->last_name),
                'branch_id' => $member->branch_id,
                'branch_name' => $member->branch?->name,
                'status' => $member->status,
                'days' => $days,
                'counts' => $counts,
                'hours_worked' => round($hours, 2),
            ];
        });
    }

    private function classifyDays(Member $member, Carbon $from, Carbon $to, Collection $attendanceByDate, array $leaveDates, array $offDates = []): array
    {
        $branch = $member->branch;
        $scheduleRows = $branch ? $branch->scheduleDays->keyBy('day') : collect();
        $shift = ($branch?->use_shifts && $member->shift_id) ? $member->shift : null;

        $joined = $member->created_at->toDateString();
        $today = now()->toDateString();

        $counts = array_fill_keys(self::COUNT_KEYS, 0);
        $days = [];

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $d = $date->toDateString();

            if ($d < $joined || $d > $today) {
                continue;
            }

            $row = $scheduleRows->get(strtolower($date->format('l')));
            $attendance = $attendanceByDate->get($d);

            $entry = [
                'date' => $d,
                'status' => null,
                'clock_in' => null,
                'clock_out' => null,
                'hours' => null,
                'incomplete' => false,
                'note' => null,
            ];

            if ($attendance) {
                $entry['status'] = $attendance->status;
                $entry['clock_in'] = $attendance->clock_in->format('H:i');
                $entry['clock_out'] = $attendance->clock_out?->format('H:i');
                $entry['hours'] = $attendance->clock_out
                    ? round($attendance->clock_in->diffInSeconds($attendance->clock_out) / 3600, 2)
                    : null;
                $entry['incomplete'] = !$attendance->clock_out && $d < $today;

                if (array_key_exists($attendance->status, $counts)) {
                    $counts[$attendance->status]++;
                }
                if ($entry['incomplete']) {
                    $counts['incomplete']++;
                }
            } elseif ($row && !$row->is_working) {
                $entry['status'] = 'closed';
            } elseif (isset($offDates[$d])) {
                $entry['status'] = 'day_off';
                $entry['note'] = $offDates[$d];
                $counts['day_off']++;
            } elseif (isset($leaveDates[$d])) {
                $entry['status'] = 'leave';
                $counts['leave']++;
            } elseif (!$row) {
                $entry['status'] = 'unscheduled';
            } elseif ($d < $today || $this->deadlinePassed($row, $shift, $d)) {
                $entry['status'] = 'absent';
                $counts['absent']++;
            } else {
                $entry['status'] = 'upcoming';
            }

            $days[$d] = $entry;
        }

        return [$days, $counts];
    }

    /** Today only: can this member still clock in? Mirrors the clock-in window. */
    private function deadlinePassed($row, $shift, string $date): bool
    {
        $startRaw = $shift ? $shift->start_time : $row->start_time;
        $grace = (int) ($shift ? $shift->grace_minutes : $row->grace_minutes);

        $deadline = Carbon::parse($date . ' ' . $startRaw)->addMinutes($grace);

        return now()->gt($deadline);
    }

    // ------------------------------------------------------------------
    // Shaping the output
    // ------------------------------------------------------------------

    private function memberRow(array $r): array
    {
        return array_merge([
            'member_id' => $r['member_id'],
            'name' => $r['name'],
            'branch_id' => $r['branch_id'],
            'branch_name' => $r['branch_name'],
            'status' => $r['status'],
        ], $r['counts'], [
            'hours_worked' => $r['hours_worked'],
            'attendance_rate' => $this->rate($r['counts']),
        ]);
    }

    /** Share of expected days (present+late+half_day+absent) that were attended. */
    private function rate(array $c): ?float
    {
        $attended = $c['present'] + $c['late'] + $c['half_day'];
        $expected = $attended + $c['absent'];

        return $expected > 0 ? round($attended / $expected * 100, 1) : null;
    }

    private function totals(Collection $members): array
    {
        $totals = array_fill_keys(self::COUNT_KEYS, 0);
        $hours = 0.0;

        foreach ($members as $m) {
            foreach (self::COUNT_KEYS as $k) {
                $totals[$k] += $m[$k];
            }
            $hours += $m['hours_worked'];
        }

        return array_merge(['member_count' => $members->count()], $totals, [
            'hours_worked' => round($hours, 2),
            'attendance_rate' => $this->rate($totals),
        ]);
    }

    private function byBranch(Collection $members): array
    {
        return $members
            ->groupBy(fn ($m) => $m['branch_id'] ?? 0)
            ->map(function (Collection $group) {
                $first = $group->first();

                return array_merge([
                    'branch_id' => $first['branch_id'],
                    'branch_name' => $first['branch_name'] ?? 'No branch',
                ], $this->totals($group));
            })
            ->sortBy('branch_name')
            ->values()
            ->all();
    }

    // ------------------------------------------------------------------
    // CSV helpers
    // ------------------------------------------------------------------

    private function streamCsv(string $filename, callable $writer): StreamedResponse
    {
        return response()->streamDownload(function () use ($writer) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads names correctly
            $writer($out);
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Stops spreadsheet apps treating a name like "=1+1" as a formula. */
    private function safe(array $cells): array
    {
        return array_map(function ($v) {
            if (is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) {
                return "'" . $v;
            }
            return $v;
        }, $cells);
    }
}