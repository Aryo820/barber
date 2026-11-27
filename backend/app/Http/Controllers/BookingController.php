<?php

namespace App\Http\Controllers;

use App\Services\QueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function store(Request $request, QueueService $queueService): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone_number' => ['required', 'string', 'max:30'],
            'barber_id' => ['required', 'integer'],
            'service_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'slot' => ['required', 'date_format:H:i'],
        ]);

        $appointment = $queueService->createOnlineBooking($validated);

        return response()->json([
            'data' => $queueService->formatAppointment($appointment, false),
        ], 201);
    }
}
