<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->enum('phase', ['planning', 'preparation', 'in_progress', 'finishing', 'completed'])
                ->default('planning')
                ->after('status');
        });

        // A project whose administrative status is already completed should read
        // as phase-completed too, rather than defaulting to "planning".
        DB::table('projects')->where('status', 'completed')->update(['phase' => 'completed']);
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('phase');
        });
    }
};
