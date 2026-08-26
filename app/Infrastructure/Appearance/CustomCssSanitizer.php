<?php

namespace App\Infrastructure\Appearance;

/**
 * Riduce i rischi del CSS custom dell'istanza: un amministratore puo'
 * comunque rompere il layout, ma non deve poter uscire dal tag <style>
 * ne' importare fogli remoti.
 */
final class CustomCssSanitizer
{
    public function sanitize(string $css): string
    {
        $css = str_replace("\0", '', $css);
        $css = preg_replace('/<\/style/i', '', $css) ?? $css;
        $css = preg_replace('/@import\b[^;{]*;?/i', '', $css) ?? $css;
        $css = preg_replace('/expression\s*\(/i', 'invalid(', $css) ?? $css;
        $css = preg_replace('/-moz-binding\s*:/i', 'invalid:', $css) ?? $css;
        $css = preg_replace(
            '/url\s*\(\s*[\'"]?\s*(javascript|vbscript)\s*:/i',
            'url(about:blank',
            $css,
        ) ?? $css;

        return $css;
    }
}
