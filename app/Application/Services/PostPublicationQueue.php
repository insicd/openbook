<?php

namespace App\Application\Services;

use App\Domain\Posts\PendingPostPublication;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Acquisisce e rilascia atomicamente i lavori della coda video. */
final class PostPublicationQueue
{
    public function claimNext(): ?PendingPostPublication
    {
        return Cache::lock('openbook:video-publication-claim', 10)->block(3, function () {
            return DB::transaction(function (): ?PendingPostPublication {
                $maxAttempts = max(1, (int) config('openbook.video.worker_max_attempts', 3));
                $expiredBefore = now()->subSeconds($this->claimLeaseSeconds());

                PendingPostPublication::query()
                    ->where('status', PendingPostPublication::STATUS_PROCESSING)
                    ->where('claimed_at', '<=', $expiredBefore)
                    ->where('attempts', '>=', $maxAttempts)
                    ->update([
                        'status' => PendingPostPublication::STATUS_FAILED,
                        'claim_token' => null,
                        'claimed_at' => null,
                        'last_error' => 'Il worker ha superato il numero massimo di tentativi.',
                        'updated_at' => now(),
                    ]);

                $publication = PendingPostPublication::query()
                    ->where('attempts', '<', $maxAttempts)
                    ->where(function ($query) use ($expiredBefore): void {
                        $query->where('status', PendingPostPublication::STATUS_PENDING)
                            ->orWhere(function ($query) use ($expiredBefore): void {
                                $query->where('status', PendingPostPublication::STATUS_PROCESSING)
                                    ->where('claimed_at', '<=', $expiredBefore);
                            });
                    })
                    ->orderBy('created_at')
                    ->lockForUpdate()
                    ->first();

                if ($publication === null) {
                    return null;
                }

                $publication->forceFill([
                    'status' => PendingPostPublication::STATUS_PROCESSING,
                    'attempts' => $publication->attempts + 1,
                    'claim_token' => (string) Str::uuid(),
                    'claimed_at' => now(),
                    'last_error' => null,
                ])->save();

                return $publication->load(['actor', 'attachments']);
            });
        });
    }

    public function fail(PendingPostPublication $publication, string $claimToken, Throwable $exception): bool
    {
        $maxAttempts = max(1, (int) config('openbook.video.worker_max_attempts', 3));
        $status = $publication->attempts >= $maxAttempts
            ? PendingPostPublication::STATUS_FAILED
            : PendingPostPublication::STATUS_PENDING;

        return PendingPostPublication::query()
            ->whereKey($publication->id)
            ->where('status', PendingPostPublication::STATUS_PROCESSING)
            ->where('claim_token', $claimToken)
            ->update([
                'status' => $status,
                'claim_token' => null,
                'claimed_at' => null,
                'last_error' => mb_substr($exception->getMessage(), 0, 4000),
                'updated_at' => now(),
            ]) === 1;
    }

    private function claimLeaseSeconds(): int
    {
        return max(
            60,
            (int) config('openbook.video.worker_claim_seconds', 1200),
            (int) config('openbook.video.process_timeout_seconds', 900) + 60,
        );
    }
}
