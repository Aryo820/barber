<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Appointment extends Model
{
    protected $fillable = [
        'customer_id',
        'barber_id',
        'service_type',
        'scheduled_time',
        'queue_position',
        'status',
        'type',
    ];

    protected $casts = [
        'scheduled_time' => 'datetime',
        'queue_position' => 'integer',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function barber(): BelongsTo
    {
        return $this->belongsTo(Barber::class);
    }

    public function queueLogs(): HasMany
    {
        return $this->hasMany(QueueLog::class);
    }
}
