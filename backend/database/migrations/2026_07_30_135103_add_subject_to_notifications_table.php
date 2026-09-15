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
        // Additive, nullable columns — every existing notification row (no
        // subject) stays valid. Lets a notification deep-link to the page
        // it's about (see NotificationResource::link) and lets scheduled
        // commands (subscription-expiring reminders, overdue projects) avoid
        // re-notifying the same user about the same record twice.
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('subject_type')->nullable()->after('message');
            $table->unsignedBigInteger('subject_id')->nullable()->after('subject_type');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['subject_type', 'subject_id']);
            $table->dropColumn(['subject_type', 'subject_id']);
        });
    }
};
