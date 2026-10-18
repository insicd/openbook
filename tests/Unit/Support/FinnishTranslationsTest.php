<?php

namespace Tests\Unit\Support;

use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FinnishTranslationsTest extends TestCase
{
    #[DataProvider('languageFiles')]
    public function test_finnish_has_all_keys_placeholders_and_plural_ranges(string $file): void
    {
        $source = Arr::dot(require lang_path('en/'.$file.'.php'));
        $finnish = Arr::dot(require lang_path('fi/'.$file.'.php'));
        $this->assertSame(array_keys($source), array_keys($finnish));
        foreach ($source as $key => $value) {
            if (is_array($value)) {
                $this->assertSame($value, $finnish[$key]);

                continue;
            }
            $translated = $finnish[$key];
            $this->assertIsString($translated, $key);
            $this->assertNotSame('', trim($translated), $key);
            $this->assertTrue(mb_check_encoding($translated, 'UTF-8'), $key);
            preg_match_all('/(?<![\p{L}\p{N}_]):[a-zA-Z_][a-zA-Z0-9_]*/u', $value, $expected);
            preg_match_all('/(?<![\p{L}\p{N}_]):[a-zA-Z_][a-zA-Z0-9_]*/u', $translated, $actual);
            sort($expected[0]);
            sort($actual[0]);
            $this->assertSame($expected[0], $actual[0], $key);
            if (str_contains($value, '|')) {
                $ranges = static function (string $text): array {
                    return array_map(static function (string $part): string {
                        preg_match('/^(\{[^}]+\}|\[[^]]+\])/', $part, $match);

                        return $match[0] ?? '';
                    }, explode('|', $text));
                };
                $this->assertSame($ranges($value), $ranges($translated), $key);
            }
        }
    }

    public static function languageFiles(): array
    {
        return [['openbook'], ['validation'], ['auth'], ['passwords'], ['pagination']];
    }
}
