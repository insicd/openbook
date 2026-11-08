<?php

use App\Application\Services\HashtagStatsBootstrap;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['daily' => 'day', 'monthly' => 'month'] as $period => $column) {
            if (Schema::hasTable('stats_hashtag_'.$period.'_buckets')) {
                continue;
            }
            Schema::create('stats_hashtag_'.$period.'_buckets', function (Blueprint $table) use ($column): void {
                $table->uuid('hashtag_id');
                $table->date($column);
                $table->unsignedBigInteger('count')->default(0);
                $table->primary(['hashtag_id', $column]);
                $table->index([$column, 'hashtag_id', 'count']);
                // Historical tag identity must survive removal of the original posts.
                $table->foreign('hashtag_id')->references('id')->on('hashtags')->restrictOnDelete();
            });
        }
        DB::table('stats_collections')->insertOrIgnore(['metric' => 'hashtag_buckets', 'started_at' => now('UTC')->format('Y-m-d H:i:s')]);
        // Explicit product requirement: upgrade also seeds retained history.
        // Use the migration connection so SQLite sees its uncommitted DDL.
        $bootstrap = app(HashtagStatsBootstrap::class);
        $month = CarbonImmutable::now('UTC')->startOfMonth();
        $writer = DB::getDriverName() === 'sqlite' ? DB::connection() : null;
        foreach (range(23, 0) as $offset) {
            $bootstrap->month($month->subMonthsNoOverflow($offset), $writer);
        }

    }

    public function down(): void
    {
        Schema::dropIfExists('stats_hashtag_daily_buckets');
        Schema::dropIfExists('stats_hashtag_monthly_buckets');
        DB::table('stats_collections')->where('metric', 'hashtag_buckets')->orWhere('metric', 'like', 'hashtag_seed:%')->delete();
    }
};
