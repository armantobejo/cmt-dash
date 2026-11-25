<?php

namespace Tests\Parity\Suite;

use App\Support\CmtAuth;
use App\Support\CmtUser;
use App\Support\Panel\CmtPanelUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/**
 * Policy parity: this app has no Laravel Gate/Policy classes — authorization is
 * the role-level table (`CmtAuth::ROLE_LEVEL`) enforced by the `cmt.role`
 * middleware alias, plus the Filament panel's access check. Those two are the
 * policy layer, so both are pinned here.
 */
class PoliciesParityTest extends ParitySuiteTestCase
{
    /** @return array<string, string> route uri => minimum role required by middleware */
    private function routeMinimumRoles(): array
    {
        $map = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            if (preg_match('#^(admin|filament|livewire|up|storage)#', $uri)) {
                continue;
            }
            // Raw (unresolved) middleware strings: gatherMiddleware() resolves
            // aliases to class names and loses the cmt.role:<level> parameter.
            foreach ($route->middleware() as $middleware) {
                if (! is_string($middleware)) {
                    continue;
                }
                if (str_starts_with($middleware, 'cmt.role:')) {
                    $map[$route->methods()[0] . ' /' . ltrim($uri, '/')] = substr($middleware, strlen('cmt.role:'));
                } elseif ($middleware === 'cmt.auth') {
                    $map[$route->methods()[0] . ' /' . ltrim($uri, '/')] = 'auth';
                }
            }
        }
        ksort($map, SORT_STRING);

        return $map;
    }

    public function test_role_level_table_is_stable(): void
    {
        $this->snapshotCase('policies', 'role_levels', CmtAuth::ROLE_LEVEL);
    }

    public function test_route_to_minimum_role_map_is_stable(): void
    {
        $map = $this->routeMinimumRoles();

        $this->assertSame('management', $map['GET /ledger'] ?? null);
        $this->assertSame('admin', $map['GET /users'] ?? null);
        $this->assertSame('superadmin', $map['GET /data-quality'] ?? null);
        $this->assertSame('auth', $map['GET /'] ?? null);

        $this->snapshotCase('policies', 'route_minimum_roles', $map);
    }

    public function test_user_level_mapping(): void
    {
        $levels = [];
        foreach (array_keys(self::ROLE_USERS) as $role) {
            $levels[$role] = (new CmtUser(role: $role))->level();
        }
        $levels['anonymous'] = (new CmtUser())->level();

        $this->assertSame(0, $levels['anonymous']);
        $this->assertSame(5, $levels['superadmin']);
        $this->snapshotCase('policies', 'user_levels', $levels);
    }

    public function test_panel_guard_denies_guests_and_admits_sessions(): void
    {
        $this->assertNull(Auth::guard('cmt')->user(), 'no cmt_session cookie -> no panel user');
        $this->assertTrue(Auth::guard('cmt')->guest());

        $token = $this->tokenFor('management');
        $request = \Illuminate\Http\Request::create('/admin');
        $request->cookies->set('cmt_session', $token);
        $this->app->instance('request', $request);
        Auth::forgetGuards();

        $panelUser = Auth::guard('cmt')->user();
        $this->assertInstanceOf(CmtPanelUser::class, $panelUser);
        $this->assertTrue(Auth::guard('cmt')->check());
        $this->assertSame('manager1', $panelUser->cmt->username);
    }

    public function test_panel_access_requires_an_authenticated_session(): void
    {
        $panel = \Filament\Facades\Filament::getPanel('admin');

        $anonymous = new CmtPanelUser(CmtUser::anonymous());
        $authenticated = new CmtPanelUser(new CmtUser(
            username: 'staff1', displayName: 'Staff1', role: 'staff', authenticated: true,
        ));

        $this->assertFalse($anonymous->canAccessPanel($panel));
        $this->assertTrue($authenticated->canAccessPanel($panel));

        $this->snapshotCase('policies', 'panel_access', [
            'anonymous' => $anonymous->canAccessPanel($panel),
            'authenticated' => $authenticated->canAccessPanel($panel),
            'panel_id' => $panel->getId(),
        ]);
    }

    public function test_denial_body_shape_across_surfaces(): void
    {
        $bodies = [];
        foreach ([['staff', '/ledger'], ['customer', '/users'], ['admin', '/data-quality']] as [$role, $path]) {
            $bodies[$path] = $this->asRole($role)->getJson($path)->json();
        }

        // One key, always: FastAPI's {"detail": ...} envelope.
        foreach ($bodies as $path => $body) {
            $this->assertSame(['detail'], array_keys($body), "{$path} must use the detail envelope");
        }
        $this->assertSame('Requires role >= management', $bodies['/ledger']['detail'] ?? null);
        $this->assertSame('Requires role >= admin', $bodies['/users']['detail'] ?? null);
        $this->assertSame('Requires role >= superadmin', $bodies['/data-quality']['detail'] ?? null);

        $this->snapshotCase('policies', 'denial_bodies', $bodies);
    }

    public function test_denied_request_never_reaches_the_controller(): void
    {
        // /data-quality queries the DBF-backed schema; a 403 (not 500) proves
        // the gate short-circuits before controller work.
        $this->asRole('management')->getJson('/data-quality')->assertStatus(403);
        $this->asRole('superadmin')->get('/data-quality')->assertOk();
    }
}
