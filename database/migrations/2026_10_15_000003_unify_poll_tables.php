<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    private const TABLES = [
        'remote_polls' => 'polls',
        'remote_poll_options' => 'poll_options',
        'remote_poll_votes' => 'poll_votes',
        'remote_poll_vote_choices' => 'poll_vote_choices',
    ];

    private const INDEXES = [
        'polls' => ['post_id_unique'],
        'poll_options' => ['position_unique', 'name_unique'],
        'poll_votes' => ['actor_unique', 'actor_id_index'],
        'poll_vote_choices' => ['name_unique', 'position_unique'],
    ];

    public function up(): void
    {
        $this->alterSafely(fn () => $this->renameAndExtend());
    }

    private function renameAndExtend(): void
    {
        // Rename first: all existing UUIDs, foreign keys and queued vote IDs survive.
        foreach (self::TABLES as $old => $new) {
            Schema::rename($old, $new);
        }
        $this->renameIndexes(false);

        Schema::table('polls', function (Blueprint $table): void {
            $table->unsignedBigInteger('revision')->default(0);
            $table->dateTime('results_updated_at')->nullable();
            $table->boolean('publication_pending')->default(false);
            $table->boolean('needs_delivery')->default(false);
            $table->unsignedBigInteger('distributed_revision')->default(0);
            $table->index(['needs_delivery', 'id'], 'polls_delivery_index');
            $table->index(['closed_at', 'ends_at', 'id'], 'polls_expiry_index');
        });

        Schema::table('poll_votes', function (Blueprint $table): void {
            $table->dropForeign(DB::getDriverName() === 'sqlite' ? ['actor_id'] : 'remote_poll_votes_actor_id_foreign');
        });
        Schema::table('poll_votes', function (Blueprint $table): void {
            $table->uuid('actor_id')->nullable()->change();
            $table->char('voter_hash', 64)->nullable();
        });
        Schema::table('poll_votes', function (Blueprint $table): void {
            $table->foreign('actor_id')->references('id')->on('actors')->nullOnDelete();
            $table->unique(['poll_id', 'voter_hash'], 'poll_votes_identity_unique');
        });
        // Use the same identity as new votes, without modifying their IDs or dates.
        DB::table('poll_votes')->join('actors', 'actors.id', '=', 'poll_votes.actor_id')
            ->select('poll_votes.id', 'actors.uri')->orderBy('poll_votes.id')
            ->chunkById(500, function ($votes): void {
                foreach ($votes as $vote) {
                    DB::table('poll_votes')->where('id', $vote->id)->update([
                        'voter_hash' => hash_hmac('sha256', $vote->uri, (string) config('app.key')),
                    ]);
                }
            }, 'poll_votes.id', 'id');

        Schema::table('poll_vote_choices', function (Blueprint $table): void {
            // A remote option can disappear; keep the original name/position snapshot.
            $table->uuid('option_id')->nullable();
            $table->char('remote_uri_hash', 64)->nullable()->unique();
            $table->foreign('option_id')->references('id')->on('poll_options')->nullOnDelete();
            $table->unique(['vote_id', 'option_id'], 'poll_choices_option_unique');
            $table->index(['option_id', 'id'], 'poll_choices_listing_index');
        });
    }

    public function down(): void
    {
        // The old schema cannot represent local polls or detached voters: do not discard them.
        if (DB::table('polls')->join('posts', 'posts.id', '=', 'polls.post_id')->whereNull('posts.uri')->exists()
            || DB::table('poll_votes')->whereNull('actor_id')->exists()) {
            throw new RuntimeException('Rollback requires no local polls or detached poll voters.');
        }
        $this->alterSafely(fn () => $this->restoreRemoteSchema());
    }

    private function restoreRemoteSchema(): void
    {
        Schema::table('poll_vote_choices', function (Blueprint $table): void {
            $table->dropForeign(['option_id']);
        });
        Schema::table('poll_vote_choices', function (Blueprint $table): void {
            $table->dropUnique('poll_choices_option_unique');
            $table->dropIndex('poll_choices_listing_index');
            $table->dropUnique('poll_vote_choices_remote_uri_hash_unique');
            $table->dropColumn(['option_id', 'remote_uri_hash']);
        });
        Schema::table('poll_votes', function (Blueprint $table): void {
            $table->dropForeign(['actor_id']);
        });
        Schema::table('poll_votes', function (Blueprint $table): void {
            $table->dropUnique('poll_votes_identity_unique');
            $table->dropColumn('voter_hash');
            $table->uuid('actor_id')->nullable(false)->change();
        });
        Schema::table('poll_votes', function (Blueprint $table): void {
            $table->foreign('actor_id', 'remote_poll_votes_actor_id_foreign')->references('id')->on('actors')->cascadeOnDelete();
        });
        Schema::table('polls', function (Blueprint $table): void {
            $table->dropIndex('polls_delivery_index');
            $table->dropIndex('polls_expiry_index');
            $table->dropColumn(['revision', 'results_updated_at', 'publication_pending', 'needs_delivery', 'distributed_revision']);
        });
        $this->renameIndexes(true);
        foreach (array_reverse(self::TABLES, true) as $old => $new) {
            Schema::rename($new, $old);
        }
    }

    private function renameIndexes(bool $reverse): void
    {
        foreach (self::INDEXES as $table => $suffixes) {
            foreach ($suffixes as $suffix) {
                $old = 'remote_'.$table.'_'.$suffix;
                $new = $table.'_'.$suffix;
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->renameIndex($reverse ? $new : $old, $reverse ? $old : $new));
            }
        }
    }

    private function alterSafely(Closure $operation): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite rebuilds parent tables on FK changes. Disable checks before
            // starting the transaction, otherwise DROP TABLE would cascade choices.
            Schema::withoutForeignKeyConstraints(fn () => DB::transaction($operation));
        } else {
            $operation();
        }
    }
};
