<?php

namespace Tests\Unit\Support;

use App\Support\Assets;
use Tests\TestCase;

/**
 * Gli asset statici in "public/assets" non passano da alcuna pipeline di
 * build (niente hash nel nome del file), quindi senza una query string di
 * versione i browser possono continuare a servire dalla cache una copia
 * vecchia del file anche dopo un aggiornamento del software.
 */
class AssetsTest extends TestCase
{
    public function test_it_appends_the_file_modification_time_as_a_version_query_string(): void
    {
        $url = Assets::url('assets/css/app.css');

        $this->assertMatchesRegularExpression('/\?v=\d+$/', $url);
        $this->assertStringContainsString('assets/css/app.css', $url);
    }

    public function test_two_urls_for_the_same_untouched_file_are_identical(): void
    {
        $this->assertSame(Assets::url('assets/js/lightbox.js'), Assets::url('assets/js/lightbox.js'));
    }

    public function test_it_falls_back_to_a_plain_url_when_the_file_does_not_exist(): void
    {
        $url = Assets::url('assets/does-not-exist.css');

        $this->assertStringNotContainsString('?v=', $url);
        $this->assertStringContainsString('assets/does-not-exist.css', $url);
    }
}
