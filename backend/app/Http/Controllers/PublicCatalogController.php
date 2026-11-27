<?php

namespace App\Http\Controllers;

use App\Models\Barber;
use App\Models\Service;
use App\Services\QueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PublicCatalogController extends Controller
{
    public function services(): JsonResponse
    {
        $services = Service::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->map(static fn (Service $service): array => [
                'id' => (int) $service->id,
                'name' => (string) $service->name,
                'price' => (int) $service->price,
            ])
            ->all();

        return response()->json(['data' => $services]);
    }

    public function barbers(): JsonResponse
    {
        $barbers = Barber::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->map(static fn (Barber $barber): array => [
                'id' => (int) $barber->id,
                'name' => (string) $barber->name,
                'current_status' => (string) $barber->current_status,
            ])
            ->all();

        return response()->json(['data' => $barbers]);
    }

    public function slots(Request $request, QueueService $queueService): JsonResponse
    {
        $validated = $request->validate([
            'barber_id' => ['required', 'integer', Rule::exists('barbers', 'id')->where('is_active', true)],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $barber = Barber::query()
            ->where('is_active', true)
            ->findOrFail((int) $validated['barber_id']);

        $slots = $queueService->slotsFor($barber, (string) $validated['date']);

        return response()->json([
            'barber' => [
                'id' => (int) $barber->id,
                'name' => (string) $barber->name,
                'current_status' => (string) $barber->current_status,
            ],
            'date' => (string) $validated['date'],
            'timezone' => (string) config('barberflow.timezone', 'Asia/Jakarta'),
            'slots' => $slots,
        ]);
    }
}
