<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// PIC directory lives ON the users table (owner decision 2026-10-13): emails
// used as action-improvement PICs become directory users (can_login = false —
// flip the toggle in User Management when someone needs access; API token
// users are unaffected, they never pass the panel login gate). manager_id is
// the org tree that drives overdue escalation. can_login defaults TRUE so
// every existing user keeps logging in exactly as before.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_login')->default(true)->after('is_service_account');
            $table->foreignId('manager_id')->nullable()->after('can_login')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('action_improvements', function (Blueprint $table) {
            $table->unsignedTinyInteger('escalation_level')->default(0)->after('reminders_sent');
            $table->timestamp('last_escalated_at')->nullable()->after('escalation_level');
        });
    }

    public function down(): void
    {
        Schema::table('action_improvements', function (Blueprint $table) {
            $table->dropColumn(['escalation_level', 'last_escalated_at']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_id');
            $table->dropColumn('can_login');
        });
    }
};
