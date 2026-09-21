<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * I post importati da RSS/Atom tenevano l'URL (o il guid) dell'articolo
     * in "posts.uri". Un Announce federato puntava quindi a una pagina HTML,
     * e le altre istanze mostravano solo un link. La colonna "source_uri"
     * resta per la deduplicazione; l'id ActivityPub torna a "/posts/{id}".
     */
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('source_uri', 768)->nullable()->unique()->after('uri');
        });

        $feedActorIds = DB::table('actors')->where('type', 'feed')->pluck('id');

        if ($feedActorIds->isEmpty()) {
            return;
        }

        foreach (DB::table('posts')->whereIn('actor_id', $feedActorIds)->whereNotNull('uri')->cursor() as $post) {
            DB::table('posts')->where('id', $post->id)->update([
                'source_uri' => $post->uri,
                'uri' => null,
            ]);
        }
    }

    public function down(): void
    {
        $feedActorIds = DB::table('actors')->where('type', 'feed')->pluck('id');

        if ($feedActorIds->isNotEmpty()) {
            foreach (DB::table('posts')->whereIn('actor_id', $feedActorIds)->whereNotNull('source_uri')->cursor() as $post) {
                DB::table('posts')->where('id', $post->id)->update([
                    'uri' => mb_substr((string) $post->source_uri, 0, 255),
                ]);
            }
        }

        Schema::table('posts', function (Blueprint $table) {
            $table->dropUnique(['source_uri']);
            $table->dropColumn('source_uri');
        });
    }
};
