<?php

namespace Tests\Unit\Domain\Posts;

use App\Domain\Posts\PostLanguage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PostLanguageTest extends TestCase
{
    #[DataProvider('tags')]
    public function test_normalizes_only_well_formed_determined_tags(mixed $input, ?string $expected): void
    {
        $this->assertSame($expected, PostLanguage::normalize($input));
    }

    public static function tags(): array
    {
        return [
            ['it', 'it'], ['IT-it', 'it-it'], ['it-CH', 'it-ch'],
            ['zh-Hant-TW', 'zh-hant-tw'], ['cmn', 'cmn'], ['yue', 'yue'],
            ['zh-cmn-Hans-CN', 'zh-cmn-hans-cn'], ['es-419', 'es-419'],
            ['de-CH-1901', 'de-ch-1901'], ['sl-rozaj-biske', 'sl-rozaj-biske'],
            ['en-US-u-ca-gregory-x-example', 'en-us-u-ca-gregory-x-example'],
            ['i-klingon', 'i-klingon'], ['sgn-BE-FR', 'sgn-be-fr'],
            ['en-x-'.implode('-', array_fill(0, 25, 'abcdefgh')), 'en-x-'.implode('-', array_fill(0, 25, 'abcdefgh'))],
            [null, null], [[], null], [123, null], ['', null], [' it', null],
            ['it_IT', null], ['en--us', null], ['a', null], ['englishhhhh', null],
            ['en-1234abcd9', null], ['en-u', null], ['en-x', null],
            ['sl-rozaj-rozaj', null], ['en-u-ca-u-nu', null],
            ['und', null], ['und-Latn', null], ['mul', null], ['zxx', null],
            ['unknown', null], ['i-default', null], ['x-local', null], ['<script>', null],
            ['en-x-'.implode('-', array_fill(0, 30, 'abcdefgh')), null],
        ];
    }
}
