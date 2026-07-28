<?php

namespace App\Federation\Inbox;

use App\Domain\Posts\PostBodyRenderer;

/**
 * Converte il campo "content" (HTML) di una Note remota in testo semplice
 * compatibile con la colonna "body" di post e commenti.
 *
 * Decisione architetturale: invece di introdurre un sanitizzatore HTML
 * completo (dipendenza aggiuntiva, superficie di rischio non banale per
 * contenuto arbitrario proveniente da server non fidati) il contenuto remoto
 * viene ridotto a testo semplice e fatto poi transitare dalla stessa
 * pipeline di rendering usata per i post locali ({@see PostBodyRenderer}),
 * che gia' esegue escaping HTML e linkificazione in modo sicuro. Il prezzo
 * pagato e' la perdita della formattazione ricca (grassetto, liste, link
 * gia' pronti) dei post remoti: una semplificazione documentata, non un
 * compromesso di sicurezza.
 */
final class RemoteContentSanitizer
{
    public static function toPlainText(string $html): string
    {
        $withBreaks = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $withParagraphs = preg_replace('#</p>|</div>|</li>#i', "\n\n", $withBreaks) ?? $withBreaks;

        $text = html_entity_decode(strip_tags($withParagraphs), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
