<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROJ-011 Wave 2 (VP TechOps PDR): detection-pattern fields.
 * detected_via / failed_control back the channel-mix and 3×3-matrix KPIs;
 * detection_delay_days + start_time_known kill the placeholder-time problem
 * at the source (RD-4: record "started X days before discovery" when the
 * true start is unknown instead of booking 00:00/12:00).
 * NOTE (CLAUDE.md owner-only guardrail): running this on PRODUCTION needs
 * owner approval — it is a prod DB migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->string('detected_via')->nullable();
            $table->string('failed_control')->nullable();
            $table->unsignedTinyInteger('detection_delay_days')->nullable();
            $table->boolean('start_time_known')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn(['detected_via', 'failed_control', 'detection_delay_days', 'start_time_known']);
        });
    }
};
