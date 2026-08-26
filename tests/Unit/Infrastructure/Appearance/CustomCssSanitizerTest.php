<?php

namespace Tests\Unit\Infrastructure\Appearance;

use App\Infrastructure\Appearance\CustomCssSanitizer;
use PHPUnit\Framework\TestCase;

class CustomCssSanitizerTest extends TestCase
{
    public function test_it_strips_style_tag_breakout_and_imports(): void
    {
        $css = (new CustomCssSanitizer)->sanitize(
            "body { color: red; }\n@import url('https://evil.test/x.css');\n</style><script>alert(1)</script>",
        );

        $this->assertStringContainsString('body { color: red; }', $css);
        $this->assertStringNotContainsString('@import', $css);
        $this->assertStringNotContainsString('</style', $css);
    }

    public function test_it_neutralizes_javascript_urls_and_expressions(): void
    {
        $css = (new CustomCssSanitizer)->sanitize(
            'a { background: url(javascript:alert(1)); behavior: expression(alert(1)); -moz-binding: url(x); }',
        );

        $this->assertStringNotContainsString('javascript:', $css);
        $this->assertStringNotContainsString('expression(', $css);
        $this->assertStringNotContainsString('-moz-binding', $css);
    }
}
