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
            // Only meaningful once status = rejected. 'invoice' lets the leader
            // replace the invoice and resubmit (rejected -> pending). 'details'
            // permanently locks the expense — the leader must create a new one.
            $table->enum('rejection_type', ['invoice', 'details'])
                ->nullable()
                ->after('rejection_reason');
        });

        // Any expense rejected before this column existed is safest treated as
        // a "details" rejection — that preserves its current fully-locked
        // behavior instead of silently making it resubmittable.
        DB::table('expenses')->where('status', 'rejected')->update(['rejection_type' => 'details']);
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('rejection_type');
        });
    }
};
