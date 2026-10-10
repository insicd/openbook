<?php

namespace Tests\Unit\Federation\Inbox;

use App\Federation\Inbox\RemoteProfileFields;
use Tests\TestCase;

class RemoteProfileFieldsTest extends TestCase
{
    public function test_it_preserves_order_duplicate_labels_and_text_or_links(): void
    {
        $this->assertSame([
            ['label' => 'Attività', 'value' => 'Insegnante & sviluppatore'],
            ['label' => 'Sito', 'value' => '[Il mio sito](https://example.test/about)'],
            ['label' => 'Sito', 'value' => 'https://blog.example.test'],
        ], RemoteProfileFields::extract(['attachment' => [
            ['type' => 'PropertyValue', 'name' => '<b>Attività</b>', 'value' => '<p>Insegnante &amp; sviluppatore</p>'],
            ['type' => 'PropertyValue', 'name' => 'Sito', 'value' => '<a href="https://example.test/about" onclick="alert(1)">Il mio sito</a>'],
            ['type' => 'PropertyValue', 'name' => 'Sito', 'value' => 'https://blog.example.test'],
        ]]));
    }

    public function test_it_accepts_single_fields_and_known_schema_type_forms(): void
    {
        foreach (['PropertyValue', 'schema:PropertyValue', 'http://schema.org#PropertyValue', 'https://schema.org/PropertyValue'] as $type) {
            $this->assertSame([['label' => 'Pronomi', 'value' => 'lei']], RemoteProfileFields::extract([
                'attachment' => ['type' => $type, 'name' => 'Pronomi', 'value' => 'lei'],
            ]));
        }
    }

    public function test_it_ignores_unusable_fields_and_active_media(): void
    {
        $this->assertSame([['label' => 'Sito', 'value' => 'Testo']], RemoteProfileFields::extract(['attachment' => [
            null,
            'invalid',
            ['type' => 'Image', 'name' => 'Foto', 'value' => 'https://example.test/photo.jpg'],
            ['type' => ['PropertyValue'], 'name' => 'Tipo errato', 'value' => 'Testo'],
            ['type' => 'PropertyValue', 'name' => [], 'value' => 'Testo'],
            ['type' => 'PropertyValue', 'name' => 'Oggetto', 'value' => ['id' => 'https://example.test']],
            ['type' => 'PropertyValue', 'name' => '  ', 'value' => 'Testo'],
            ['type' => 'PropertyValue', 'name' => 'Vuoto', 'value' => '<img src="https://example.test/a.jpg">'],
            ['type' => 'PropertyValue', 'name' => 'Sito', 'value' => '<a href="javascript:alert(1)">Testo</a><img src="https://example.test/a.jpg">'],
        ]]));

        $this->assertSame([], RemoteProfileFields::extract([]));
        $this->assertSame([], RemoteProfileFields::extract(['attachment' => 'invalid']));
    }

    public function test_it_bounds_field_count_and_unicode_text_lengths(): void
    {
        $fields = RemoteProfileFields::extract(['attachment' => array_fill(0, 20, [
            'type' => 'PropertyValue', 'name' => str_repeat('è', 101), 'value' => str_repeat('語', 1001),
        ])]);

        $this->assertCount(16, $fields);
        $this->assertSame(str_repeat('è', 100), $fields[0]['label']);
        $this->assertSame(str_repeat('語', 1000), $fields[0]['value']);
    }
}
