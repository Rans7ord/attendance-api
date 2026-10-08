<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\DayOff;
use App\Models\Member;
use App\Models\User;
use App\Models\UserNotification;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Optional days off: holidays, short breaks and one-off closures that admins
 * and managers set for everyone, one branch, or specific people. On those
 * days clock-in is blocked and nobody is counted absent.
 */
class DayOffController extends Controller
{
    private const MAX_SPAN_DAYS = 60;

    /** Admin/manager: upcoming and current days off (?include_past=1 for all). */
    public function index(Request $request)
    {
        $query = DayOff::with(['branch:id,name', 'members:id,first_name,last_name'])->orderBy('start_date');

        if (!$request->boolean('include_past')) {
            $query->where('end_date', '>=', now()->toDateString());
        }

        return $query->get();
    }

    /** Anyone: the upcoming days off that apply to me. */
    public function mine(Request $request)
    {
        $member = $request->user()->member;

        if (!$member) {
            return response()->json([]);
        }

        $today = now()->startOfDay();
        $mine = DayOff::loadFor($today, $today->copy()->addYear())
            ->filter(fn (DayOff $off) => $off->appliesTo($member))
            ->sortBy('start_date')
            ->map(fn (DayOff $off) => [
                'id' => $off->id,
                'title' => $off->title,
                'kind' => $off->kind,
                'start_date' => $off->start_date->toDateString(),
                'end_date' => $off->end_date->toDateString(),
            ])
            ->values();

        return response()->json($mine);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $off = DB::transaction(function () use ($data, $request) {
            $off = DayOff::create([
                'title' => $data['title'],
                'kind' => $data['kind'],
                'scope' => $data['scope'],
                'branch_id' => $data['scope'] === 'branch' ? $data['branch_id'] : null,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'created_by' => $request->user()->id,
            ]);

            if ($data['scope'] === 'members') {
                $off->members()->sync($data['member_ids']);
            }

            return $off;
        });

        $this->notifyAffected($off, $request->user());

        return response()->json($off->load(['branch:id,name', 'members:id,first_name,last_name']), 201);
    }

    public function update(Request $request, DayOff $dayOff)
    {
        $data = $this->validated($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        DB::transaction(function () use ($data, $dayOff) {
            $dayOff->update([
                'title' => $data['title'],
                'kind' => $data['kind'],
                'scope' => $data['scope'],
                'branch_id' => $data['scope'] === 'branch' ? $data['branch_id'] : null,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
            ]);

            $dayOff->members()->sync($data['scope'] === 'members' ? $data['member_ids'] : []);
        });

        return response()->json($dayOff->fresh()->load(['branch:id,name', 'members:id,first_name,last_name']));
    }

    /** Needs confirm=true — removing a day off turns those days back into normal working days. */
    public function destroy(Request $request, DayOff $dayOff)
    {
        $request->validate(['confirm' => 'accepted'], [
            'confirm.accepted' => 'Confirmation required: send confirm=true to delete this day off.',
        ]);

        $dayOff->delete();

        return response()->json(['message' => 'Day off deleted.']);
    }

    /** Validates the body; returns the cleaned data or an error response. */
    private function validated(Request $request): array|JsonResponse
    {
        $request->validate([
            'title' => 'required|string|max:120',
            'kind' => 'nullable|in:holiday,break,closure',
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'scope' => 'required|in:company,branch,members',
            'branch_id' => 'required_if:scope,branch|nullable|integer',
            'member_ids' => 'required_if:scope,members|nullable|array|min:1',
            'member_ids.*' => 'integer',
        ]);

        $start = $request->start_date;
        $end = $request->input('end_date', $start);

        if (Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1 > self::MAX_SPAN_DAYS) {
            return response()->json(['message' => 'A day off can span at most ' . self::MAX_SPAN_DAYS . ' days. Add another one for a longer break.'], 422);
        }

        $memberIds = [];
        if ($request->scope === 'branch' && !Branch::find($request->branch_id)) {
            return response()->json(['message' => 'That branch does not belong to this company.'], 422);
        }

        if ($request->scope === 'members') {
            $memberIds = array_values(array_unique(array_map('intval', $request->member_ids)));
            if (Member::whereIn('id', $memberIds)->count() !== count($memberIds)) {
                return response()->json(['message' => 'One or more of those members were not found.'], 422);
            }
        }

        return [
            'title' => $request->title,
            'kind' => $request->input('kind', 'holiday'),
            'scope' => $request->scope,
            'branch_id' => $request->branch_id,
            'member_ids' => $memberIds,
            'start_date' => $start,
            'end_date' => $end,
        ];
    }

    /** Tells everyone the new day off applies to (except whoever created it). */
    private function notifyAffected(DayOff $off, User $creator): void
    {
        $members = Member::whereNotNull('user_id')
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'inactive'));

        if ($off->scope === 'branch') {
            $members->where('branch_id', $off->branch_id);
        } elseif ($off->scope === 'members') {
            $members->whereIn('id', $off->members()->pluck('members.id'));
        }

        $start = $off->start_date->toDateString();
        $end = $off->end_date->toDateString();
        $when = $start === $end ? "on $start" : "from $start to $end";

        $users = User::whereIn('id', $members->pluck('user_id'))->where('id', '!=', $creator->id)->get();

        foreach ($users as $user) {
            UserNotification::send(
                $user,
                'day_off',
                'Day off: ' . $off->title,
                "You are off $when ({$off->title}). Clock-in is closed on those days.",
                ['day_off_id' => $off->id]
            );
        }
    }
}