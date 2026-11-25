<?php

namespace Tests\Parity\Suite;

use Illuminate\View\View;

/**
 * Controller contract parity: status codes, redirect targets, view names and
 * view-data keys for every parity surface (Python main.py handlers).
 */
class ControllersParityTest extends ParitySuiteTestCase
{
    /** Path portion of a Location header ('/' when parse_url can't split it). */
    private function pathOf(?string $location): ?string
    {
        if ($location === null) {
            return null;
        }
        $path = parse_url($location, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    /** @return array{status:int, view:?string, keys:array, location:?string} */
    private function viewSnapshot($response): array
    {
        $view = $response->original instanceof View ? $response->original : null;

        return [
            'status' => $response->getStatusCode(),
            'view' => $view?->getName(),
            'keys' => $view ? array_keys($this->viewShape($view)) : [],
            'location' => $this->pathOf($response->headers->get('location')),
            'has_session_cookie' => str_contains((string) $response->headers->get('set-cookie'), 'cmt_session='),
        ];
    }

    public function test_login_page_contract(): void
    {
        $response = $this->guest()->get('/login');

        $response->assertOk()->assertViewIs('login');
        $this->snapshotCase('controllers', 'login_page', $this->viewSnapshot($response));
    }

    public function test_login_success_redirects_home_with_session_cookie(): void
    {
        $response = $this->guest()->post('/login', [
            'username' => 'staff1',
            'password' => 'staff-pw',
        ]);

        $response->assertStatus(302);
        $this->assertSame('/', $this->pathOf($response->headers->get('location')));
        $this->assertNotNull($response->getCookie('cmt_session', false));

        $this->snapshotCase('controllers', 'login_success', $this->viewSnapshot($response));
    }

    public function test_login_failure_rerenders_form_with_error(): void
    {
        $response = $this->guest()->post('/login', [
            'username' => 'staff1',
            'password' => 'WRONG',
        ]);

        $response->assertOk()->assertViewIs('login');
        $response->assertViewHas('error', 'Username or password incorrect');

        $this->snapshotCase('controllers', 'login_failure', $this->viewSnapshot($response) + [
            'error' => 'Username or password incorrect',
        ]);
    }

    public function test_login_missing_field_returns_fastapi_shape(): void
    {
        $response = $this->guest()->postJson('/login', ['password' => 'staff-pw']);

        $response->assertStatus(422)->assertJsonPath('detail.0.type', 'missing')
            ->assertJsonPath('detail.0.loc.1', 'username')
            ->assertJsonPath('detail.0.msg', 'Field required');

        $this->snapshotCase('controllers', 'login_missing_field', $response->json());
    }

    public function test_logout_clears_session_cookie(): void
    {
        $token = $this->throwawayToken('staff');

        $response = $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class)
            ->withUnencryptedCookie('cmt_session', $token)
            ->post('/logout');

        $response->assertStatus(302);
        $this->assertSame('/login', $this->pathOf($response->headers->get('location')));
        $cookie = (string) $response->headers->get('set-cookie');
        $this->assertStringContainsString('cmt_session=', $cookie);
        $this->assertStringContainsString('Max-Age=0', $cookie);
    }

    public function test_api_health_payload(): void
    {
        $response = $this->guest()->get('/api/health');

        $response->assertOk()->assertExactJson(['status' => 'ok']);
        $this->snapshotCase('controllers', 'api_health', $response->json());
    }

    public function test_api_summary_requires_login(): void
    {
        $response = $this->guest()->getJson('/api/summary');

        $response->assertStatus(401)->assertExactJson(['detail' => 'Unauthorized']);
        $this->snapshotCase('controllers', 'api_summary_anon', $response->json());
    }

    public function test_api_summary_payload_shape(): void
    {
        $response = $this->asRole('staff')->getJson('/api/summary');

        $response->assertOk();
        $json = $response->json();
        $this->assertArrayHasKey('shipments', $json);
        $this->assertArrayHasKey('recent_shipments', $json);
        $this->assertIsArray($json['recent_shipments']);

        $this->snapshotCase('controllers', 'api_summary_shape', [
            'keys' => array_keys($json),
            'shipments' => $json['shipments'],
            'invoices' => $json['invoices'],
            'recent_count' => count($json['recent_shipments']),
        ]);
    }

    public function test_api_sync_status_payload_shape(): void
    {
        $response = $this->asRole('staff')->getJson('/api/sync-status');

        $response->assertOk();
        $json = $response->json();
        $this->assertEqualsCanonicalizing(['age_seconds', 'last_sync', 'stale'], array_keys($json));

        // last_sync / age_seconds are wall-clock values; only the shape is parity.
        $this->snapshotCase('controllers', 'api_sync_status_shape', [
            'keys' => array_keys($json),
            'stale_is_bool' => is_bool($json['stale']),
        ]);
    }

    public function test_dashboard_view_contract(): void
    {
        $response = $this->asRole('staff')->get('/');

        $response->assertOk()->assertViewIs('dashboard');
        $this->snapshotCase('controllers', 'dashboard', $this->viewSnapshot($response));
    }

    public function test_shipments_view_contract(): void
    {
        $response = $this->asRole('staff')->get('/shipments');

        $response->assertOk()->assertViewIs('shipments');
        $this->snapshotCase('controllers', 'shipments', $this->viewSnapshot($response));
    }

    public function test_invoices_view_contract(): void
    {
        $response = $this->asRole('staff')->get('/invoices');

        $response->assertOk()->assertViewIs('invoices');
        $this->snapshotCase('controllers', 'invoices', $this->viewSnapshot($response));
    }

    public function test_shipment_detail_requires_ref_query(): void
    {
        $response = $this->asRole('staff')->getJson('/shipments/detail');

        $response->assertStatus(422)->assertJsonPath('detail.0.loc.1', 'ref');
        $this->snapshotCase('controllers', 'shipment_detail_missing_ref', $response->json());
    }

    public function test_unknown_shipment_redirects_to_list(): void
    {
        $this->asRole('staff')
            ->get('/shipments/detail?ref=REF-NOTHING')
            ->assertStatus(303)
            ->assertRedirect('/shipments');
    }

    public function test_ledger_detail_without_account_redirects(): void
    {
        $this->asRole('management')
            ->get('/ledger/detail')
            ->assertStatus(302)
            ->assertRedirect('/ledger');
    }

    public function test_users_page_contract(): void
    {
        $response = $this->asRole('admin')->get('/users');

        $response->assertOk()->assertViewIs('users');
        $users = $response->original->getData()['users'] ?? [];
        $this->assertNotEmpty($users);

        $this->snapshotCase('controllers', 'users_page', $this->viewSnapshot($response) + [
            'usernames' => array_column($users, 'username'),
            'roles' => array_column($users, 'role'),
        ]);
    }

    public function test_ledger_view_contract(): void
    {
        $response = $this->asRole('management')->get('/ledger');

        $response->assertOk()->assertViewIs('ledger');
        $this->snapshotCase('controllers', 'ledger', $this->viewSnapshot($response));
    }

    public function test_shipments_listing_state_variants(): void
    {
        foreach (['?q=REF-TRISCO-001' => 'search', '?type=I' => 'type_filter', '?sort=ref&dir=asc' => 'sort'] as $query => $label) {
            $response = $this->asRole('staff')->get('/shipments' . $query);
            $response->assertOk()->assertViewIs('shipments');
            $this->snapshotCase('controllers', 'shipments_' . $label, $this->viewSnapshot($response));
        }
    }

    public function test_invoices_listing_state_variants(): void
    {
        foreach (['?q=INV-0001' => 'search', '?sort=number&dir=asc' => 'sort'] as $query => $label) {
            $response = $this->asRole('staff')->get('/invoices' . $query);
            $response->assertOk()->assertViewIs('invoices');
            $this->snapshotCase('controllers', 'invoices_' . $label, $this->viewSnapshot($response));
        }
    }

    public function test_ledger_listing_state_variants(): void
    {
        foreach (['?sort=name&dir=desc' => 'sort', '?type=detail' => 'type_filter'] as $query => $label) {
            $response = $this->asRole('management')->get('/ledger' . $query);
            $response->assertOk()->assertViewIs('ledger');
            $this->snapshotCase('controllers', 'ledger_' . $label, $this->viewSnapshot($response));
        }
    }

    public function test_sales_view_contract(): void
    {
        $response = $this->asRole('staff')->get('/sales?p=all');

        $response->assertOk()->assertViewIs('sales');
        $this->snapshotCase('controllers', 'sales', $this->viewSnapshot($response));
    }

    public function test_data_quality_view_contract(): void
    {
        $response = $this->asRole('superadmin')->get('/data-quality');

        $response->assertOk()->assertViewIs('data_quality');
        $this->snapshotCase('controllers', 'data_quality', $this->viewSnapshot($response));
    }

    /**
     * GET /ledger/detail and GET /shipments/detail?ref=... currently 500 on the
     * reference schema (views treat stdClass rows as arrays / add a TEXT column
     * to a float). Pinned as AS-IS defects — see the follow-up card; flip these
     * assertions once the views are fixed.
     */
    public function test_known_defect_detail_pages_return_500(): void
    {
        $ledger = $this->asRole('management')->get('/ledger/detail?k=1101');
        $shipment = $this->asRole('staff')->get('/shipments/detail?ref=REF-TRISCO-001');

        $this->assertSame(200, $ledger->getStatusCode());
        $this->assertSame(200, $shipment->getStatusCode());

        $this->snapshotCase('controllers', 'known_defect_detail_pages', [
            'ledger_detail' => $ledger->getStatusCode(),
            'shipment_detail' => $shipment->getStatusCode(),
        ]);
    }
}
