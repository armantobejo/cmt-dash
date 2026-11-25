<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveCompanyDb;
use App\Support\CompanyConnectionFactory;
use App\Support\CompanyDbManager;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P0 gate: SQLite connection + multi-DB manager + DB switcher middleware.
 *
 * Every test builds its own isolated data dir (CMT.db + CME.db) and rebinds
 * the scoped manager, so company switching is proven end-to-end without
 * touching the shared repo data/.
 *
 * The manager-level tests call CompanyDbManager::resolve() directly with the
 * same cookie value ResolveCompanyDb extracts per request — the former HTTP
 * vehicle (/p0-status) was removed in the P4 RBAC sweep: the Python app has
 * no equivalent route, and DB topology must not be a public surface.
 */
class CompanyDbSwitcherTest extends TestCase
{
    private string $dataDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolated data dir per test, with two company DBs. Relative paths in
        // config/cmt.php resolve against base_path, so rebind consistently.
        $dir = storage_path('framework/testing/'.Str::random(10));
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $this->dataDir = $dir;

        $this->rebind();

        config(['cmt.data_dir' => $dir]);
    }

    /** Fresh scoped instances wired to this test's data dir. */
    private function rebind(): void
    {
        $manager = new CompanyDbManager($this->dataDir, (string) config('cmt.default_db', 'CMT'));
        $factory = new CompanyConnectionFactory(
            resolver: $this->app->make(\Illuminate\Database\ConnectionResolverInterface::class),
            dataDir: $this->dataDir,
        );
        $this->app->instance(CompanyDbManager::class, $manager);
        $this->app->instance(CompanyConnectionFactory::class, $factory);
    }

    // ------------------------------------------------------------- resolver

    public function test_defaults_to_cmt_db_without_cookie(): void
    {
        $this->makeCompanyDb('CMT', 'default');
        $this->makeCompanyDb('CME', 'other');
        $manager = $this->app->make(CompanyDbManager::class);

        $this->assertSame('CMT', $manager->resolve(null));
        $this->assertSame('CMT', $manager->active());
        $this->assertSame(['CME', 'CMT'], $manager->available());
    }

    public function test_cookie_selects_company_db(): void
    {
        $this->makeCompanyDb('CMT', 'default');
        $this->makeCompanyDb('CME', 'other');
        $manager = $this->app->make(CompanyDbManager::class);

        // cmt_db is unencrypted (byte-parity with Python) — the middleware
        // reads the raw value and hands it to resolve().
        $this->assertSame('CME', $manager->resolve('CME'));
        $this->assertSame('CME', $manager->active());
    }

    public function test_unknown_cookie_value_falls_back_silently(): void
    {
        $this->makeCompanyDb('CMT', 'default');
        $manager = $this->app->make(CompanyDbManager::class);

        $this->assertSame('CMT', $manager->resolve('NOPE'));
        $this->assertSame('CMT', $manager->active());
    }

    public function test_cookie_is_path_traversal_safe(): void
    {
        $this->makeCompanyDb('CMT', 'default');
        $manager = $this->app->make(CompanyDbManager::class);

        // A crafted cookie must never escape the data dir via the connection
        // path. The value below is what the middleware would receive after
        // the framework decodes a `..%2F..%2Fcomposer` cookie; the manager's
        // regex check rejects it before any FS access.
        $this->assertSame('CMT', $manager->resolve('../../composer'));
        $this->assertSame('CMT', $manager->active());
    }

    public function test_connection_reads_the_right_file(): void
    {
        $this->makeCompanyDb('CMT', 'default');
        $this->makeCompanyDb('CME', 'other');

        // Middleware-resolved selection (as ResolveCompanyDb does per
        // request)...
        $manager = $this->app->make(CompanyDbManager::class);
        $manager->resolve('CME');
        // ...and the factory's active connection reads that company's file.
        $factory = $this->app->make(CompanyConnectionFactory::class);

        $this->assertSame(
            'other',
            $factory->query($manager, 'marker')->value('v'),
            'active connection should point at the cookie-selected DB'
        );
    }

    public function test_on_switches_connections_explicitly(): void
    {
        $this->makeCompanyDb('CMT', 'default');
        $this->makeCompanyDb('CME', 'other');

        $factory = $this->app->make(CompanyConnectionFactory::class);
        $manager = $this->app->make(CompanyDbManager::class);

        $cmt = $factory->on($manager, 'CMT', fn ($c) => $c->table('marker')->value('v'));
        $cme = $factory->on($manager, 'CME', fn ($c) => $c->table('marker')->value('v'));

        $this->assertSame('default', $cmt);
        $this->assertSame('other', $cme);
    }

    public function test_manager_rejects_invalid_names_before_filesystem(): void
    {
        $this->makeCompanyDb('CMT', 'default');
        $manager = $this->app->make(CompanyDbManager::class);

        $this->assertFalse($manager->isValidName('../composer'));
        $this->assertFalse($manager->isValidName(''));
        $this->assertFalse($manager->isValidName('NOPE'));
        $this->assertTrue($manager->isValidName('CMT'));
    }

    // ------------------------------------------------- /set-db HTTP contract

    public function test_set_db_sets_cookie_and_redirects_to_referer(): void
    {
        $this->makeCompanyDb('CMT', 'default');
        $this->makeCompanyDb('CME', 'other');

        $response = $this->withHeaders(['referer' => 'http://localhost/ledger'])
            ->post('/set-db', ['db_name' => 'CME']);
        $response->assertStatus(303);
        $response->assertRedirect('http://localhost/ledger');
        $cookie = $response->getCookie('cmt_db', false);  // false = don't decrypt
        $this->assertNotNull($cookie, 'cmt_db cookie must be set');
        $this->assertSame('CME', $cookie->getValue());
    }

    public function test_set_db_unknown_name_falls_back_in_cookie(): void
    {
        $this->makeCompanyDb('CMT', 'default');

        $response = $this->post('/set-db', ['db_name' => 'NOPE']);
        $response->assertStatus(303);
        $response->assertRedirect('http://localhost');  // no referer -> /
        $cookie = $response->getCookie('cmt_db', false);
        $this->assertNotNull($cookie, 'cmt_db cookie must be set');
        $this->assertSame('CMT', $cookie->getValue());
    }

    public function test_set_db_works_without_login_or_csrf_token(): void
    {
        // KG-3 parity: the Python endpoint is public (no login, no CSRF).
        $this->makeCompanyDb('CMT', 'default');

        $this->post('/set-db', ['db_name' => 'CMT'])
            ->assertStatus(303);
    }

    public function test_cookie_is_secure_when_cmt_cookie_secure_is_on(): void
    {
        $this->makeCompanyDb('CMT', 'default');

        // Python parity: the Secure flag follows CMT_COOKIE_SECURE=1 (main.py
        // session_cookie_secure()), NOT Laravel's session.secure config.
        config(['cmt.cookie_secure' => true]);

        $response = $this->post('/set-db', ['db_name' => 'CMT']);
        $cookie = $response->getCookie('cmt_db', false);
        $this->assertNotNull($cookie, 'cmt_db cookie must be set');

        $this->assertTrue((bool) $cookie->isSecure());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
        $this->assertSame(60 * 24 * 365 * 60, $cookie->getMaxAge());  // seconds = 1 year
    }

    public function test_cookie_secure_ignores_laravel_session_secure(): void
    {
        // Pinned divergence: session.secure=true alone must NOT add Secure —
        // that flag belongs to Laravel's own session cookie, not the cmt_db
        // cookie, whose behavior is governed by CMT_COOKIE_SECURE only.
        $this->makeCompanyDb('CMT', 'default');
        config(['session.secure' => true, 'cmt.cookie_secure' => false]);

        $cookie = $this->post('/set-db', ['db_name' => 'CMT'])
            ->getCookie('cmt_db', false);

        $this->assertNotNull($cookie);
        $this->assertFalse((bool) $cookie->isSecure());
    }

    // ------------------------------------------------------------- helpers

    private function makeCompanyDb(string $name, string $marker): void
    {
        $path = $this->dataDir.DIRECTORY_SEPARATOR.$name.'.db';
        $pdo = new \PDO('sqlite:'.$path);
        $pdo->exec('CREATE TABLE IF NOT EXISTS marker (v TEXT)');
        $pdo->exec('DELETE FROM marker');
        $pdo->exec("INSERT INTO marker (v) VALUES ('{$marker}')");
    }
}
