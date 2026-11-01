<?php

use App\Http\Controllers\Api\Admin\UserUsageController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\FilingRuleController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::patch('/me', [AuthController::class, 'update']);

    Route::get('/dashboard', [DashboardController::class, 'show']);

    Route::get('/profiles', [ProfileController::class, 'index']);
    Route::post('/profiles', [ProfileController::class, 'store']);
    Route::patch('/profiles/{profile}', [ProfileController::class, 'update']);
    Route::post('/profiles/{profile}/activate', [ProfileController::class, 'activate']);
    Route::delete('/profiles/{profile}', [ProfileController::class, 'destroy']);

    Route::get('/categories', [CategoryController::class, 'index']);
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::patch('/categories/{category}', [CategoryController::class, 'update']);
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

    Route::get('/documents', [DocumentController::class, 'index']);
    Route::get('/documents/date-facets', [DocumentController::class, 'dateFacets']);
    Route::get('/documents/export', [DocumentController::class, 'export']);
    Route::post('/documents', [DocumentController::class, 'store']);
    Route::post('/documents/bulk', [DocumentController::class, 'storeBulk']);
    Route::get('/documents/{document}', [DocumentController::class, 'show']);
    Route::patch('/documents/{document}', [DocumentController::class, 'update']);
    Route::post('/documents/{document}/confirm', [DocumentController::class, 'confirm']);
    Route::post('/documents/{document}/retry', [DocumentController::class, 'retry']);
    Route::post('/documents/{document}/replace', [DocumentController::class, 'replace']);
    Route::get('/documents/{document}/download', [DocumentController::class, 'download']);
    Route::post('/documents/{document}/move', [DocumentController::class, 'move']);
    Route::post('/documents/{document}/reminders/{reminder}/dismiss', [DocumentController::class, 'dismissReminder']);
    Route::delete('/documents/{document}', [DocumentController::class, 'destroy']);

    Route::get('/filing-rules', [FilingRuleController::class, 'index']);
    Route::post('/filing-rules/{filingRule}/approve', [FilingRuleController::class, 'approve']);
    Route::post('/filing-rules/{filingRule}/disable', [FilingRuleController::class, 'disable']);

    Route::prefix('admin')->middleware(EnsureUserIsAdmin::class)->group(function () {
        Route::get('/users', [UserUsageController::class, 'index']);
        Route::get('/users/{user}', [UserUsageController::class, 'show']);
        Route::patch('/users/{user}', [UserUsageController::class, 'update']);
    });
});
