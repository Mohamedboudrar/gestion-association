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
        // Deliberately no unique/foreign constraints beyond the primary key —
        // singleton-ness is enforced in application code (SettingsService /
        // AssociationSetting::current()), never by the schema itself.
        Schema::create('association_settings', function (Blueprint $table) {
            $table->id();
            $table->string('association_name');
            $table->string('association_logo')->nullable();
            $table->string('address');
            $table->string('phone');
            $table->string('email');
            $table->string('website')->nullable();
            $table->decimal('annual_subscription_amount', 10, 2)->default(0);
            $table->string('currency', 10)->default('MAD');
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('association_settings');
    }
};
