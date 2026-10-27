<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cancel reason (owner 2026-10-26): mandatory in the shared form when status
// flips to 'cancelled' — same mechanism as result_note/done. Nullable here:
// enforcement is form-level, not observer-level (precedent parity).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('action_improvements', function (Blueprint $table) {
            $table->text('cancellation_reason')->nullable()->after('result_note');
        });
    }

    public function down(): void
    {
        Schema::table('action_improvements', function (Blueprint $table) {
            $table->dropColumn('cancellation_reason');
        });
    }
};
