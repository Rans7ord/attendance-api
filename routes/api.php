<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AttendanceController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MembersController;
use App\Http\Controllers\BranchesController;
use App\Http\Controllers\BranchScheduleController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\MemberRegistrationController;
use App\Http\Controllers\InviteController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\DayOffController;
use App\Http\Controllers\PinLoginController;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::post('/login-with-pin', [PinLoginController::class, 'login']);

Route::middleware('throttle:10,1')->group(function () {
    Route::post('/register-company', [CompanyController::class, 'register']);
    Route::post('/register-member', [MemberRegistrationController::class, 'register']);
    Route::post('/accept-invite', [MemberRegistrationController::class, 'acceptInvite']);
    Route::get('/join/{code}/branches', [CompanyController::class, 'branchesForCode']);
    Route::get('/invites/{token}/registration', [CompanyController::class, 'inviteRegistrationDetails']);
});

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('/clock-in', [AttendanceController::class, 'clockIn']);
    Route::post('/clock-out', [AttendanceController::class, 'clockOut']);
    Route::get('/attendance', [AttendanceController::class, 'history']);
    Route::get('/attendance/status', [AttendanceController::class, 'status']);
    Route::get('/attendance/calendar', [AttendanceController::class, 'myCalendar']);

    Route::get('/leave', [LeaveController::class, 'mine']);
    Route::post('/leave', [LeaveController::class, 'store']);
    Route::post('/leave/{leaveRequest}/cancel', [LeaveController::class, 'cancel']);

    Route::get('/days-off/mine', [DayOffController::class, 'mine']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
});

Route::middleware(['auth:sanctum', 'active', 'staff'])->group(function () {
    Route::get('/members', [MembersController::class, 'index']);
    Route::get('/members/{member}', [MembersController::class, 'show']);
    Route::get('/members/{member}/attendance', [AttendanceController::class, 'memberHistory']);
    Route::get('/members/{member}/calendar', [AttendanceController::class, 'memberCalendar']);
    Route::get('/branches', [BranchesController::class, 'index']);
    Route::get('/branches/{branch}/schedule', [BranchScheduleController::class, 'show']);
    Route::get('/branches/{branch}/shifts', [ShiftController::class, 'index']);
    Route::get('/attendance/today', [AttendanceController::class, 'today']);

    Route::get('/leave/review', [LeaveController::class, 'review']);
    Route::post('/leave/{leaveRequest}/approve', [LeaveController::class, 'approve']);
    Route::post('/leave/{leaveRequest}/reject', [LeaveController::class, 'reject']);
});

Route::middleware(['auth:sanctum', 'active', 'admin'])->group(function () {
    Route::post('/members', [MembersController::class, 'store']);
    Route::post('/members/bulk-import', [MembersController::class, 'bulkImport']);
    Route::put('/members/{member}', [MembersController::class, 'update']);

    Route::post('/branches', [BranchesController::class, 'store']);
    Route::put('/branches/{branch}', [BranchesController::class, 'update']);
    Route::put('/branches/{branch}/schedule', [BranchScheduleController::class, 'update']);

    Route::post('/branches/{branch}/shifts', [ShiftController::class, 'store']);
    Route::put('/branches/{branch}/shifts/{shift}', [ShiftController::class, 'update']);
    Route::delete('/branches/{branch}/shifts/{shift}', [ShiftController::class, 'destroy']);

    Route::get('/company', [CompanyController::class, 'show']);
    Route::put('/company/settings', [CompanyController::class, 'updateSettings']);
    Route::post('/company/regenerate-join-code', [CompanyController::class, 'regenerateJoinCode']);

    Route::get('/invites', [InviteController::class, 'index']);
    Route::post('/invites', [InviteController::class, 'store']);
    Route::delete('/invites/{invite}', [InviteController::class, 'destroy']);

    Route::put('/members/{member}/role', [MembersController::class, 'updateRole']);
    Route::post('/members/{member}/deactivate', [MembersController::class, 'deactivate']);
    Route::post('/members/{member}/reactivate', [MembersController::class, 'reactivate']);
    Route::delete('/members/{member}', [MembersController::class, 'destroy']);
});

Route::middleware(['auth:sanctum', 'active', 'manager'])->group(function () {
    Route::get('/reports/summary', [ReportsController::class, 'summary']);
    Route::get('/reports/export', [ReportsController::class, 'exportCsv']);
    Route::get('/reports/members/{member}', [ReportsController::class, 'memberDetail']);

    // Optional holidays / short breaks / one-off closures. Managers manage
    // these too (it is their scheduling job), unlike branches and settings.
    Route::get('/days-off', [DayOffController::class, 'index']);
    Route::post('/days-off', [DayOffController::class, 'store']);
    Route::put('/days-off/{dayOff}', [DayOffController::class, 'update']);
    Route::delete('/days-off/{dayOff}', [DayOffController::class, 'destroy']);
});