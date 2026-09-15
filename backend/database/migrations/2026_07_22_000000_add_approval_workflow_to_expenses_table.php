<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->enum('status', ['draft', 'pending', 'approved', 'rejected', 'paid'])
                ->default('draft')
                ->after('created_by');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete()->after('status');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete()->after('approved_at');
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->text('rejection_reason')->nullable()->after('rejected_at');
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete()->after('rejection_reason');
            $table->timestamp('paid_at')->nullable()->after('paid_by');
        });

        // Expenses recorded before this workflow existed were already
        // legitimate, decided expenses — treat them as approved rather than
        // surfacing them as new drafts/pending items to review.
        DB::table('expenses')->update(['status' => 'approved']);
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn([
                'status',
                'approved_by',
                'approved_at',
                'rejected_by',
                'rejected_at',
                'rejection_reason',
                'paid_by',
                'paid_at',
            ]);
        });
    }
};
