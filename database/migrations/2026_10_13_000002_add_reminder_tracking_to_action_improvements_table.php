<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// PROJ-011 follow-up (owner 2026-10-13): every reminder email sent for an
// action improvement is recorded on the row — last_reminded_at doubles as
// the throttle anchor for reminder_frequency. Prod run is an owner-only
// guardrail (php artisan migrate --force).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('action_improvements', function (Blueprint $table) {
            $table->timestamp('last_reminded_at')->nullable()->after('reminder_frequency');
            $table->unsignedSmallInteger('reminders_sent')->default(0)->after('last_reminded_at');
        });
    }

    public function down(): void
    {
        Schema::table('action_improvements', function (Blueprint $table) {
            $table->dropColumn(['last_reminded_at', 'reminders_sent']);
        });
    }
};
