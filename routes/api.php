<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AttendanceController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MembersController;
use App\Http\Controllers\BranchesController;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/clock-in', [AttendanceController::class, 'clockIn']);
    Route::post('/clock-out', [AttendanceController::class, 'clockOut']);
    Route::get('/attendance', [AttendanceController::class, 'history']);
});


Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/members', [MembersController::class, 'index']);
    Route::post('/members', [MembersController::class, 'store']);
    Route::get('/members/{member}', [MembersController::class, 'show']);
    Route::put('/members/{member}', [MembersController::class, 'update']);
    
    
    Route::get('/branches', [BranchesController::class, 'index']);
    Route::post('/branches', [BranchesController::class, 'store']);
    Route::put('/branches/{branch}', [BranchesController::class, 'update']);
});