<?php

namespace Tests\Feature\Federation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAccounts;
use Tests\TestCase;

class NodeInfoTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_the_discovery_document_points_to_the_2_1_schema(): void
    {
        $response = $this->get('/.well-known/nodeinfo');

        $response->assertOk();
        $response->assertJson([
            'links' => [
                [
                    'rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.1',
                    'href' => url('/nodeinfo/2.1'),
                ],
            ],
        ]);
    }

    public function test_the_nodeinfo_document_reports_software_and_usage(): void
    {
        $this->createFullAccount('nodo1');
        $this->createFullAccount('nodo2');

        $response = $this->get('/nodeinfo/2.1');

        $response->assertOk();
        $response->assertJsonPath('version', '2.1');
        $response->assertJsonPath('software.name', 'openbook');
        $response->assertJsonPath('software.version', config('openbook.version'));
        $response->assertJsonPath('software.homepage', 'https://about.openb.app');
        $response->assertJsonPath('protocols', ['activitypub']);
        $response->assertJsonPath('usage.users.total', 2);
    }

    public function test_the_outgoing_user_agent_reports_the_real_software_version(): void
    {
        $this->assertSame(
            sprintf('Openbook/%s (+%s)', config('openbook.version'), config('app.url')),
            config('openbook.federation.user_agent'),
        );
    }

    public function test_federation_discovery_endpoints_allow_cross_origin_reads(): void
    {
        $response = $this->get('/.well-known/nodeinfo', ['Origin' => 'https://example.org']);

        $response->assertOk();
        $response->assertHeader('Access-Control-Allow-Origin', '*');
    }
}
