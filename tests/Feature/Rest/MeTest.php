<?php

namespace Tests\Feature\Rest;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\UsesPassportKeys;
use Tests\TestCase;

class MeTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase, UsesPassportKeys;

    public function test_me_contains_only_the_authenticated_person_and_reuses_its_full_representation(): void
    {
        Http::preventStrayRequests();
        $this->configurePassportKeys();
        $user = $this->createFullAccount('myaccount');
        $other = $this->createFullAccount('otheraccount');
        $user->profile->update(['display_name' => 'My name', 'bio' => '**My biography**']);
        Passport::actingAs($user, ['api'], 'rest');
        $this->withToken('test-token');

        $person = $this->get('/rest/v1/persons/'.$user->actor->id)->assertOk()->json();
        $this->get('/rest/v1/me?personId='.$other->actor->id)->assertOk()
            ->assertExactJson(['myPerson' => $person])
            ->assertHeader('Vary', 'Authorization');
        Http::assertNothingSent();
    }

    public function test_me_supports_private_cache_revalidation_and_reflects_profile_changes(): void
    {
        $this->configurePassportKeys();
        $user = $this->createFullAccount('cachedme');
        Passport::actingAs($user, ['api'], 'rest');
        $response = $this->withToken('test-token')->get('/rest/v1/me')->assertOk()->assertHeader('ETag');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->withHeader('If-None-Match', $response->headers->get('ETag'))->get('/rest/v1/me')->assertStatus(304)->assertContent('');
        $user->profile->update(['display_name' => 'Changed name']);
        $this->get('/rest/v1/me')->assertOk()->assertJsonPath('myPerson.personProfile.personName', 'Changed name');
    }
}
