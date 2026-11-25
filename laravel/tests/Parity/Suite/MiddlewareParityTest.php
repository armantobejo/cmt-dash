<?php

namespace Tests\Parity\Suite;

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\CmtLocale;
use App\Http\Middleware\CmtSession;
use App\Http\Middleware\LogRequest;
use App\Http\Middleware\ResolveCompanyDb;
use App\Support\CmtUser;
use App\Support\CompanyDbManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Middleware parity: the app-level stack that implements Python's
 * RequestLogMiddleware / company-DB / session / role gates.
 */
class MiddlewareParityTest extends ParitySuiteTestCase
{
    public function test_cmt_middleware_aliases(): void
    {
        $router = $this->app->make('router');
        $aliases = array_filter(
            $router->getMiddleware(),
            fn (string $key) => str_starts_with($key, 'cmt.'),
            ARRAY_FILTER_USE_KEY
        );
        ksort($aliases);

        $this->assertArrayHasKey('cmt.auth', $aliases);
        $this->assertArrayHasKey('cmt.role', $aliases);
        $this->snapshotCase('middleware', 'aliases', $aliases);
    }

    public function test_web_group_stack_contains_app_middleware(): void
    {
        $groups = $this->app->make('router')->getMiddlewareGroups();
        $appMiddleware = array_values(array_filter(
            $groups['web'] ?? [],
            fn (string $class) => str_starts_with($class, 'App\\')
        ));

        $this->assertContains(ResolveCompanyDb::class, $appMiddleware);
        $this->assertContains(CmtSession::class, $appMiddleware);
        $this->assertContains(AddSecurityHeaders::class, $appMiddleware);

        $this->snapshotCase('middleware', 'web_group_app_middleware', $appMiddleware);
    }

    public function test_auth_gate_redirects_guest_with_303(): void
    {
        $this->guest()->get('/', ['Accept' => 'text/html'])
            ->assertStatus(303)
            ->assertRedirect('/login');

        $this->snapshotCase('middleware', 'guest_redirect', ['status' => 303, 'location' => '/login']);
    }

    public function test_role_gate_returns_fastapi_403_body(): void
    {
        $response = $this->asRole('customer')->getJson('/users');

        $response->assertStatus(403)->assertExactJson(['detail' => 'Requires role >= admin']);

        $this->snapshotCase('middleware', 'role_gate_body', $response->json());
    }

    public function test_session_middleware_resolves_cookie_to_user(): void
    {
        $this->asRole('management')->get('/api/summary')->assertOk();

        $user = $this->app->make(CmtUser::class);
        $this->assertTrue($user->authenticated);
        $this->assertSame('manager1', $user->username);
        $this->assertSame('management', $user->role);

        $this->snapshotCase('middleware', 'session_resolution', [
            'username' => $user->username,
            'role' => $user->role,
            'authenticated' => $user->authenticated,
        ]);
    }

    public function test_session_middleware_falls_back_to_anonymous(): void
    {
        $this->guest()
            ->withUnencryptedCookie('cmt_session', 'not-a-real-token')
            ->get('/api/summary')
            ->assertStatus(401);

        $this->assertFalse($this->app->make(CmtUser::class)->authenticated);
    }

    public function test_security_headers_are_applied(): void
    {
        $response = $this->guest()->get('/login');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->assertStringContainsString(
            "default-src 'self'",
            (string) $response->headers->get('Content-Security-Policy')
        );

        $this->snapshotCase('middleware', 'security_headers', [
            'x_content_type_options' => $response->headers->get('X-Content-Type-Options'),
            'x_frame_options' => $response->headers->get('X-Frame-Options'),
            'csp_prefix' => substr((string) $response->headers->get('Content-Security-Policy'), 0, 24),
        ]);
    }

    public function test_company_db_middleware_resolves_valid_cookie(): void
    {
        $manager = $this->app->make(CompanyDbManager::class);
        $middleware = new ResolveCompanyDb($manager);
        $request = Request::create('/');
        $request->cookies->set('cmt_db', 'CMT');

        $middleware->handle($request, fn () => response('ok'));

        $this->assertSame('CMT', $manager->active());
        $this->snapshotCase('middleware', 'company_db_resolution', ['active' => $manager->active()]);
    }

    public function test_company_db_middleware_falls_back_on_unknown_name(): void
    {
        $manager = $this->app->make(CompanyDbManager::class);
        $middleware = new ResolveCompanyDb($manager);
        $request = Request::create('/');
        $request->cookies->set('cmt_db', 'NOPE');

        $middleware->handle($request, fn () => response('ok'));

        $this->assertSame((string) config('cmt.default_db'), $manager->active());
    }

    public function test_locale_middleware_maps_cmt_lang_cookie(): void
    {
        $middleware = $this->app->make(CmtLocale::class);
        $mapped = [];

        foreach (['id', 'en', 'zh', 'xx'] as $cookie) {
            $request = Request::create('/');
            $request->cookies->set('cmt_lang', $cookie);
            $middleware->handle($request, fn () => response('ok'));
            $mapped[$cookie] = $this->app->getLocale();
        }

        $this->assertSame('zh_CN', $mapped['zh']);
        $this->assertSame('id', $mapped['xx'], 'unsupported cookie falls back to the default lang (CMT_LANG, default id)');
        $this->snapshotCase('middleware', 'locale_mapping', $mapped);
    }

    public function test_request_log_middleware_tags_the_request(): void
    {
        $middleware = new LogRequest();
        $request = Request::create('/api/health');

        $middleware->handle($request, fn () => response('ok'));

        $id = $request->attributes->get('cmt_request_id');
        $this->assertIsString($id);
        $this->assertSame(16, strlen($id));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $id);
        $this->assertIsFloat($request->attributes->get('cmt_request_start'));
    }
}
