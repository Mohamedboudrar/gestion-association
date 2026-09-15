<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Deterministic hash (see App\Services\PasskeyService) so a login
            // attempt can be resolved to a user by a direct lookup — never the
            // plain passkey. Indexed for that lookup.
            $table->string('passkey_hash')->nullable()->unique()->after('password');
            $table->timestamp('passkey_created_at')->nullable()->after('passkey_hash');
            $table->timestamp('last_passkey_sent_at')->nullable()->after('passkey_created_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['passkey_hash', 'passkey_created_at', 'last_passkey_sent_at']);
        });
    }
};
