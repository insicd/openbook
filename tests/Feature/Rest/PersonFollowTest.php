<?php

namespace Tests\Feature\Rest;

use App\Application\Queries\FollowListQuery;
use App\Domain\SocialGraph\Follow;
use App\Federation\Actors\Actor;
use App\Federation\SocialGraph\RemoteCollectionMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class PersonFollowTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function person(string $name, array $attributes = []): Actor
    {
        return Actor::query()->create(array_merge([
            'type' => Actor::TYPE_PERSON,
            'is_local' => false,
            'preferred_username' => $name,
            'domain' => 'remote.example',
            'uri' => 'https://remote.example/users/'.$name,
            'name' => ucfirst($name),
            'status' => Actor::STATUS_ACTIVE,
        ], $attributes));
    }

    private function follow(Actor $from, Actor $to, string $status = 'accepted'): void
    {
        Follow::query()->create([
            'follower_id' => $from->id, 'following_id' => $to->id,
            'status' => $status, 'requested_at' => now(),
            'accepted_at' => $status === 'accepted' ? now() : null,
        ]);
    }

    private function cacheMember(Actor $owner, string $collection, ?Actor $member): void
    {
        RemoteCollectionMember::query()->create([
            'actor_id' => $owner->id, 'collection' => $collection,
            'member_actor_id' => $member?->id,
            'member_uri' => $member?->uri ?? 'https://unknown.example/users/unknown',
            'position' => 0,
        ]);
    }

    private function url(Actor $owner, string $collection): string
    {
        return '/rest/v1/persons/'.$owner->id.'/'.$collection;
    }

    public function test_lists_belong_to_the_specified_person_and_return_compact_references(): void
    {
        $owner = $this->createFullAccount('owner')->actor;
        $other = $this->createFullAccount('other');
        $other->profile->update(['display_name' => 'Other Person']);
        $remote = $this->person('robot', ['type' => Actor::TYPE_APPLICATION, 'icon_url' => 'https://remote.example/avatar.png']);
        $pending = $this->person('pending');
        $this->follow($owner, $remote);
        $this->follow($other->actor, $owner);
        $this->follow($owner, $pending, 'pending');
        $this->follow($pending, $owner, 'pending');

        $this->actingAs($other)->get($this->url($owner, 'following'))->assertOk()->assertExactJson([[
            'personId' => $remote->id, 'personUsername' => 'robot',
            'personDomain' => 'remote.example', 'personName' => 'Robot',
            'personAvatar' => 'https://remote.example/avatar.png',
        ]]);
        $this->get($this->url($owner, 'followers'))->assertOk()->assertExactJson([[
            'personId' => $other->actor->id, 'personUsername' => 'other',
            'personDomain' => $other->actor->domain, 'personName' => 'Other Person',
        ]]);
    }

    public function test_remote_cache_and_accepted_graph_are_combined_without_duplicates_or_network_calls(): void
    {
        Http::preventStrayRequests();
        $owner = $this->person('owner');
        $known = $this->person('known');
        $cached = $this->person('cached');
        $pending = $this->person('pending');
        $group = $this->person('group', ['type' => Actor::TYPE_GROUP]);
        foreach (['followers', 'following'] as $collection) {
            $collection === 'followers' ? $this->follow($known, $owner) : $this->follow($owner, $known);
            $collection === 'followers' ? $this->follow($pending, $owner, 'pending') : $this->follow($owner, $pending, 'pending');
            $this->cacheMember($owner, $collection, $known);
            $this->cacheMember($owner, $collection, $cached);
            $this->cacheMember($owner, $collection, $group);
            $this->cacheMember($owner, $collection, null);
            $response = $this->get($this->url($owner, $collection))->assertOk()->assertJsonCount(2);
            $ids = $response->json('*.personId');
            $this->assertEqualsCanonicalizing([$known->id, $cached->id], $ids);
            $webLists = app(FollowListQuery::class);
            $webPage = $collection === 'followers'
                ? $webLists->followers($owner)
                : $webLists->following($owner);
            $webPreview = $webLists->remotePreview($owner, $collection);
            $this->assertEqualsCanonicalizing([$cached->id, $group->id], $webPreview->pluck('id')->all());
            $this->assertEqualsCanonicalizing(
                $webPage->getCollection()->concat($webPreview)
                    ->whereIn('type', [Actor::TYPE_PERSON, Actor::TYPE_APPLICATION])
                    ->pluck('id')->all(),
                $ids,
            );
        }
        Http::assertNothingSent();
    }

    public function test_pagination_uses_person_id_in_both_directions_and_survives_a_removed_reference(): void
    {
        $owner = $this->createFullAccount('paginated')->actor;
        $people = collect(range(1, 5))->map(fn (int $i) => $this->person('member'.$i, [
            'id' => sprintf('01900000-0000-7000-8000-%012d', $i),
        ]));
        foreach ($people as $person) {
            $this->follow($owner, $person);
        }
        foreach (['asc', 'desc'] as $direction) {
            $url = $this->url($owner, 'following').'?limit=2&direction='.$direction;
            $received = [];
            do {
                $response = $this->get($url)->assertOk();
                array_push($received, ...$response->json('*.personId'));
                $link = $response->headers->get('Link');
                $url = $link ? substr($link, 1, strpos($link, '>') - 1) : null;
            } while ($url);
            $expected = $people->pluck('id')->all();
            $this->assertSame($direction === 'asc' ? $expected : array_reverse($expected), $received);
        }
        $reference = $people[1];
        Follow::query()->where('following_id', $reference->id)->delete();
        $reference->delete();
        $this->get($this->url($owner, 'following').'?limit=2&after='.$reference->id)
            ->assertOk()->assertJsonPath('0.personId', $people[2]->id)->assertJsonPath('1.personId', $people[3]->id);
    }

    public function test_unavailable_members_are_filtered_before_applying_the_page_limit(): void
    {
        $owner = $this->createFullAccount('filterowner')->actor;
        $suspended = $this->createFullAccount('suspended')->actor;
        $suspended->update(['status' => Actor::STATUS_SUSPENDED]);
        $hidden = [
            $suspended,
            $this->person('deleted', ['status' => Actor::STATUS_DELETED]),
            $this->person('group', ['type' => Actor::TYPE_GROUP]),
            $this->person('feed', ['type' => Actor::TYPE_FEED]),
            $this->person('technical', ['type' => Actor::TYPE_APPLICATION, 'is_local' => true]),
        ];
        $visible = $this->person('visible', ['id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff']);
        foreach (array_merge($hidden, [$visible]) as $member) {
            $this->follow($owner, $member);
        }
        $this->cacheMember($owner, 'following', $this->person('ignoredlocalcache'));
        $this->get($this->url($owner, 'following').'?limit=1')->assertOk()
            ->assertJsonCount(1)->assertJsonPath('0.personId', $visible->id)->assertHeaderMissing('Link');
    }

    public function test_default_page_size_and_maximum_bound_keep_lists_small(): void
    {
        $owner = $this->person('many');
        foreach (range(1, 25) as $i) {
            $this->follow($owner, $this->person('member'.$i));
        }
        $this->get($this->url($owner, 'following'))->assertOk()->assertJsonCount(20)->assertHeader('Link');
        $this->get($this->url($owner, 'following').'?limit=100')->assertOk()->assertJsonCount(25)->assertHeaderMissing('Link');
    }

    public function test_invalid_query_parameters_return_validation_problem_details(): void
    {
        $owner = $this->person('validation');
        foreach ([['limit', '0'], ['limit', '101'], ['limit[]', '2'], ['after', 'invalid'], ['direction', 'ASC'], ['sort', 'personName']] as [$name, $value]) {
            $this->get($this->url($owner, 'following').'?'.$name.'='.$value)->assertStatus(422)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('status', 422)->assertJsonStructure(['errors' => [rtrim($name, '[]')]]);
        }
    }

    public function test_etag_includes_navigation_and_checks_owner_availability_before_304(): void
    {
        $owner = $this->person('cacheowner');
        $members = collect(range(1, 3))->map(fn (int $i) => $this->person('cache'.$i, [
            'id' => sprintf('01900000-0000-7000-8000-%012d', $i),
        ]));
        $this->follow($owner, $members[0]);
        $this->follow($owner, $members[1]);
        $url = $this->url($owner, 'following').'?limit=2';
        $first = $this->get($url)->assertOk()->assertHeaderMissing('Link');
        $etag = $first->headers->get('ETag');
        $this->withHeader('If-None-Match', $etag)->get($url)->assertStatus(304);
        $this->follow($owner, $members[2]);
        $changed = $this->withHeader('If-None-Match', $etag)->get($url)->assertOk()->assertHeader('Link');
        $this->assertSame($first->json(), $changed->json());
        $this->assertNotSame($etag, $changed->headers->get('ETag'));
        $this->withHeader('If-None-Match', $changed->headers->get('ETag'))->get($url)->assertStatus(304)->assertHeader('Link');
        $members[0]->update(['name' => 'Changed']);
        $this->get($url)->assertOk()->assertJsonPath('0.personName', 'Changed');
        $owner->update(['status' => Actor::STATUS_DELETED]);
        $this->get($url)->assertNotFound();
    }

    public function test_missing_or_unavailable_owners_return_404_and_empty_lists_are_arrays(): void
    {
        $empty = $this->person('empty');
        foreach (['followers', 'following'] as $collection) {
            $this->get($this->url($empty, $collection))->assertOk()->assertExactJson([])->assertHeaderMissing('Link');
            foreach (['invalid', (string) Str::uuid(), $this->person('group'.$collection, ['type' => Actor::TYPE_GROUP])->id] as $id) {
                $this->get('/rest/v1/persons/'.$id.'/'.$collection)->assertNotFound()->assertJsonPath('status', 404);
            }
        }
    }
}
