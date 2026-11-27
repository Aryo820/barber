<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Customer;
use App\Models\QueueLog;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class QueueService
{
    private const ACTIVE_STATUSES = ['pending', 'confirmed'];

    private const ALLOWED_TRANSITIONS = [
        'pending' => ['confirmed', 'cancelled', 'no_show'],
        'confirmed' => ['served', 'cancelled', 'no_show'],
        'served' => [],
        'cancelled' => [],
        'no_show' => [],
    ];

    public function __construct(
        private readonly PhoneNumberNormalizer $phoneNormalizer = new PhoneNumberNormalizer(),
    ) {}

    public function generateDailySlotTimes(): array
    {
        $tz = $this->timezone();
        $openTime = (string) config('barberflow.open_time', '09:00');
        $lastSlot = (string) config('barberflow.last_slot', '20:30');
        $interval = (int) config('barberflow.slot_interval_minutes', 30);

        $cursor = Carbon::createFromFormat('!H:i', $openTime, $tz);
        $end = Carbon::createFromFormat('!H:i', $lastSlot, $tz);

        $slots = [];
        while ($cursor->lessThanOrEqualTo($end)) {
            $slots[] = $cursor->format('H:i');
            $cursor->addMinutes($interval);
        }

        return $slots;
    }

    public function slotsFor(Barber $barber, string $dateYmd, ?Carbon $now = null): array
    {
        $tz = $this->timezone();
        $nowWib = $this->nowWib($now, $tz);
        $dateWib = $this->parseBookableDate($dateYmd, $nowWib, $tz);
        [$dayStartUtc, $nextDayStartUtc] = $this->wibDayRangeUtc($dateWib);

        $bookedSlots = [];
        $bookedTimes = Appointment::query()
            ->where('barber_id', $barber->id)
            ->where('type', 'online')
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->where('scheduled_time', '>=', $dayStartUtc)
            ->where('scheduled_time', '<', $nextDayStartUtc)
            ->pluck('scheduled_time');

        foreach ($bookedTimes as $scheduledTime) {
            if ($scheduledTime !== null) {
                $bookedSlots[Carbon::parse($scheduledTime, 'UTC')->timezone($tz)->format('H:i')] = true;
            }
        }

        $result = [];
        foreach ($this->generateDailySlotTimes() as $slot) {
            $slotWib = Carbon::createFromFormat('!Y-m-d H:i', "{$dateYmd} {$slot}", $tz);

            if ($slotWib->lessThanOrEqualTo($nowWib)) {
                $available = false;
                $reason = 'past';
            } elseif (isset($bookedSlots[$slot])) {
                $available = false;
                $reason = 'booked';
            } else {
                $available = true;
                $reason = null;
            }

            $result[] = [
                'slot' => $slot,
                'scheduled_time' => $slotWib->toIso8601String(),
                'available' => $available,
                'reason' => $reason,
            ];
        }

        return $result;
    }

    public function createOnlineBooking(array $data, ?Carbon $now = null): Appointment
    {
        $tz = $this->timezone();
        $nowWib = $this->nowWib($now, $tz);
        $nowUtc = $nowWib->copy()->utc();

        $normalizedPhone = $this->requireValidPhone((string) ($data['phone_number'] ?? ''));
        $barber = $this->requireActiveBarber($data['barber_id'] ?? null);
        $service = $this->requireActiveService($data['service_id'] ?? null);

        $dateYmd = (string) ($data['date'] ?? '');
        $this->parseBookableDate($dateYmd, $nowWib, $tz);

        $slot = (string) ($data['slot'] ?? '');
        if (! in_array($slot, $this->generateDailySlotTimes(), true)) {
            throw ValidationException::withMessages([
                'slot' => ['Slot waktu tidak valid.'],
            ]);
        }

        $scheduledWib = Carbon::createFromFormat('!Y-m-d H:i', "{$dateYmd} {$slot}", $tz);
        if (! $scheduledWib->greaterThan($nowWib)) {
            throw ValidationException::withMessages([
                'slot' => ['Slot waktu sudah berlalu.'],
            ]);
        }

        $scheduledUtc = $scheduledWib->copy()->utc();

        $appointment = DB::transaction(function () use (
            $data,
            $normalizedPhone,
            $barber,
            $service,
            $scheduledUtc,
            $nowUtc,
            $now
        ): Appointment {
            $customer = $this->upsertCustomer((string) ($data['name'] ?? ''), $normalizedPhone);

            $conflictExists = Appointment::query()
                ->where('type', 'online')
                ->where('barber_id', $barber->id)
                ->where('scheduled_time', $scheduledUtc)
                ->whereIn('status', self::ACTIVE_STATUSES)
                ->exists();

            if ($conflictExists) {
                throw new ConflictHttpException('Slot waktu tersebut sudah dipesan untuk barber ini.');
            }

            $appointment = new Appointment([
                'customer_id' => $customer->id,
                'barber_id' => $barber->id,
                'service_id' => $service->id,
                'service_type' => $service->name,
                'scheduled_time' => $scheduledUtc,
                'queue_position' => null,
                'status' => 'pending',
                'type' => 'online',
            ]);

            if ($now !== null) {
                $appointment->created_at = $nowUtc;
                $appointment->updated_at = $nowUtc;
            }

            try {
                $appointment->save();
            } catch (QueryException $e) {
                if ($this->isUniqueConstraintViolation($e)) {
                    throw new ConflictHttpException('Slot waktu tersebut sudah dipesan untuk barber ini.', $e);
                }

                throw $e;
            }

            QueueLog::create([
                'appointment_id' => $appointment->id,
                'action_type' => 'join',
                'timestamp' => $nowUtc,
                'actor_id' => null,
            ]);

            return $appointment;
        });

        return $appointment->load(['customer', 'barber', 'service']);
    }

    public function joinWalkIn(array $data, ?Carbon $now = null): Appointment
    {
        $tz = $this->timezone();
        $nowWib = $this->nowWib($now, $tz);
        $nowUtc = $nowWib->copy()->utc();
        [$dayStartUtc, $nextDayStartUtc] = $this->wibDayRangeUtc($nowWib);

        $normalizedPhone = $this->requireValidPhone((string) ($data['phone_number'] ?? ''));
        $service = $this->requireActiveService($data['service_id'] ?? null);

        $barberId = $data['barber_id'] ?? null;
        if ($barberId !== null) {
            $barberId = $this->requireActiveBarber($barberId)->id;
        }

        $appointment = DB::transaction(function () use (
            $data,
            $normalizedPhone,
            $service,
            $barberId,
            $dayStartUtc,
            $nextDayStartUtc,
            $nowUtc,
            $now
        ): Appointment {
            $customer = $this->upsertCustomer((string) ($data['name'] ?? ''), $normalizedPhone);

            $activeWalkIn = Appointment::query()
                ->where('customer_id', $customer->id)
                ->where('type', 'walk_in')
                ->whereIn('status', self::ACTIVE_STATUSES)
                ->where('created_at', '>=', $dayStartUtc)
                ->where('created_at', '<', $nextDayStartUtc)
                ->first();

            if ($activeWalkIn !== null) {
                throw new ConflictHttpException(
                    "Anda sudah terdaftar dalam antrean hari ini dengan nomor A-{$activeWalkIn->queue_position}."
                );
            }

            $nextPosition = (int) Appointment::query()
                ->where('type', 'walk_in')
                ->where('created_at', '>=', $dayStartUtc)
                ->where('created_at', '<', $nextDayStartUtc)
                ->max('queue_position') + 1;

            $appointment = new Appointment([
                'customer_id' => $customer->id,
                'barber_id' => $barberId,
                'service_id' => $service->id,
                'service_type' => $service->name,
                'scheduled_time' => null,
                'queue_position' => $nextPosition,
                'status' => 'pending',
                'type' => 'walk_in',
            ]);

            if ($now !== null) {
                $appointment->created_at = $nowUtc;
                $appointment->updated_at = $nowUtc;
            }

            $appointment->save();

            QueueLog::create([
                'appointment_id' => $appointment->id,
                'action_type' => 'join',
                'timestamp' => $nowUtc,
                'actor_id' => null,
            ]);

            return $appointment;
        });

        return $appointment->load(['customer', 'barber', 'service']);
    }

    public function formatAppointment(Appointment $appointment, bool $includeCustomerPhone = false): array
    {
        $appointment->loadMissing(['customer', 'barber', 'service']);

        $tz = $this->timezone();
        $scheduledWib = $appointment->scheduled_time?->copy()->timezone($tz);
        $createdWib = $appointment->created_at?->copy()->timezone($tz);

        $customer = [
            'id' => (int) $appointment->customer->id,
            'name' => (string) $appointment->customer->name,
        ];

        if ($includeCustomerPhone) {
            $customer['phone_number'] = (string) $appointment->customer->phone_number;
        }

        return [
            'id' => (int) $appointment->id,
            'ticket_code' => $appointment->type === 'online'
                ? "BK-{$appointment->id}"
                : "A-{$appointment->queue_position}",
            'type' => (string) $appointment->type,
            'status' => (string) $appointment->status,
            'queue_position' => $appointment->queue_position !== null ? (int) $appointment->queue_position : null,
            'scheduled_time' => $scheduledWib?->toIso8601String(),
            'date' => $scheduledWib?->format('Y-m-d'),
            'slot' => $scheduledWib?->format('H:i'),
            'service_type' => (string) $appointment->service_type,
            'service' => $appointment->service ? [
                'id' => (int) $appointment->service->id,
                'name' => (string) $appointment->service->name,
                'price' => (int) $appointment->service->price,
            ] : null,
            'barber' => $appointment->barber ? [
                'id' => (int) $appointment->barber->id,
                'name' => (string) $appointment->barber->name,
                'current_status' => (string) $appointment->barber->current_status,
            ] : null,
            'customer' => $customer,
            'created_at' => $createdWib?->toIso8601String() ?? '',
        ];
    }

    public function todayQueue(bool $includeCustomerPhone = false, ?Carbon $now = null): array
    {
        $tz = $this->timezone();
        $nowWib = $this->nowWib($now, $tz);
        [$dayStartUtc, $nextDayStartUtc] = $this->wibDayRangeUtc($nowWib);

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

        $activeAppointments = Appointment::query()
            ->with(['customer', 'barber', 'service'])
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->where(function ($query) use ($dayStartUtc, $nextDayStartUtc): void {
                $this->applyTodayWibWindow($query, $dayStartUtc, $nextDayStartUtc);
            })
            ->get();

        $priorityWindow = (int) config('barberflow.priority_window_minutes', 30);
        $priorityCutoffUtc = $nowWib->copy()->addMinutes($priorityWindow)->utc();

        $tierOf = static function (Appointment $appointment) use ($priorityCutoffUtc): int {
            if ($appointment->status === 'confirmed') {
                return 1;
            }

            if ($appointment->type === 'online') {
                return ($appointment->scheduled_time !== null && $appointment->scheduled_time->lessThanOrEqualTo($priorityCutoffUtc))
                    ? 2
                    : 4;
            }

            return 3;
        };

        $sorted = $activeAppointments
            ->sort(static function (Appointment $a, Appointment $b) use ($tierOf): int {
                $tierA = $tierOf($a);
                $tierB = $tierOf($b);

                if ($tierA !== $tierB) {
                    return $tierA <=> $tierB;
                }

                $primaryCmp = match ($tierA) {
                    1 => ($a->updated_at?->getPreciseTimestamp(6) ?? 0) <=> ($b->updated_at?->getPreciseTimestamp(6) ?? 0),
                    2, 4 => ($a->scheduled_time?->getPreciseTimestamp(6) ?? 0) <=> ($b->scheduled_time?->getPreciseTimestamp(6) ?? 0),
                    3 => ($a->queue_position ?? PHP_INT_MAX) <=> ($b->queue_position ?? PHP_INT_MAX),
                };

                return $primaryCmp !== 0 ? $primaryCmp : ($a->id <=> $b->id);
            })
            ->values();

        $servedToday = Appointment::query()
            ->where('status', 'served')
            ->where(function ($query) use ($dayStartUtc, $nextDayStartUtc): void {
                $this->applyTodayWibWindow($query, $dayStartUtc, $nextDayStartUtc);
            })
            ->count();

        $pendingCount = $sorted->where('status', 'pending')->count();
        $confirmedCount = $sorted->where('status', 'confirmed')->count();

        return [
            'date' => $nowWib->format('Y-m-d'),
            'timezone' => $tz,
            'barbers' => $barbers,
            'queue' => $sorted
                ->map(fn (Appointment $appointment): array => $this->formatAppointment($appointment, $includeCustomerPhone))
                ->all(),
            'summary' => [
                'pending' => $pendingCount,
                'confirmed' => $confirmedCount,
                'served_today' => $servedToday,
                'active_total' => $sorted->count(),
            ],
        ];
    }

    public function transitionStatus(
        Appointment $appointment,
        string $newStatus,
        string $actorId,
        ?int $barberId = null,
    ): Appointment {
        $locked = DB::transaction(function () use ($appointment, $newStatus, $actorId, $barberId): Appointment {
            $locked = Appointment::query()
                ->whereKey($appointment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $allowed = self::ALLOWED_TRANSITIONS[$locked->status] ?? [];
            if (! in_array($newStatus, $allowed, true)) {
                throw ValidationException::withMessages([
                    'status' => ["Transisi status dari '{$locked->status}' ke '{$newStatus}' tidak diizinkan."],
                ]);
            }

            if ($barberId !== null) {
                $this->requireActiveBarber($barberId);
            }

            if ($barberId !== null && $newStatus === 'confirmed') {
                $locked->barber_id = $barberId;
            }

            $locked->status = $newStatus;

            try {
                $locked->save();
            } catch (QueryException $e) {
                if ($this->isUniqueConstraintViolation($e)) {
                    throw new ConflictHttpException('Slot waktu tersebut sudah dipesan untuk barber ini.', $e);
                }

                throw $e;
            }

            QueueLog::create([
                'appointment_id' => $locked->id,
                'action_type' => in_array($newStatus, ['confirmed', 'served'], true) ? 'serve' : 'leave',
                'timestamp' => Carbon::now('UTC'),
                'actor_id' => $actorId,
            ]);

            return $locked;
        });

        return $locked->refresh()->load(['customer', 'barber', 'service']);
    }

    private function timezone(): string
    {
        return (string) config('barberflow.timezone', 'Asia/Jakarta');
    }

    private function nowWib(?Carbon $now, string $tz): Carbon
    {
        return ($now ? $now->copy() : Carbon::now($tz))->timezone($tz);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function wibDayRangeUtc(Carbon $wibDate): array
    {
        $dayStartUtc = $wibDate->copy()->startOfDay()->utc();
        $nextDayStartUtc = $wibDate->copy()->addDay()->startOfDay()->utc();

        return [$dayStartUtc, $nextDayStartUtc];
    }

    private function parseBookableDate(string $dateYmd, Carbon $nowWib, string $tz): Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateYmd) !== 1) {
            throw ValidationException::withMessages([
                'date' => ['Format tanggal harus YYYY-MM-DD.'],
            ]);
        }

        $dateWib = Carbon::createFromFormat('!Y-m-d', $dateYmd, $tz);
        if ($dateWib === false || $dateWib->format('Y-m-d') !== $dateYmd) {
            throw ValidationException::withMessages([
                'date' => ['Tanggal tidak valid.'],
            ]);
        }

        $todayWib = $nowWib->copy()->startOfDay();
        $maxDays = (int) config('barberflow.booking_max_days_ahead', 7);
        $maxDateWib = $todayWib->copy()->addDays($maxDays);

        if ($dateWib->lt($todayWib) || $dateWib->gt($maxDateWib)) {
            throw ValidationException::withMessages([
                'date' => ["Tanggal booking harus antara hari ini sampai H+{$maxDays}."],
            ]);
        }

        return $dateWib;
    }

    private function requireValidPhone(string $rawPhone): string
    {
        $normalized = $this->phoneNormalizer->normalize($rawPhone);
        if ($normalized === null) {
            throw ValidationException::withMessages([
                'phone_number' => ['Nomor WhatsApp tidak valid.'],
            ]);
        }

        return $normalized;
    }

    private function requireActiveBarber(mixed $barberId): Barber
    {
        $barber = $barberId !== null ? Barber::find($barberId) : null;
        if ($barber === null || ! $barber->is_active) {
            throw ValidationException::withMessages([
                'barber_id' => ['Barber tidak tersedia.'],
            ]);
        }

        return $barber;
    }

    private function requireActiveService(mixed $serviceId): Service
    {
        $service = $serviceId !== null ? Service::find($serviceId) : null;
        if ($service === null || ! $service->is_active) {
            throw ValidationException::withMessages([
                'service_id' => ['Layanan tidak tersedia.'],
            ]);
        }

        return $service;
    }

    private function upsertCustomer(string $name, string $normalizedPhone): Customer
    {
        $cleanName = trim($name);
        $customer = Customer::where('phone_number', $normalizedPhone)->first();

        if ($customer !== null) {
            $customer->name = $cleanName;
            if (empty($customer->wa_id)) {
                $customer->wa_id = $this->phoneNormalizer->toWaId($normalizedPhone);
            }
            $customer->save();

            return $customer;
        }

        return Customer::create([
            'name' => $cleanName,
            'phone_number' => $normalizedPhone,
            'wa_id' => $this->phoneNormalizer->toWaId($normalizedPhone),
        ]);
    }

    private function applyTodayWibWindow($query, Carbon $dayStartUtc, Carbon $nextDayStartUtc): void
    {
        $query->where(function ($walkIn) use ($dayStartUtc, $nextDayStartUtc): void {
            $walkIn->where('type', 'walk_in')
                ->where('created_at', '>=', $dayStartUtc)
                ->where('created_at', '<', $nextDayStartUtc);
        })->orWhere(function ($online) use ($dayStartUtc, $nextDayStartUtc): void {
            $online->where('type', 'online')
                ->where('scheduled_time', '>=', $dayStartUtc)
                ->where('scheduled_time', '<', $nextDayStartUtc);
        });
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        if (in_array($sqlState, ['23000', '23505'], true)) {
            return true;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'unique constraint')
            || str_contains($message, 'duplicate key')
            || str_contains($message, 'appointments_active_online_barber_slot_unique');
    }
}
