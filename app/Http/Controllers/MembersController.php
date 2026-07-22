<?php
namespace App\Http\Controllers;

use App\Models\Member;
use Illuminate\Http\Request;

class MembersController extends Controller
{
    public function index()
    {
        return Member::with('branch')->get();
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
        ]);

        $member = Member::create($request->only([
            'first_name', 'last_name', 'phone', 'email', 'position', 'branch_id',
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
            'status' => 'sometimes|in:active,inactive',
        ]);

        $member->update($request->only([
            'first_name', 'last_name', 'phone', 'email', 'position', 'branch_id', 'status',
        ]));

        return response()->json($member);
    }

    public function show(Member $member)
    {
        return $member->load('branch');
    }
}
