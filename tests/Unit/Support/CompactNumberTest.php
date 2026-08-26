<?php

namespace Tests\Unit\Support;

use App\Support\CompactNumber;
use Tests\TestCase;

class CompactNumberTest extends TestCase
{
    public function test_it_keeps_counts_below_one_thousand(): void
    {
        $this->assertSame('999', CompactNumber::format(999));
        $this->assertSame('1', CompactNumber::format(1));
        $this->assertSame('0', CompactNumber::format(0));
    }

    public function test_it_uses_a_comma_decimal_in_italian(): void
    {
        $this->app->setLocale('it');

        $this->assertSame('1k', CompactNumber::format(1000));
        $this->assertSame('1,1k', CompactNumber::format(1100));
        $this->assertSame('19,6k', CompactNumber::format(19600));
        $this->assertSame('196k', CompactNumber::format(196000));
        $this->assertSame('1M', CompactNumber::format(1_000_000));
        $this->assertSame('1,2M', CompactNumber::format(1_200_000));
    }

    public function test_it_uses_a_dot_decimal_in_english(): void
    {
        $this->app->setLocale('en');

        $this->assertSame('19.6k', CompactNumber::format(19600));
        $this->assertSame('1.2M', CompactNumber::format(1_200_000));
    }
}
