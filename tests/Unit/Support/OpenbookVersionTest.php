<?php

namespace Tests\Unit\Support;

use App\Support\OpenbookVersion;
use Tests\TestCase;

class OpenbookVersionTest extends TestCase
{
    public function test_it_accepts_calendar_legacy_and_rc_strings(): void
    {
        $this->assertTrue(OpenbookVersion::isValid('26.34'));
        $this->assertTrue(OpenbookVersion::isValid('26.34.rc1'));
        $this->assertTrue(OpenbookVersion::isValid('0.9.2'));
        $this->assertFalse(OpenbookVersion::isValid('pancake'));
        $this->assertFalse(OpenbookVersion::isValid('26.34-Lovable'));
    }

    public function test_patch_rcs_come_after_the_same_week_stable(): void
    {
        $this->assertTrue(OpenbookVersion::isNewer('26.34.rc1', '26.34'));
        $this->assertFalse(OpenbookVersion::isNewer('26.34', '26.34.rc1'));
        $this->assertTrue(OpenbookVersion::isNewer('26.34.rc2', '26.34.rc1'));
        $this->assertTrue(OpenbookVersion::isNewer('26.35', '26.34.rc9'));
        $this->assertTrue(OpenbookVersion::isNewer('26.34', '0.9.2'));
        $this->assertFalse(OpenbookVersion::isNewer('26.34', '26.34'));
    }
}
