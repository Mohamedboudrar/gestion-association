<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_project', function (Blueprint $table) {
            $table->string('responsibility')->nullable()->after('role');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete()->after('responsibility');
            $table->timestamp('assigned_at')->nullable()->after('assigned_by');
        });
    }

    public function down(): void
    {
        Schema::table('member_project', function (Blueprint $table) {
            $table->dropColumn(['responsibility', 'assigned_by', 'assigned_at']);
        });
    }
};
