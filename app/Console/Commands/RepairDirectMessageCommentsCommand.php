<?php

namespace App\Console\Commands;

use App\Application\Services\DirectMessageLinker;
use App\Domain\Comments\Comment;
use App\Domain\Posts\Post;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Converte commenti creati per errore su post privati in messaggi di conversazione.
 */
final class RepairDirectMessageCommentsCommand extends Command
{
    protected $signature = 'openbook:repair-dm-comments
        {--dry-run : Mostra cosa verrebbe convertito senza scrivere}';

    protected $description = 'Sposta in /messaggi i reply DM federati salvati per errore come commenti.';

    public function handle(DirectMessageLinker $linker): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $converted = 0;

        Comment::query()
            ->whereNotNull('uri')
            ->where('status', Comment::STATUS_PUBLISHED)
            ->with('post')
            ->orderBy('created_at')
            ->chunkById(100, function ($comments) use ($dryRun, $linker, &$converted): void {
                foreach ($comments as $comment) {
                    $parent = $comment->post;

                    if ($parent === null) {
                        continue;
                    }

                    if ($parent->conversation_id === null && $parent->visibility !== Post::VISIBILITY_DIRECT) {
                        continue;
                    }

                    if ($dryRun) {
                        $this->line("Would convert comment {$comment->id} ({$comment->uri}) on post {$parent->id}");
                        $converted++;

                        continue;
                    }

                    DB::transaction(function () use ($comment, $parent, $linker, &$converted): void {
                        if (Post::query()->where('uri', $comment->uri)->exists()) {
                            $comment->delete();

                            return;
                        }

                        $post = Post::query()->create([
                            'uri' => $comment->uri,
                            'actor_id' => $comment->actor_id,
                            'body' => $comment->body,
                            'visibility' => Post::VISIBILITY_DIRECT,
                            'status' => Post::STATUS_PUBLISHED,
                            'published_at' => $comment->created_at,
                            'conversation_id' => $parent->conversation_id,
                        ]);

                        if ($parent->conversation_id === null) {
                            $post->loadMissing('actor');
                            $linker->link($post, $post->actor, ['to' => [], 'cc' => []], true, $parent);
                        } elseif ($post->conversation_id !== $parent->conversation_id) {
                            $post->update(['conversation_id' => $parent->conversation_id]);
                        }

                        if ($parent->comments_count > 0) {
                            $parent->decrement('comments_count');
                        }

                        $comment->delete();
                        $converted++;
                    });
                }
            });

        $this->info($dryRun
            ? "Trovati {$converted} commenti da convertire (dry-run)."
            : "Convertiti {$converted} commenti in messaggi di conversazione.");

        return self::SUCCESS;
    }
}
