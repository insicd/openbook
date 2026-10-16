<?php

namespace Tests\Unit\Support;

use App\Support\UserDate;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UserDateTest extends TestCase
{
    public function test_conversion_preserves_the_instant_and_does_not_mutate_model_dates(): void
    {
        $this->app->instance('request', Request::create('/', 'GET', [], [UserDate::COOKIE_NAME => 'Europe/Helsinki']));
        $this->app->setLocale('it');
        config(['app.timezone' => 'Europe/Rome']);
        $date = Carbon::parse('2026-01-15 23:30:00', 'Europe/Rome');
        $dates = new UserDate;
        $local = $dates->local($date);
        $this->assertSame('2026-01-16 00:30', $local->format('Y-m-d H:i'));
        $this->assertSame($date->getTimestamp(), $local->getTimestamp());
        $this->assertSame('Europe/Rome', $date->timezoneName);
        $this->assertSame('16 gennaio 2026', $dates->format($date, 'j F Y'));
        $this->assertSame('2026-01-15 23:30', $date->format('Y-m-d H:i'));
        $this->assertSame('', $dates->format(null));
    }

    public function test_normal_timezone_conversion_uses_the_date_specific_summer_offset(): void
    {
        $dates = new UserDate;
        $this->assertSame('14:00', $dates->local(Carbon::parse('2026-01-15 12:00', 'UTC'), 'Europe/Helsinki')->format('H:i'));
        $this->assertSame('15:00', $dates->local(Carbon::parse('2026-07-15 12:00', 'UTC'), 'Europe/Helsinki')->format('H:i'));
    }
}
