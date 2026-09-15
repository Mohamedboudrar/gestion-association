<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The raw ENUM-widening DDL below is MySQL-only syntax — production
        // runs MySQL (see CLAUDE.md), but the test suite runs sqlite (see
        // phpunit.xml), which has no live 'planned' rows to preserve on a
        // freshly migrated database, so it takes the portable Schema::change()
        // path instead. Same end state either way.
        if (DB::connection()->getDriverName() !== 'mysql') {
            DB::table('projects')->where('status', 'planned')->update(['status' => 'draft']);

            Schema::table('projects', function (Blueprint $table) {
                $table->enum('status', ['draft', 'committee_ready', 'funding_ready', 'active', 'completed', 'cancelled'])
                    ->default('draft')
                    ->change();
            });

            return;
        }

        // Widen the enum first (keeping 'planned') so existing rows stay valid
        // while we backfill, then narrow it once the data is migrated.
        DB::statement("ALTER TABLE projects MODIFY status ENUM('planned', 'draft', 'committee_ready', 'funding_ready', 'active', 'completed', 'cancelled') DEFAULT 'draft'");

        DB::table('projects')->where('status', 'planned')->update(['status' => 'draft']);

        DB::statement("ALTER TABLE projects MODIFY status ENUM('draft', 'committee_ready', 'funding_ready', 'active', 'completed', 'cancelled') DEFAULT 'draft'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            DB::table('projects')
                ->whereIn('status', ['draft', 'committee_ready', 'funding_ready'])
                ->update(['status' => 'planned']);

            Schema::table('projects', function (Blueprint $table) {
                $table->enum('status', ['planned', 'active', 'completed', 'cancelled'])
                    ->default('planned')
                    ->change();
            });

            return;
        }

        DB::statement("ALTER TABLE projects MODIFY status ENUM('draft', 'committee_ready', 'funding_ready', 'active', 'completed', 'cancelled', 'planned') DEFAULT 'planned'");

        DB::table('projects')
            ->whereIn('status', ['draft', 'committee_ready', 'funding_ready'])
            ->update(['status' => 'planned']);

        DB::statement("ALTER TABLE projects MODIFY status ENUM('planned', 'active', 'completed', 'cancelled') DEFAULT 'planned'");
    }
};
