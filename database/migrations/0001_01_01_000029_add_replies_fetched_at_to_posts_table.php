<?php

use App\Federation\Replies\RemoteRepliesFetcher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vedi {@see RemoteRepliesFetcher}: quando si apre un post remoto in
     * cache, viene interrogata la collection "replies" della Note originale
     * per recuperare commenti di terzi che non sono mai arrivati in inbox.
     * La colonna registra l'ultimo tentativo (riuscito o meno).
     */
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->timestamp('replies_fetched_at')->nullable()->after('edited_at');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('replies_fetched_at');
        });
    }
};
