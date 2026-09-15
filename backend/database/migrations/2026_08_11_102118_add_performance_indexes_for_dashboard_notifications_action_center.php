<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Targeted indexes for the columns the Dashboard/Notifications/Action
     * Center queries actually filter or sort by. `user_id`/`project_id`
     * foreign key columns already have an implicit index from their FK
     * constraint — only the columns that didn't are added here.
     */
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            // Unread-count / unread-list queries (WHERE user_id = ? AND
            // read_at IS NULL) and the "latest N for this user" query
            // (WHERE user_id = ? ORDER BY created_at DESC).
            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->index('status');
        });

        Schema::table('donations', function (Blueprint $table) {
            $table->index('status');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->index('status');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->index('status');
        });

        Schema::table('project_phase_requests', function (Blueprint $table) {
            $table->index('status');
        });

        Schema::table('project_deletion_requests', function (Blueprint $table) {
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'read_at']);
            $table->dropIndex(['user_id', 'created_at']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });

        Schema::table('donations', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });

        Schema::table('project_phase_requests', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });

        Schema::table('project_deletion_requests', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });
    }
};
