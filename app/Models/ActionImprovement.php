<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

class ActionImprovement extends Model implements Auditable
{
    use \App\Models\Concerns\SerializesDatesInAppTimezone;
    use HasFactory;
    use \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'incident_id',
        'title',
        'detail',
        'due_date',
        'pic_email',
        'reminder',
        'reminder_frequency',
        'status',
        'last_reminded_at',
        'reminders_sent',
    ];

    protected $casts = [
        'pic_email' => 'array',
        // MySQL DATE columns don't need this, but SQLite tests store raw strings —
        // cast keeps ->format() working on both drivers (notification mail depends on it).
        'due_date' => 'date',
        'last_reminded_at' => 'datetime',
        'reminders_sent' => 'integer',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * Record that a reminder email actually went out (mail-eligible
     * recipients > 0). The per-user in-app halves already persist as
     * `notifications` rows — this tracks the combined email itself, and
     * last_reminded_at anchors the reminder_frequency throttle.
     */
    public function recordReminderSent(int $mailedCount): void
    {
        if ($mailedCount <= 0) {
            return;
        }

        $this->forceFill([
            'last_reminded_at' => now(),
            'reminders_sent' => ($this->reminders_sent ?? 0) + 1,
        ])->saveQuietly();
    }
}
