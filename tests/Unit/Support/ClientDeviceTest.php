<?php

namespace Tests\Unit\Support;

use App\Support\ClientDevice;
use Tests\TestCase;

class ClientDeviceTest extends TestCase
{
    public function test_it_detects_common_phones(): void
    {
        $this->assertTrue(ClientDevice::isMobile(
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15',
        ));
        $this->assertTrue(ClientDevice::isMobile(
            'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Mobile Safari/537.36',
        ));
    }

    public function test_it_does_not_treat_desktops_as_mobile(): void
    {
        $this->assertFalse(ClientDevice::isMobile(
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/128.0.0.0 Safari/537.36',
        ));
        $this->assertFalse(ClientDevice::isMobile(''));
    }
}
