<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_project', function (Blueprint $table) {
            // leader, treasurer, secretary, member
            $table->string('committee_role')->default('member');
        });
    }

    public function down(): void
    {
        Schema::table('member_project', function (Blueprint $table) {
            $table->dropColumn('committee_role');
        });
    }
};
