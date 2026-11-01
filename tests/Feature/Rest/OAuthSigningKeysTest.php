<?php

namespace Tests\Feature\Rest;

use App\Infrastructure\Auth\OAuthSigningKeys;
use App\Infrastructure\Database\SystemSetting;
use App\Infrastructure\Security\KeyPair;
use App\Infrastructure\Security\RsaKeyPairGenerator;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\ResourceServer;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class OAuthSigningKeysTest extends TestCase
{
    use RefreshDatabase;

    private static ?KeyPair $fixtureKeys = null;

    private function pair(): KeyPair
    {
        return self::$fixtureKeys ??= app(RsaKeyPairGenerator::class)->generate(2048);
    }

    public function test_first_use_generates_an_encrypted_reusable_signing_pair(): void
    {
        $keys = app(OAuthSigningKeys::class)->getOrCreate();
        $raw = SystemSetting::query()->where('key', OAuthSigningKeys::SETTING_KEY)->sole()->value;
        $this->assertStringNotContainsString('PRIVATE KEY', $raw);
        $this->assertStringNotContainsString($keys->privateKey, $raw);
        $this->assertSame(json_encode($keys, JSON_THROW_ON_ERROR), Crypt::decryptString($raw));
        $this->assertSame(4096, openssl_pkey_get_details(openssl_pkey_get_private($keys->privateKey))['bits']);
        openssl_sign('test payload', $signature, $keys->privateKey, OPENSSL_ALGO_SHA256);
        $this->assertSame(1, openssl_verify('test payload', $signature, $keys->publicKey, OPENSSL_ALGO_SHA256));
        $reloaded = (new OAuthSigningKeys(app(RsaKeyPairGenerator::class)))->getOrCreate();
        $this->assertSame($keys->publicKey, $reloaded->publicKey);
        $this->assertSame($keys->privateKey, $reloaded->privateKey);
        $this->assertSame($raw, SystemSetting::query()->where('key', OAuthSigningKeys::SETTING_KEY)->value('value'));
        $this->assertSame(1, SystemSetting::query()->where('key', OAuthSigningKeys::SETTING_KEY)->count());
    }

    public function test_existing_database_keys_are_used_without_rotation(): void
    {
        $pair = $this->pair();
        SystemSetting::put(OAuthSigningKeys::SETTING_KEY, json_encode($pair, JSON_THROW_ON_ERROR));
        $raw = SystemSetting::query()->where('key', OAuthSigningKeys::SETTING_KEY)->value('value');
        $keys = app(OAuthSigningKeys::class)->getOrCreate();
        $this->assertSame($pair->privateKey, $keys->privateKey);
        $this->assertSame($pair->publicKey, $keys->publicKey);
        $this->assertSame($raw, SystemSetting::query()->where('key', OAuthSigningKeys::SETTING_KEY)->value('value'));
    }

    public function test_another_initializer_wins_atomically_without_mixing_the_pair(): void
    {
        $winner = $this->pair();
        $inserted = false;
        DB::connection()->beforeExecuting(function ($query, $bindings) use ($winner, &$inserted): void {
            if (! $inserted && str_contains(strtolower($query), 'insert') && in_array(OAuthSigningKeys::SETTING_KEY, $bindings, true)) {
                $inserted = true;
                SystemSetting::put(OAuthSigningKeys::SETTING_KEY, json_encode($winner, JSON_THROW_ON_ERROR));
            }
        });
        $keys = app(OAuthSigningKeys::class)->getOrCreate();
        $this->assertTrue($inserted);
        $this->assertSame($winner->privateKey, $keys->privateKey);
        $this->assertSame($winner->publicKey, $keys->publicKey);
        $this->assertSame(1, SystemSetting::query()->where('key', OAuthSigningKeys::SETTING_KEY)->count());
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_existing_values_fail_without_being_replaced(?string $value, bool $encrypted): void
    {
        if ($encrypted) {
            SystemSetting::put(OAuthSigningKeys::SETTING_KEY, $value);
        } else {
            SystemSetting::query()->create(['key' => OAuthSigningKeys::SETTING_KEY, 'value' => $value]);
        }
        $raw = SystemSetting::query()->where('key', OAuthSigningKeys::SETTING_KEY)->value('value');
        try {
            app(OAuthSigningKeys::class)->getOrCreate();
            $this->fail('Invalid signing keys must not be accepted or regenerated.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('OAuth signing keys', $exception->getMessage());
        }
        $this->assertSame($raw, SystemSetting::query()->where('key', OAuthSigningKeys::SETTING_KEY)->value('value'));
    }

    public static function invalidValues(): array
    {
        return [
            'unreadable' => ['not encrypted', false],
            'null' => [null, false],
            'malformed JSON' => ['{broken', true],
            'invalid structure' => ['[]', true],
            'invalid PEM' => ['{"publicKey":"invalid","privateKey":"invalid"}', true],
        ];
    }

    public function test_mismatched_keys_and_a_wrong_app_key_do_not_trigger_regeneration(): void
    {
        $other = app(RsaKeyPairGenerator::class)->generate(2048);
        $value = json_encode(['publicKey' => $other->publicKey, 'privateKey' => $this->pair()->privateKey], JSON_THROW_ON_ERROR);
        SystemSetting::put(OAuthSigningKeys::SETTING_KEY, $value);
        $raw = SystemSetting::query()->where('key', OAuthSigningKeys::SETTING_KEY)->value('value');
        try {
            app(OAuthSigningKeys::class)->getOrCreate();
            $this->fail('Mismatched keys were accepted.');
        } catch (RuntimeException) {
            $this->assertSame($raw, SystemSetting::query()->where('key', OAuthSigningKeys::SETTING_KEY)->value('value'));
        }
        $encrypted = (new Encrypter(random_bytes(32), 'AES-256-CBC'))->encryptString(json_encode($this->pair(), JSON_THROW_ON_ERROR));
        SystemSetting::query()->where('key', OAuthSigningKeys::SETTING_KEY)->update(['value' => $encrypted]);
        $this->expectException(RuntimeException::class);
        app(OAuthSigningKeys::class)->getOrCreate();
    }

    public function test_boot_and_public_metadata_do_not_initialize_keys_but_passport_servers_do(): void
    {
        $this->assertNull(config('passport.private_key'));
        app(OAuthSigningKeys::class);
        $this->getJson('/.well-known/oauth-authorization-server')->assertOk();
        $this->getJson('/rest/v1/me')->assertUnauthorized();
        $this->assertDatabaseMissing('system_settings', ['key' => OAuthSigningKeys::SETTING_KEY]);
        SystemSetting::put(OAuthSigningKeys::SETTING_KEY, json_encode($this->pair(), JSON_THROW_ON_ERROR));
        app(ResourceServer::class);
        $this->assertSame($this->pair()->publicKey, config('passport.public_key'));
        app(AuthorizationServer::class);
        $this->assertSame($this->pair()->privateKey, config('passport.private_key'));
    }

    public function test_uninstalled_oauth_does_not_initialize_signing_keys(): void
    {
        $this->simulateNotInstalled();
        $this->postJson('/oauth/token', ['grant_type' => 'authorization_code'])->assertStatus(503);
        $this->assertDatabaseMissing('system_settings', ['key' => OAuthSigningKeys::SETTING_KEY]);
    }
}
