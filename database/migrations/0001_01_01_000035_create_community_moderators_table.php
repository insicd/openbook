<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_moderators', function (Blueprint $table) {
            $table->uuid('community_id');
            $table->uuid('user_id');
            $table->timestamps();

            $table->primary(['community_id', 'user_id']);
            $table->foreign('community_id')->references('id')->on('communities')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_moderators');
    }
};
