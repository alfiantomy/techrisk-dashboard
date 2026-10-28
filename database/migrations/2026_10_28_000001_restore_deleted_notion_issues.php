<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restore semantics for the Notion sync (owner 2026-10-28): deleting a
 * Notion-synced Issue from the dashboard is temporary — the next sync
 * re-imports it. incident_id goes nullable + nullOnDelete (was
 * cascadeOnDelete): a deleted Issue leaves a dangling row whose page_id
 * marks the page as "previously synced, needs re-import". DB-level
 * set-null survives every deletion path (Eloquent delete, Filament bulk
 * query delete, raw SQL) — an observer on incident deleting would miss
 * bulk/query deletes. The recovery itself queries Notion by page ID, so
 * no extra column is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notion_synced_pages', function (Blueprint $table) {
            $table->dropForeign(['incident_id']);
        });

        Schema::table('notion_synced_pages', function (Blueprint $table) {
            $table->foreignId('incident_id')->nullable()->change();
            $table->foreign('incident_id')->references('id')->on('incidents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('notion_synced_pages', function (Blueprint $table) {
            $table->dropForeign(['incident_id']);

            // Dangling rows cannot survive the original cascade — prune them
            // before restoring the NOT NULL column.
            DB::table('notion_synced_pages')->whereNull('incident_id')->delete();

            $table->foreignId('incident_id')->nullable(false)->change();
        });

        Schema::table('notion_synced_pages', function (Blueprint $table) {
            $table->foreign('incident_id')->references('id')->on('incidents')->cascadeOnDelete();
        });
    }
};
