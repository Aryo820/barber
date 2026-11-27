<?php

use App\Http\Controllers\AdminQueueController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\PublicCatalogController;
use App\Http\Controllers\QueueController;
use Illuminate\Http\JsonResponse;
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

Route::get('/services', [PublicCatalogController::class, 'services']);
Route::get('/barbers', [PublicCatalogController::class, 'barbers']);
Route::get('/slots', [PublicCatalogController::class, 'slots']);
Route::get('/queue', [QueueController::class, 'index']);
Route::post('/bookings', [BookingController::class, 'store']);
Route::post('/queue/join', [QueueController::class, 'join']);

Route::prefix('admin')->middleware('supabase.admin')->group(function (): void {
    Route::get('/me', [AdminQueueController::class, 'me']);
    Route::get('/queue', [AdminQueueController::class, 'queue']);
    Route::patch('/appointments/{appointment}/status', [AdminQueueController::class, 'updateAppointmentStatus']);
    Route::patch('/barbers/{barber}/status', [AdminQueueController::class, 'updateBarberStatus']);
});
