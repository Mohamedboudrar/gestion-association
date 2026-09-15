<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->enum('status', ['draft', 'pending', 'approved', 'rejected'])
                ->default('draft')
                ->after('recorded_by');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete()->after('status');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete()->after('approved_at');
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->text('rejection_reason')->nullable()->after('rejected_at');
            // Only meaningful once status = rejected. 'receipt' lets the leader
            // replace the receipt and resubmit (rejected -> pending). 'details'
            // permanently locks the donation — the leader must create a new one.
            $table->enum('rejection_type', ['receipt', 'details'])->nullable()->after('rejection_reason');
        });

        // Donations recorded before this workflow existed were already
        // legitimate, decided donations — treat them as approved rather than
        // surfacing them as new drafts/pending items to review.
        DB::table('donations')->update(['status' => 'approved']);
    }

    public function down(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->dropColumn([
                'status',
                'approved_by',
                'approved_at',
                'rejected_by',
                'rejected_at',
                'rejection_reason',
                'rejection_type',
            ]);
        });
    }
};
