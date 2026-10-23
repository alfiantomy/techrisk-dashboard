<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maps synced Notion sub-pages (of the configured watch target) to the Issue
 * records they created, so the scheduled sync never imports the same page
 * twice. Keyed on the Notion page ID — titles get edited, IDs don't.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notion_synced_pages', function (Blueprint $table) {
            $table->id();
            $table->string('page_id')->unique();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notion_synced_pages');
    }
};
