<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Barber;
use App\Services\QueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminQueueController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $admin = (array) $request->attributes->get('supabase_admin', []);

        return response()->json([
            'data' => [
                'id' => (string) ($admin['id'] ?? ''),
                'email' => $admin['email'] ?? null,
                'role' => 'admin',
            ],
        ]);
    }

    public function queue(QueueService $queueService): JsonResponse
    {
        return response()->json($queueService->todayQueue(true));
    }

    public function updateAppointmentStatus(
        Request $request,
        Appointment $appointment,
        QueueService $queueService,
    ): JsonResponse {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:confirmed,served,cancelled,no_show'],
            'barber_id' => ['nullable', 'integer'],
        ]);

        $admin = (array) $request->attributes->get('supabase_admin', []);
        $actorId = (string) (($admin['email'] ?? null) ?: ($admin['id'] ?? 'admin'));
        $barberId = isset($validated['barber_id']) ? (int) $validated['barber_id'] : null;

        $updated = $queueService->transitionStatus(
            $appointment,
            (string) $validated['status'],
            $actorId,
            $barberId,
        );

        return response()->json([
            'data' => $queueService->formatAppointment($updated, true),
        ]);
    }

    public function updateBarberStatus(Request $request, Barber $barber): JsonResponse
    {
        $validated = $request->validate([
            'current_status' => ['required', 'string', 'in:idle,busy,break'],
        ]);

        $barber->current_status = (string) $validated['current_status'];
        $barber->save();

        return response()->json([
            'data' => [
                'id' => (int) $barber->id,
                'name' => (string) $barber->name,
                'current_status' => (string) $barber->current_status,
            ],
        ]);
    }
}
