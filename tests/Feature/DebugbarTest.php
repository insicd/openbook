<?php

namespace Tests\Feature;

use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Fruitcake\LaravelDebugbar\ServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use ReflectionProperty;
use Tests\TestCase;

class DebugbarTest extends TestCase
{
    use RefreshDatabase;

    public function test_configuration_enables_debugbar_only_for_local_debug_mode(): void
    {
        $property = new ReflectionProperty(Env::class, 'repository');
        $original = $property->getValue();
        $repository = RepositoryBuilder::createWithNoAdapters()->addAdapter(ArrayAdapter::class)->make();
        $property->setValue(null, $repository);
        try {
            foreach ([
                ['local', 'true', null, true],
                ['local', 'true', 'false', false],
                ['local', 'false', 'true', false],
                ['production', 'true', 'true', false],
                ['staging', 'true', 'true', false],
                ['testing', 'true', 'true', false],
            ] as [$environment, $debug, $enabled, $expected]) {
                $repository->set('APP_ENV', $environment);
                $repository->set('APP_DEBUG', $debug);
                $enabled === null ? $repository->clear('DEBUGBAR_ENABLED') : $repository->set('DEBUGBAR_ENABLED', $enabled);
                $configuration = require config_path('debugbar.php');
                $this->assertSame($expected, $configuration['enabled']);
            }
        } finally {
            $property->setValue(null, $original);
        }
    }

    public function test_local_html_contains_the_bar_and_queries_while_json_stays_json(): void
    {
        $this->app->instance('env', 'local');
        config(['app.debug' => true, 'debugbar.enabled' => true, 'debugbar.storage.enabled' => false]);
        (new ReflectionProperty($this->app, 'isRunningInConsole'))->setValue($this->app, false);
        $this->app->register(ServiceProvider::class, force: true);
        Route::get('/debugbar-probe', function () {
            DB::select('select 42 as debugbar_probe');

            return response('<html><head></head><body>Debugbar probe</body></html>');
        });
        Route::get('/debugbar-json-probe', fn () => response()->json(['unchanged' => true]));
        $this->app['router']->getRoutes()->refreshNameLookups();

        $this->get('/debugbar-probe')->assertOk()->assertSee('phpdebugbar', false)->assertSee('debugbar_probe', false);
        $this->getJson('/debugbar-json-probe')->assertOk()->assertExactJson(['unchanged' => true]);
    }

    public function test_production_with_debug_enabled_has_no_bar_or_debugbar_endpoints(): void
    {
        $this->app->instance('env', 'production');
        config(['app.debug' => true]);
        $this->app->register(ServiceProvider::class, force: true);
        Route::get('/debugbar-probe', fn () => response('<html><head></head><body>Production probe</body></html>'));

        $this->get('/debugbar-probe')->assertOk()->assertDontSee('phpdebugbar', false);
        $this->get('/_debugbar/open')->assertNotFound();
    }
}
