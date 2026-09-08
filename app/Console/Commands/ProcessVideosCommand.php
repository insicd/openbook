<?php

namespace App\Console\Commands;

use App\Application\Services\InstanceSettings;
use App\Application\Services\PendingPostFinalizer;
use App\Application\Services\PostPublicationQueue;
use App\Infrastructure\Media\VideoCapability;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Worker CLI dedicato alla preparazione e pubblicazione dei post con video. */
class ProcessVideosCommand extends Command
{
    protected $signature = 'openbook:process-videos
        {--once : Elabora al massimo un elemento disponibile e termina}
        {--poll= : Secondi di attesa quando la coda e\' vuota}';

    protected $description = 'Elabora continuamente i post con video in attesa di pubblicazione.';

    public function handle(
        PostPublicationQueue $queue,
        PendingPostFinalizer $finalizer,
        InstanceSettings $instanceSettings,
    ): int {
        if (! $instanceSettings->videoEnabled()) {
            $this->components->info('Il supporto video non e\' abilitato.');

            return self::SUCCESS;
        }

        $status = app(VideoCapability::class)->inspect(
            (string) config('openbook.video.ffmpeg_path'),
            (string) config('openbook.video.ffprobe_path'),
        );

        if (! $status['available']) {
            $this->components->error('Worker video non avviato: '.($status['error'] ?? 'FFmpeg/ffprobe non disponibili.'));

            return self::FAILURE;
        }

        $stopRequested = false;
        $signals = array_values(array_filter([
            defined('SIGTERM') ? constant('SIGTERM') : null,
            defined('SIGINT') ? constant('SIGINT') : null,
        ]));

        if ($signals !== []) {
            $this->trap($signals, function () use (&$stopRequested): void {
                $stopRequested = true;
            });
        }

        $once = (bool) $this->option('once');
        $pollSeconds = $this->option('poll') !== null
            ? max(1, (int) $this->option('poll'))
            : max(1, (int) config('openbook.video.worker_poll_seconds', 5));

        $this->components->info($once
            ? 'Ricerca di un post video da elaborare.'
            : "Worker video avviato; polling ogni {$pollSeconds} secondi.");

        while (! $stopRequested) {
            if (! $instanceSettings->videoEnabled()) {
                $this->components->info('Supporto video disabilitato; arresto del worker.');

                break;
            }

            $publication = $queue->claimNext();

            if ($publication === null) {
                if ($once) {
                    $this->components->info('Nessun post video in attesa.');

                    break;
                }

                sleep($pollSeconds);

                continue;
            }

            $claimToken = (string) $publication->claim_token;
            $startedAt = microtime(true);
            $requiresTranscode = $publication->attachments->contains(
                fn ($attachment) => $attachment->processing === 'transcode',
            );

            try {
                $post = $finalizer->finalize($publication, $claimToken);
                $elapsed = number_format(microtime(true) - $startedAt, 2, ',', '');
                $processing = $requiresTranscode ? 'con transcodifica' : 'senza transcodifica';
                $this->components->info("Post video {$post->id} pubblicato in {$elapsed} secondi ({$processing}).");
            } catch (Throwable $exception) {
                $elapsed = number_format(microtime(true) - $startedAt, 2, ',', '');
                $queue->fail($publication, $claimToken, $exception);
                Log::error('video.publication_failed', [
                    'publication_id' => $publication->id,
                    'attempt' => $publication->attempts,
                    'exception' => $exception,
                ]);
                $this->components->error("Post video {$publication->id} non pubblicato dopo {$elapsed} secondi: {$exception->getMessage()}");

                if ($once) {
                    return self::FAILURE;
                }
            }

            if ($once) {
                break;
            }
        }

        $this->components->info('Worker video arrestato.');

        return self::SUCCESS;
    }
}
