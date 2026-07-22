<?php
namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Http\Request;

class BranchesController extends Controller
{
    public function index()
    {
        return Branch::all();
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'address' => 'nullable|string',
            'gps_lat' => 'required|numeric',
            'gps_lng' => 'required|numeric',
            'geofence_radius_m' => 'nullable|integer|min:10',
        ]);

        $branch = Branch::create($request->only([
            'name', 'address', 'gps_lat', 'gps_lng', 'geofence_radius_m',
        ]));

        return response()->json($branch, 201);
    }

    public function update(Request $request, Branch $branch)
    {
        $request->validate([
            'name' => 'sometimes|string',
            'address' => 'nullable|string',
            'gps_lat' => 'sometimes|numeric',
            'gps_lng' => 'sometimes|numeric',
            'geofence_radius_m' => 'sometimes|integer|min:10',
        ]);

        $branch->update($request->only([
            'name', 'address', 'gps_lat', 'gps_lng', 'geofence_radius_m',
        ]));

        return response()->json($branch);
    }
}
