<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PolicyRequestController;
use App\Http\Controllers\MockInsurerController;

// 1. Policy Requests CRUD & Workflow
Route::get('/policy-requests', [PolicyRequestController::class, 'index']);
Route::post('/policy-requests', [PolicyRequestController::class, 'store']);
Route::get('/policy-requests/{id}', [PolicyRequestController::class, 'show']);
Route::post('/policy-requests/{id}/submit', [PolicyRequestController::class, 'submit']);
Route::post('/policy-requests/{id}/reconcile', [PolicyRequestController::class, 'reconcile']);

// 2. Mock Insurer Callback
Route::post('/mock-insurer/callback', [MockInsurerController::class, 'handleCallback']);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
