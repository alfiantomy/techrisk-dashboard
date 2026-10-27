<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Encrypted evidence for action improvements (owner 2026-10-26): photos/text
// proving a fix happened. Key table mirrors encryption_keys but is bound to
// evidence rows — the existing table's FK is hard-bound to investigation
// documents, so a parallel table keeps zero blast radius.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_improvement_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('action_improvement_id')->constrained('action_improvements')->onDelete('cascade');
            $table->string('file_path');
            $table->string('original_filename');
            $table->timestamps();
        });

        Schema::create('action_improvement_evidence_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('action_improvement_evidence_id')->constrained('action_improvement_evidence')->onDelete('cascade');
            $table->text('key');
            $table->string('salt');
            $table->string('method');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_improvement_evidence_keys');
        Schema::dropIfExists('action_improvement_evidence');
    }
};
