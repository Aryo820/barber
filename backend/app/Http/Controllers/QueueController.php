<?php

namespace App\Http\Controllers;

use App\Services\QueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QueueController extends Controller
{
    public function index(QueueService $queueService): JsonResponse
    {
        return response()->json($queueService->todayQueue(false));
    }

    public function join(Request $request, QueueService $queueService): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone_number' => ['required', 'string', 'max:30'],
            'service_id' => ['required', 'integer'],
            'barber_id' => ['nullable', 'integer'],
        ]);

        $appointment = $queueService->joinWalkIn($validated);

        return response()->json([
            'data' => $queueService->formatAppointment($appointment, false),
        ], 201);
    }
}
