<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QueueLog extends Model
{
    protected $fillable = [
        'appointment_id',
        'action_type',
        'timestamp',
        'actor_id',
    ];

    protected $casts = [
        'timestamp' => 'datetime',
    ];

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
