<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('action_improvements', function (Blueprint $table) {
            // Owner 2026-10-14: null due_date = TBD — "some action improvement
            // have TBD date or dont know". No existing row is touched.
            $table->date('due_date')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('action_improvements', function (Blueprint $table) {
            $table->date('due_date')->nullable(false)->change();
        });
    }
};
