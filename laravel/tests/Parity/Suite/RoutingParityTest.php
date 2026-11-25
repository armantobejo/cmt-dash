<?php

namespace Tests\Parity\Suite;

use Illuminate\Support\Facades\Route;

/**
 * Routing parity: the route table is the contract the Python app's URL map is
 * compared against. Snapshotted so any added/removed/moved route shows up as
 * a diff instead of a silent behavior change.
 */
class RoutingParityTest extends ParitySuiteTestCase
{
    private function appRoutes(): array
    {
        $routes = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            if (preg_match('#^(admin|filament|livewire|up|storage)#', $uri)) {
                continue; // vendor surfaces, not part of the parity contract
            }

            $action = $route->getAction();
            $controller = $action['controller'] ?? ($action['uses'] ?? null);

            $routes[implode('|', $route->methods()) . ' /' . $uri] = [
                'uri' => $uri,
                'methods' => $route->methods(),
                'name' => $route->getName(),
                'controller' => is_string($controller)
                    ? $controller
                    : (is_object($controller) ? get_class($controller) : 'closure'),
                'middleware' => array_values($route->gatherMiddleware()),
            ];
        }

        ksort($routes, SORT_STRING);

        return $routes;
    }

    private function filterPrefix(array $routes, string $prefix): array
    {
        return array_filter(
            $routes,
            fn (array $r) => str_starts_with($r['uri'], $prefix)
        );
    }

    public function test_web_route_table_matches_baseline(): void
    {
        $web = array_filter($this->appRoutes(), fn (array $r) => ! str_starts_with($r['uri'], 'api/'));

        $this->assertNotEmpty($web);
        $this->snapshotCase('routing', 'web_routes', $web);
    }

    public function test_api_route_table_matches_baseline(): void
    {
        $api = $this->filterPrefix($this->appRoutes(), 'api/');

        $this->assertNotEmpty($api);
        $this->snapshotCase('routing', 'api_routes', $api);
    }

    public function test_route_names_are_stable(): void
    {
        $names = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = $route->getName();
            if ($name === null || str_starts_with($name, 'filament.')) {
                continue;
            }
            $names[$name] = '/' . $route->uri();
        }
        ksort($names, SORT_STRING);

        $this->snapshotCase('routing', 'named_routes', $names);
    }

    public function test_login_route_is_named_login(): void
    {
        $route = Route::getRoutes()->getByName('login');

        $this->assertNotNull($route, 'framework middleware redirects guests to the `login` named route');
        $this->assertSame('login', $route->uri());
    }

    public function test_route_counts_match_baseline(): void
    {
        $counts = ['web' => 0, 'api' => 0];
        foreach ($this->appRoutes() as $route) {
            $counts[str_starts_with($route['uri'], 'api/') ? 'api' : 'web']++;
        }

        $this->assertGreaterThanOrEqual(15, $counts['web'] + $counts['api']);
        $this->snapshotCase('routing', 'route_counts', $counts);
    }

    public function test_unknown_route_renders_custom_404(): void
    {
        $response = $this->guest()->get('/nonexistent-page', ['Accept' => 'text/html']);

        $response->assertStatus(404);
        $this->assertStringContainsString('<!DOCTYPE html>', (string) $response->getContent());
        $this->snapshotCase('routing', 'unknown_route', [
            'status' => $response->getStatusCode(),
            'is_html' => true,
        ]);
    }

    public function test_numeric_route_constraint_rejects_non_numeric_id(): void
    {
        $this->asRole('management')
            ->get('/ledger/not-a-number', ['Accept' => 'text/html'])
            ->assertStatus(404);
    }

    public function test_runtime_metadata_snapshot(): void
    {
        $this->snapshotCase('meta', 'runtime', [
            'laravel_version' => app()->version(),
            'php_version' => PHP_VERSION,
            'snapshot_schema' => SnapshotStore::SCHEMA_VERSION,
            'parity_reference' => 'tests/parity/baseline/python.json',
        ]);
    }
}
