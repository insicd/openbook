<?php

namespace Tests\Unit\Federation\Inbox;

use App\Federation\Inbox\RemotePostObject;
use App\Federation\Support\JsonLdLanguage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RemotePostLanguageTest extends TestCase
{
    #[DataProvider('documents')]
    public function test_language_describes_only_the_selected_unambiguous_text(array $document, ?string $expected): void
    {
        $this->assertSame($expected, RemotePostObject::language($document));
    }

    public static function documents(): array
    {
        $content = '<p>Hello</p>';
        $context = ['https://www.w3.org/ns/activitystreams', ['@language' => 'IT-ch']];

        return [
            [['content' => $content, 'contentMap' => ['EN' => $content]], 'en'],
            [['contentMap' => ['it' => $content]], 'it'],
            [['content' => '', 'contentMap' => ['it' => $content]], 'it'],
            [['content' => $content], null],
            [['content' => $content, 'contentMap' => ['it' => '<p>Different</p>']], null],
            [['content' => $content, 'contentMap' => ['it' => $content, 'en' => $content]], null],
            [['content' => $content, 'contentMap' => ['it' => $content, 'invalid!' => null]], null],
            [['contentMap' => ['und' => $content]], null],
            [['contentMap' => ['unknown' => $content]], null],
            [['contentMap' => ['it' => ['@value' => $content]]], null],
            [['contentMap' => ['it' => ''], 'source' => ['content' => $content]], null],
            [['content' => '<p></p>', 'contentMap' => ['it' => '<p></p>'], 'summary' => 'Fallback'], null],
            [['content' => $content, '@context' => $context], 'it-ch'],
            [['content' => $content, '@context' => $context, 'contentMap' => ['en' => $content]], 'en'],
            [['content' => $content, '@context' => $context, 'contentMap' => ['en' => $content, 'it' => $content]], null],
            [['content' => $content, '@context' => $context, 'contentMap' => []], null],
            [['content' => $content, '@context' => $context, 'contentMap' => 'invalid'], null],
            [['source' => ['content' => $content], '@context' => $context], null],
            [['summary' => $content, '@context' => $context], null],
            [['content' => $content, '@context' => ['@language' => 'und']], null],
            [['content' => $content, '@context' => [$context[0], ['@language' => 'it'], ['@language' => null]]], null],
            [['content' => $content, '@context' => [$context[0], ['@language' => 'it'], null]], null],
            [['content' => $content, '@context' => [$context[0], ['@language' => 'it'], 'https://example.org/context']], null],
            [['content' => $content, '@context' => ['https://example.org/context', ['@language' => 'it']]], null],
            [['content' => $content, '@context' => ['@language' => 'it', 'content' => ['@id' => 'as:content', '@language' => 'fr']]], null],
            [['content' => $content, '@context' => [['@language' => 'it'], ['@propagate' => false]]], null],
            [['content' => $content, '@context' => false], null],
            [['content' => $content, '@context' => ['@language' => 'it', '@vocab' => 'https://other.example/']], null],
            [['content' => $content, '@context' => ['@language' => 'it', 'Note' => ['@context' => ['@language' => 'fr']]]], null],
            [['content' => $content, '@context' => ['https://other.example/context', null, 'https://www.w3.org/ns/activitystreams', ['@language' => 'fr']]], 'fr'],
        ];
    }

    public function test_embedded_object_inherits_overrides_and_resets_activity_default(): void
    {
        $activity = [
            '@context' => ['https://www.w3.org/ns/activitystreams', ['@language' => 'it']],
            'type' => 'Create',
            'object' => ['type' => 'Note', 'content' => '<p>Text</p>'],
        ];
        $this->assertSame('it', RemotePostObject::language(RemotePostObject::unwrap($activity)));
        $activity['object']['@context'] = ['@language' => 'fr'];
        $this->assertSame('fr', RemotePostObject::language(RemotePostObject::unwrap($activity)));
        $activity['object']['@context'] = null;
        $this->assertNull(RemotePostObject::language(RemotePostObject::unwrap($activity)));
    }

    public function test_context_inheritance_retains_nested_context_order(): void
    {
        $outer = ['@context' => ['@language' => 'it']];
        $create = JsonLdLanguage::inherit(['@context' => ['@language' => 'en']], $outer);
        $note = JsonLdLanguage::inherit(['content' => 'Text', '@context' => ['@language' => 'yue']], $create);
        $this->assertSame('yue', RemotePostObject::language($note));
    }
}
