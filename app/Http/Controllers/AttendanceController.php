<?php
namespace App\Http\Controllers;

use App\Models\Attendance;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function clockIn(Request $request)
    {
        $request->validate(['gps_lat' => 'required|numeric', 'gps_lng' => 'required|numeric']);

        $member = $request->user()->member;
        $branch = $member->branch;

        if ($branch) {
            $distance = $this->distanceInMeters(
                $request->gps_lat, $request->gps_lng,
                $branch->gps_lat, $branch->gps_lng
            );

            if ($distance > $branch->geofence_radius_m) {
                return response()->json([
                    'message' => 'You are outside the allowed clock-in area.',
                    'distance_m' => round($distance),
                ], 422);
            }
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

        $attendance = Attendance::where('member_id', $request->user()->member->id)
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

    private function distanceInMeters($lat1, $lng1, $lat2, $lng2)
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}