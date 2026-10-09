<?php

namespace Tests\Unit\Support;

use App\Support\PostLanguageLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PostLanguageLabelTest extends TestCase
{
    #[DataProvider('labels')]
    public function test_language_names_preserve_distinctions_and_fall_back_to_the_code(string $tag, string $locale, string $expected): void
    {
        $this->assertSame($expected, PostLanguageLabel::name($tag, $locale));
    }

    public static function labels(): array
    {
        return [
            ['it', 'en', 'Italian'], ['IT', 'it', 'Italiano'],
            ['it-ch', 'it', 'Italiano (Svizzera)'],
            ['zh-hant-tw', 'it', 'Cinese (tradizionale, Taiwan)'],
            ['zh-hans', 'en', 'Chinese (Simplified)'],
            ['yue', 'it', 'Cantonese'], ['cmn', 'it', 'Cinese mandarino'],
            ['yue', 'en', 'Cantonese'], ['cmn', 'en', 'Mandarin Chinese'],
            ['ita', 'it', 'Italiano'], ['zz', 'it', 'zz'],
            ['en-x-example', 'it', 'en-x-example'],
            ['en-u-ca-gregory', 'en', 'en-u-ca-gregory'],
            ['<script>', 'it', '<script>'],
        ];
    }
}
