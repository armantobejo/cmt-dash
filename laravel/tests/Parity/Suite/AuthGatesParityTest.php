<?php

namespace Tests\Parity\Suite;

use App\Support\CmtAuth;
use App\Support\CmtUser;

/**
 * Auth-gate parity: which role may reach which surface. The expected matrix is
 * the one in docs/parity-checklist.yaml; the snapshot pins the actual codes so
 * a widened or narrowed gate becomes a diff, never a silent regression.
 */
class AuthGatesParityTest extends ParitySuiteTestCase
{
    public const PROTECTED_ROUTES = [
        '/' => 'auth',
        '/shipments' => 'auth',
        '/invoices' => 'auth',
        '/sales' => 'auth',
        '/ledger' => 'management',
        '/users' => 'admin',
        '/data-quality' => 'superadmin',
    ];

    public function test_guest_is_redirected_from_every_protected_route(): void
    {
        $statuses = [];
        foreach (array_keys(self::PROTECTED_ROUTES) as $path) {
            $statuses[$path] = $this->guest()->get($path, ['Accept' => 'text/html'])->getStatusCode();
        }

        $this->assertSame(array_fill_keys(array_keys($statuses), 303), $statuses, 'guests get a 303 to /login');
        $this->snapshotCase('auth-gates', 'guest_statuses', $statuses);
    }

    public function test_role_matrix_matches_baseline(): void
    {
        $matrix = [];

        // One session per role (reused across routes) — the cookie persists on
        // the test case, so this is 5 logins, not 35.
        foreach (array_keys(self::ROLE_USERS) as $role) {
            $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class)
                ->withUnencryptedCookie('cmt_session', $this->tokenFor($role));

            foreach (array_keys(self::PROTECTED_ROUTES) as $path) {
                $matrix[$path][$role] = $this->get($path, ['Accept' => 'text/html'])->getStatusCode();
            }
        }
        foreach (self::PROTECTED_ROUTES as $path => $minRole) {
            $matrix[$path]['_min_role'] = $minRole;
        }

        // Every "all authenticated roles" surface is open to all five roles.
        foreach (['/', '/shipments', '/invoices', '/sales'] as $path) {
            foreach (array_keys(self::ROLE_USERS) as $role) {
                $this->assertSame(200, $matrix[$path][$role], "{$role} must reach {$path}");
            }
        }

        $this->snapshotCase('auth-gates', 'role_matrix', $matrix);
    }

    public function test_management_surface_denies_below_role(): void
    {
        $this->asRole('staff')->getJson('/ledger')->assertStatus(403);
        $this->asRole('customer')->getJson('/ledger')->assertStatus(403);
        $this->asRole('management')->get('/ledger')->assertOk();
        $this->asRole('admin')->get('/ledger')->assertOk();
        $this->asRole('superadmin')->get('/ledger')->assertOk();
    }

    public function test_admin_surface_denies_below_role(): void
    {
        $this->asRole('management')->getJson('/users')->assertStatus(403);
        $this->asRole('staff')->getJson('/users')->assertStatus(403);
        $this->asRole('customer')->getJson('/users')->assertStatus(403);
        $this->asRole('admin')->get('/users')->assertOk();
        $this->asRole('superadmin')->get('/users')->assertOk();
    }

    public function test_superadmin_surface_denies_everyone_else(): void
    {
        foreach (['customer', 'staff', 'management', 'admin'] as $role) {
            $this->asRole($role)->getJson('/data-quality')->assertStatus(403);
        }
        $this->asRole('superadmin')->get('/data-quality')->assertOk();
    }

    public function test_sync_post_is_management_only(): void
    {
        $this->asRole('staff')->postJson('/sync')->assertStatus(403);
        $this->asRole('customer')->postJson('/sync')->assertStatus(403);
    }

    public function test_api_summary_gate_is_login_not_role(): void
    {
        // Anonymous first: a later asRole() leaves its cookie on the test case.
        $this->guest()->getJson('/api/summary')->assertStatus(401);

        foreach (array_keys(self::ROLE_USERS) as $role) {
            $this->asRole($role)->getJson('/api/summary')->assertOk();
        }
    }

    public function test_logout_invalidates_the_server_side_session(): void
    {
        $token = $this->throwawayToken('staff');

        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class)
            ->withUnencryptedCookie('cmt_session', $token)
            ->post('/logout')
            ->assertStatus(302);

        $this->assertNull($this->auth()->userForToken($token));

        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class)
            ->withUnencryptedCookie('cmt_session', $token)
            ->get('/')
            ->assertStatus(303);
    }

    public function test_expired_session_is_rejected_and_pruned(): void
    {
        $token = $this->throwawayToken('staff');
        $conn = $this->app->make(\App\Support\CompanyConnectionFactory::class)->authConnection();
        $conn->table('sessions')->where('token', $token)->update(['expires_at' => '2000-01-01T00:00:00']);

        $this->assertNull($this->auth()->userForToken($token));
        $this->assertNull($conn->table('sessions')->where('token', $token)->first(), 'expired row is pruned');
    }

    public function test_role_levels_and_user_dto(): void
    {
        $this->assertSame([
            'superadmin' => 5, 'admin' => 4, 'management' => 3, 'staff' => 2, 'customer' => 1,
        ], CmtAuth::ROLE_LEVEL);

        $this->assertSame(0, (new CmtUser())->level(), 'anonymous ranks below every role');
        $this->assertFalse((new CmtUser())->authenticated);
    }
}
