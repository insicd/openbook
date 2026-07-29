<?php

namespace Tests\Feature;

use App\Infrastructure\Database\TransientConnectionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use PDOException;
use Tests\TestCase;

/**
 * Su certi hosting condivisi (Hostinger) un burst di nuove connessioni MySQL
 * produce `SQLSTATE[HY000] [2002] Operation not permitted`. L'utente non deve
 * vedere lo stack SQL: al primo errore GET riproviamo con un redirect, al
 * secondo mostriamo una pagina 503 comprensibile.
 */
class TransientDatabaseConnectionTest extends TestCase
{
    public function test_a_transient_connection_error_on_get_redirects_once_automatically(): void
    {
        $handler = app(TransientConnectionHandler::class);
        $this->assertTrue($handler->isTransient($this->makeConnectionException()));

        $request = Request::create('/cerca?q=qualcosa', 'GET');
        $request->setLaravelSession($this->app['session']->driver());

        $result = $handler->handle($request);

        $this->assertTrue($result->isRedirect());
        $this->assertSame('http://localhost/cerca?q=qualcosa', $result->headers->get('Location'));
        $this->assertTrue(session()->has(TransientConnectionHandler::RETRY_FLASH_KEY));
    }

    public function test_a_second_transient_connection_error_shows_a_friendly_page(): void
    {
        $handler = app(TransientConnectionHandler::class);

        $request = Request::create('/cerca?q=qualcosa', 'GET');
        $request->setLaravelSession($this->app['session']->driver());
        $request->session()->flash(TransientConnectionHandler::RETRY_FLASH_KEY, true);

        $result = $handler->handle($request);

        $this->assertSame(503, $result->getStatusCode());
        $this->assertStringContainsString(__('openbook.errors.database_busy_title'), $result->getContent());
        $this->assertStringNotContainsString('SQLSTATE', $result->getContent());
        $this->assertStringNotContainsString('Operation not permitted', $result->getContent());
    }

    public function test_a_post_request_does_not_auto_retry_to_avoid_duplicate_actions(): void
    {
        $handler = app(TransientConnectionHandler::class);

        $request = Request::create('/pubblica', 'POST');
        $request->setLaravelSession($this->app['session']->driver());

        $result = $handler->handle($request);

        $this->assertSame(503, $result->getStatusCode());
        $this->assertFalse($result->isRedirect());
    }

    public function test_unrelated_database_errors_are_not_treated_as_transient(): void
    {
        $handler = app(TransientConnectionHandler::class);

        $exception = new QueryException(
            'mysql',
            'select 1',
            [],
            new PDOException('SQLSTATE[42S02]: Base table or view not found: table missing', 0),
        );

        $this->assertFalse($handler->isTransient($exception));
    }

    private function makeConnectionException(): QueryException
    {
        $pdo = new PDOException(
            'SQLSTATE[HY000] [2002] Operation not permitted',
            2002,
        );
        $pdo->errorInfo = ['HY000', 2002, 'Operation not permitted'];

        return new QueryException(
            'mysql',
            'select * from `users` where `id` = ? limit 1',
            ['019fa8db-875f-7220-aa73-8f4534513b49'],
            $pdo,
        );
    }
}
