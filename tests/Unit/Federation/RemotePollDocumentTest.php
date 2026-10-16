<?php

namespace Tests\Unit\Federation;

use App\Federation\Polls\RemotePollDocument;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RemotePollDocumentTest extends TestCase
{
    public function test_realistic_create_and_update_keep_unknown_and_zero_counts_distinct(): void
    {
        $create = RemotePollDocument::fromArray($this->document('single-create'));
        $update = RemotePollDocument::fromArray($this->document('single-update'));
        $unknown = RemotePollDocument::fromArray($this->document('open-unknown-results'));

        $this->assertFalse($create->multiple);
        $this->assertSame(0, $create->votersCount);
        $this->assertSame(0, $create->options[0]['votes_count']);
        $this->assertSame(46, $update->votersCount);
        $this->assertSame([20, 15, 7, 4], array_column($update->options, 'votes_count'));
        $this->assertSame(array_column($create->options, 'name'), array_column($update->options, 'name'));
        $this->assertNull($unknown->endsAt);
        $this->assertNull($unknown->votersCount);
        $this->assertNull($unknown->options[0]['votes_count']);
    }

    public function test_multiple_choice_preserves_authoritative_voter_count(): void
    {
        $poll = RemotePollDocument::fromArray($this->document('multiple-update'));

        $this->assertTrue($poll->multiple);
        $this->assertSame(7, $poll->votersCount);
        $this->assertSame(16, array_sum(array_column($poll->options, 'votes_count')));
    }

    public function test_closure_accepts_timestamp_and_boolean_and_normalizes_offsets(): void
    {
        $document = $this->document('closed-update');
        $poll = RemotePollDocument::fromArray($document);
        $this->assertSame('2026-10-09T10:00:00+00:00', $poll->closedAt->copy()->utc()->toIso8601String());
        $document['closed'] = true;
        $document['endTime'] = '2026-10-09T12:00:00+02:00';
        $poll = RemotePollDocument::fromArray($document);
        $this->assertTrue($poll->closed);
        $this->assertNull($poll->closedAt);
        $this->assertSame('2026-10-09T10:00:00+00:00', $poll->endsAt->copy()->utc()->toIso8601String());
        $this->assertSame(config('app.timezone'), $poll->endsAt->timezoneName);
    }

    public function test_option_names_are_preserved_exactly_for_federated_voting(): void
    {
        $document = $this->document('single-create');
        $document['oneOf'][0]['name'] = ' Sabato ';
        $document['oneOf'][1]['name'] = 'sabato';
        $document['oneOf'][2]['name'] = 'Sábato';
        $document['type'] = ['https://www.w3.org/ns/activitystreams#Question'];

        $poll = RemotePollDocument::fromArray($document);
        $this->assertSame([' Sabato ', 'sabato', 'Sábato', 'Un altro giorno'], array_column($poll->options, 'name'));
    }

    #[DataProvider('invalidDocuments')]
    public function test_malformed_poll_metadata_is_rejected(array $changes): void
    {
        $document = array_replace_recursive($this->document('single-create'), $changes);
        $this->expectException(InvalidArgumentException::class);
        RemotePollDocument::fromArray($document);
    }

    public static function invalidDocuments(): array
    {
        return [
            'not a question' => [['type' => 'Note']],
            'both modes' => [['anyOf' => []]],
            'invalid list' => [['oneOf' => 'https://remote.example/options']],
            'wrong option type' => [['oneOf' => [0 => ['type' => 'Person']]]],
            'empty name' => [['oneOf' => [0 => ['name' => '  ']]]],
            'duplicate name' => [['oneOf' => [1 => ['name' => 'Sabato']]]],
            'oversized name' => [['oneOf' => [0 => ['name' => str_repeat('x', RemotePollDocument::MAX_OPTION_LENGTH + 1)]]]],
            'negative count' => [['votersCount' => -1]],
            'string count' => [['votersCount' => '7']],
            'float option count' => [['oneOf' => [0 => ['replies' => ['totalItems' => 1.5]]]]],
            'boolean count' => [['votersCount' => true]],
            'invalid replies' => [['oneOf' => [0 => ['replies' => 'invalid']]]],
            'relative time' => [['endTime' => 'tomorrow']],
            'invalid calendar date' => [['endTime' => '2026-02-30T12:00:00Z']],
            'date outside mysql range' => [['endTime' => '0001-01-01T12:00:00Z']],
            'missing timezone' => [['endTime' => '2026-10-09T10:00:00']],
            'invalid closure' => [['closed' => []]],
            'empty closure' => [['closed' => '']],
        ];
    }

    public function test_missing_mode_and_excessive_options_are_rejected(): void
    {
        $document = $this->document('single-create');
        unset($document['oneOf']);
        try {
            RemotePollDocument::fromArray($document);
            $this->fail('Missing choice mode accepted.');
        } catch (InvalidArgumentException) {
            $document['oneOf'] = array_fill(0, RemotePollDocument::MAX_OPTIONS + 1, ['type' => 'Note', 'name' => 'Choice']);
            $this->expectException(InvalidArgumentException::class);
            RemotePollDocument::fromArray($document);
        }
    }

    private function document(string $fixture): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/Federation/Polls/'.$fixture.'.json')), true, 512, JSON_THROW_ON_ERROR)['object'];
    }
}
