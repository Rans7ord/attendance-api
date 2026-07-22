<?php
namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\AttendanceAttempt;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function clockIn(Request $request)
    {
        $request->validate(['gps_lat' => 'required|numeric', 'gps_lng' => 'required|numeric']);

        $member = $request->user()->member;
        $check = $this->checkGeofence($member, $request->gps_lat, $request->gps_lng);

        $this->logAttempt($member, 'clock_in', $check, $request->gps_lat, $request->gps_lng);

        if (!$check['allowed']) {
            return response()->json([
                'message' => 'You are outside the allowed clock-in area.',
                'distance_m' => $check['distance'],
            ], 422);
        }

        $attendance = Attendance::create([
            'member_id' => $member->id,
            'clock_in' => now(),
            'gps_lat_in' => $request->gps_lat,
            'gps_lng_in' => $request->gps_lng,
            'status' => 'present',
        ]);

        return response()->json($attendance);
    }

    public function clockOut(Request $request)
    {
        $request->validate(['gps_lat' => 'required|numeric', 'gps_lng' => 'required|numeric']);

        $member = $request->user()->member;
        $check = $this->checkGeofence($member, $request->gps_lat, $request->gps_lng);

        $this->logAttempt($member, 'clock_out', $check, $request->gps_lat, $request->gps_lng);

        if (!$check['allowed']) {
            return response()->json([
                'message' => 'You are outside the allowed clock-out area.',
                'distance_m' => $check['distance'],
            ], 422);
        }

        $attendance = Attendance::where('member_id', $member->id)
            ->whereNull('clock_out')
            ->latest()
            ->firstOrFail();

        $attendance->update([
            'clock_out' => now(),
            'gps_lat_out' => $request->gps_lat,
            'gps_lng_out' => $request->gps_lng,
        ]);

        return response()->json($attendance);
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

    private function logAttempt($member, string $type, array $check, $lat, $lng): void
    {
        AttendanceAttempt::create([
            'member_id' => $member->id,
            'type' => $type,
            'result' => $check['allowed'] ? 'success' : 'rejected',
            'gps_lat' => $lat,
            'gps_lng' => $lng,
            'distance_m' => $check['distance'],
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