<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('committee_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('role')->nullable();
            $table->string('committee_role')->nullable();
            $table->string('responsibility')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->text('reason')->nullable();
            $table->enum('action', ['assigned', 'replaced', 'resigned', 'removed', 'dissolved'])->default('assigned');
            $table->timestamps();
        });

        // Backfill: every committee membership that already exists (created
        // before this history feature) gets an opening "assigned" row, so
        // pre-existing committees aren't invisible in the new history view.
        $now = now();

        DB::table('member_project')->orderBy('id')->each(function ($row) use ($now) {
            DB::table('committee_assignments')->insert([
                'project_id' => $row->project_id,
                'member_id' => $row->member_id,
                'role' => $row->role ?? null,
                'committee_role' => $row->committee_role ?? null,
                'responsibility' => $row->responsibility ?? null,
                'assigned_by' => $row->assigned_by ?? null,
                'assigned_at' => $row->assigned_at ?? $row->created_at ?? $now,
                'removed_by' => null,
                'removed_at' => null,
                'reason' => null,
                'action' => 'assigned',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('committee_assignments');
    }
};
