<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // Nullable and unconstrained-by-default in application logic —
            // every subscription that predates the Annual Dues system keeps
            // due_id = null forever and continues working exactly as before.
            // Only new subscription payments get auto-linked to a due (see
            // DuesService::getOrCreateForMemberYear via SubscriptionController).
            $table->foreignId('due_id')->nullable()->after('member_id')
                ->constrained('dues')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('due_id');
        });
    }
};
