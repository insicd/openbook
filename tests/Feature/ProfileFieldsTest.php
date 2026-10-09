<?php

namespace Tests\Feature;

use App\Application\Services\FollowManager;
use App\Jobs\Federation\DeliverActivityJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\CreatesRemoteActors;
use Tests\TestCase;

class ProfileFieldsTest extends TestCase
{
    use CreatesAccounts, CreatesRemoteActors, RefreshDatabase;

    public function test_mixed_fields_are_saved_rendered_and_federated_as_text_or_links(): void
    {
        Queue::fake();
        $user = $this->createFullAccount('mixedfields');
        app(FollowManager::class)->follow($this->createRemoteActor('reader'), $user->actor);
        $fields = [
            ['label' => 'Sito', 'value' => 'https://example.test/?a=1&b=2', 'kind' => 'link'],
            ['label' => 'Professione', 'value' => '<script>alert(1)</script> & **Insegnante**', 'kind' => 'text'],
            ['label' => 'Zero', 'value' => '0', 'kind' => 'text'],
        ];

        $this->actingAs($user)->put(route('settings.profile.update'), [
            'display_name' => 'Alice', 'links' => $fields,
        ])->assertSessionHasNoErrors()->assertRedirect(route('profile.show', $user->username));

        $stored = array_map(fn (array $field): array => ['label' => $field['label'], 'value' => $field['value']], $fields);
        $this->assertSame($stored, $user->profile->fresh()->links);
        $this->assertNull($user->actor->fresh()->links);

        $this->get(route('profile.show', $user->username))->assertOk()
            ->assertSee('href="https://example.test/?a=1&amp;b=2"', false)
            ->assertSee('<script>alert(1)</script> & **Insegnante**')
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<strong>Insegnante</strong>', false);

        $response = $this->get('/users/mixedfields', ['Accept' => 'application/activity+json'])->assertOk();
        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt; &amp; **Insegnante**', $response->json('attachment.1.value'));
        $this->assertSame('0', $response->json('attachment.2.value'));
        Queue::assertPushed(DeliverActivityJob::class, fn (DeliverActivityJob $job): bool => $job->activity['type'] === 'Update'
            && $job->activity['object']['attachment'] === $response->json('attachment'));
    }

    public function test_the_editor_reclassifies_values_and_reads_legacy_links(): void
    {
        $user = $this->createFullAccount('editorfields');
        $user->profile->update(['links' => [
            ['label' => 'Vecchio', 'url' => 'https://legacy.example.test'],
            ['label' => 'Nuovo', 'value' => 'https://new.example.test'],
            ['label' => 'Professione', 'value' => 'Insegnante'],
            ['label' => 'Non URL', 'value' => 'http è un protocollo'],
        ]]);

        $response = $this->actingAs($user)->get(route('settings.edit'))->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame(2, $xpath->query('//*[@data-profile-field-list="link"]/*[@data-profile-field]')->length);
        $this->assertSame(2, $xpath->query('//*[@data-profile-field-list="text"]/*[@data-profile-field]')->length);
        $response->assertSee('https://legacy.example.test')->assertSee('Insegnante');

        $this->get(route('profile.show', $user->username))->assertOk()
            ->assertSee('href="https://legacy.example.test"', false);
    }

    public function test_eight_fields_are_allowed_and_blank_rows_are_ignored(): void
    {
        $user = $this->createFullAccount('eightfields');
        $fields = array_fill(0, 8, ['label' => 'Campo', 'value' => str_repeat('è', 1000), 'kind' => 'text']);
        $fields[] = ['label' => '', 'value' => '', 'kind' => 'link'];

        $this->actingAs($user)->put(route('settings.profile.update'), [
            'display_name' => 'Alice', 'links' => $fields,
        ])->assertSessionHasNoErrors();
        $this->assertCount(8, $user->profile->fresh()->links);
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_fields_do_not_overwrite_the_saved_profile(array $fields, string $error): void
    {
        $user = $this->createFullAccount('invalidfields');
        $old = [['label' => 'Sito', 'url' => 'https://example.test']];
        $user->profile->update(['links' => $old]);

        $this->actingAs($user)->from(route('settings.edit'))->put(route('settings.profile.update'), [
            'display_name' => 'Alice', 'links' => $fields,
        ])->assertSessionHasErrors($error);
        $this->assertSame($old, $user->profile->fresh()->links);
        $this->get(route('settings.edit'))->assertOk();
    }

    public static function invalidFields(): array
    {
        return [
            'too many' => [array_fill(0, 9, ['label' => 'Campo', 'value' => 'testo']), 'links'],
            'missing label' => [[['value' => 'testo']], 'links.0.label'],
            'missing value' => [[['label' => 'Campo']], 'links.0.value'],
            'bad link' => [[['label' => 'Sito', 'value' => 'javascript:alert(1)', 'kind' => 'link']], 'links.0.value'],
            'too long text' => [[['label' => 'Campo', 'value' => str_repeat('a', 1001)]], 'links.0.value'],
            'too long label' => [[['label' => str_repeat('a', 51), 'value' => 'testo']], 'links.0.label'],
            'too long link in text' => [[['label' => 'Sito', 'value' => 'https://example.test/'.str_repeat('a', 256), 'kind' => 'text']], 'links.0.value'],
            'non scalar value' => [[['label' => 'Campo', 'value' => ['testo']]], 'links.0.value'],
        ];
    }
}
