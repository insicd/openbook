<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Preferenze personali e impostazioni di privacy dell'account locale.
     */
    public function up(): void
    {
        Schema::create('user_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->unique();
            $table->string('locale', 10)->default('it');
            $table->string('timezone', 64)->default('UTC');
            $table->boolean('manually_approves_followers')->default(false);
            $table->enum('default_post_visibility', ['public', 'unlisted', 'followers', 'direct'])->default('public');
            $table->boolean('discoverable')->default(true);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_settings');
    }
};
