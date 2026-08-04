<?php

namespace Tests\Unit\Federation;

use App\Federation\Support\ActivityPubTimestamp;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ActivityPubTimestampTest extends TestCase
{
    public function test_offset_timestamps_are_converted_to_app_timezone_utc(): void
    {
        config(['app.timezone' => 'UTC']);

        $parsed = ActivityPubTimestamp::parse('2026-08-04T14:30:00+02:00');

        $this->assertSame('UTC', $parsed->timezoneName);
        $this->assertSame('2026-08-04 12:30:00', $parsed->format('Y-m-d H:i:s'));
    }

    public function test_zulu_timestamps_stay_unchanged_under_utc(): void
    {
        config(['app.timezone' => 'UTC']);

        $parsed = ActivityPubTimestamp::parse('2026-08-04T12:30:00Z');

        $this->assertSame('2026-08-04 12:30:00', $parsed->format('Y-m-d H:i:s'));
    }

    public function test_normalize_converts_an_already_parsed_carbon_instance(): void
    {
        config(['app.timezone' => 'UTC']);

        $normalized = ActivityPubTimestamp::normalize(Carbon::parse('2026-08-04T08:20:00+02:00'));

        $this->assertSame('2026-08-04 06:20:00', $normalized->format('Y-m-d H:i:s'));
    }
}
