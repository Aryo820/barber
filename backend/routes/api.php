<?php

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/health', function (): JsonResponse {
    try {
        DB::connection()->getPdo();

        return response()->json([
            'status' => 'ok',
            'service' => 'barberflow-api',
            'database' => 'connected',
        ]);
    } catch (\Throwable) {
        return response()->json([
            'status' => 'error',
            'service' => 'barberflow-api',
            'database' => 'disconnected',
        ], 503);
    }
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
