<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// PROJ-014 lifecycle columns: priority (optional, default low — owner rule
// 2026-10-14) and closeout tracking (flipping to done stamps completed_at +
// completed_by in ActionImprovementObserver; result_note is free text).
// 'cancelled' needs no column — status is a plain string values change.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('action_improvements', function (Blueprint $table) {
            $table->string('priority')->default('low')->after('reminder_frequency');
            $table->timestamp('completed_at')->nullable()->after('reminders_sent');
            $table->foreignId('completed_by')->nullable()->after('completed_at')
                ->constrained('users')->nullOnDelete();
            $table->text('result_note')->nullable()->after('completed_by');
        });
    }

    public function down(): void
    {
        Schema::table('action_improvements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('completed_by');
            $table->dropColumn(['priority', 'completed_at', 'result_note']);
        });
    }
};
