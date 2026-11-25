<?php

namespace Tests\Feature;

use App\Models\LedgerEntry;
use App\Support\CompanyConnectionFactory;
use App\Support\CmtAuth;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Feature tests for the ledger routes and the Filament ledger pages.
 *
 * Authentication mirrors CmtAuthTest: seed cmt_users_env, POST /login,
 * then replay the cmt_session cookie on guarded requests.
 * gl_ledger_entries lives on the default (sqlite :memory: in testing) connection,
 * so each test runs its own migrate against a fresh in-memory DB.
 */
class LedgerTest extends TestCase
{
    private string $dataDir;

    protected function setUp(): void
    {
        parent::setUp();

        $dir = storage_path('framework/testing/'.Str::random(10));
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $this->dataDir = $dir;
        config(['cmt.data_dir' => $dir]);
        config(['cmt.users_env' => '']);

        $factory = new CompanyConnectionFactory(
            resolver: $this->app->make(ConnectionResolverInterface::class),
            dataDir: $dir,
        );
        $this->app->instance(CompanyConnectionFactory::class, $factory);
        $this->app->instance(CmtAuth::class, new CmtAuth($factory));
        $this->app->make(CompanyConnectionFactory::class)->connection('CMT');

        touch($dir.'/CMT.db');

        $this->artisan('migrate');
    }

    private function auth(): CmtAuth
    {
        return $this->app->make(CmtAuth::class);
    }

    private function seedEnv(string $env): void
    {
        config(['cmt.users_env' => $env]);
        $this->auth()->seedFromEnv();
    }

    /** Seed the staff user and sign in; return the cmt_session token. */
    private function signIn(string $username = 'mgmt', string $password = 'mgmt-pw', string $role = 'management'): string
    {
        $this->seedEnv("{$username}:{$password}:{$role}");

        return $this->post('/login', ['username' => $username, 'password' => $password])
            ->assertStatus(302)
            ->getCookie('cmt_session', false)
            ->getValue();
    }

    private function withAuth(string $token): static
    {
        return $this->withUnencryptedCookie('cmt_session', $token)->withCredentials();
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'account_code' => '1101',
            'debit' => '150.00',
            'credit' => '0.00',
            'reference' => 'INV-001',
            'transaction_date' => '2026-11-20',
        ], $overrides);
    }

    // ── POST /ledger ──────────────────────────────────────────

    public function test_post_ledger_creates_entry_and_returns_payload(): void
    {
        $token = $this->signIn();

        $this->withAuth($token)
            ->postJson('/ledger', $this->validPayload())
            ->assertStatus(201)
            ->assertJsonStructure([
                'id',
                'account_code',
                'debit',
                'credit',
                'reference',
                'transaction_date',
                'created_at',
                'updated_at',
            ])
            ->assertJson([
                'account_code' => '1101',
                'debit' => '150.00',
                'credit' => '0.00',
                'reference' => 'INV-001',
                'transaction_date' => '2026-11-20',
            ]);

        $this->assertDatabaseHas('gl_ledger_entries', [
            'account_code' => '1101',
            'reference' => 'INV-001',
        ]);
    }

    public function test_post_ledger_rejects_both_amounts_zero(): void
    {
        $token = $this->signIn();

        $this->withAuth($token)
            ->postJson('/ledger', $this->validPayload(['debit' => 0, 'credit' => 0]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['debit', 'credit']);

        $this->assertDatabaseCount('gl_ledger_entries', 0);
    }

    public function test_post_ledger_rejects_missing_required_fields(): void
    {
        $token = $this->signIn();

        $this->withAuth($token)
            ->postJson('/ledger', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['account_code', 'transaction_date']);
    }

    public function test_post_ledger_rejects_negative_amount(): void
    {
        $token = $this->signIn();

        $this->withAuth($token)
            ->postJson('/ledger', $this->validPayload(['debit' => '-10.00']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['debit']);

        $this->assertDatabaseCount('gl_ledger_entries', 0);
    }

    public function test_post_ledger_rejects_invalid_date(): void
    {
        $token = $this->signIn();

        $this->withAuth($token)
            ->postJson('/ledger', $this->validPayload(['transaction_date' => 'not-a-date']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['transaction_date']);

        $this->assertDatabaseCount('gl_ledger_entries', 0);
    }

    public function test_post_ledger_requires_authentication(): void
    {
        $this->postJson('/ledger', $this->validPayload())
            ->assertStatus(303)
            ->assertRedirect('/login');

        $this->assertDatabaseCount('gl_ledger_entries', 0);
    }

    // ── GET /ledger/entries ── journal list (was GET /ledger index) ─────────

    public function test_get_ledger_lists_entries_paginated_newest_first(): void
    {
        $token = $this->signIn('staff', 'staff-pw');

        LedgerEntry::create($this->validPayload(['account_code' => '1101', 'transaction_date' => '2026-11-01']));
        LedgerEntry::create($this->validPayload(['account_code' => '2105', 'debit' => '0.00', 'credit' => '250.00', 'reference' => 'INV-002', 'transaction_date' => '2026-11-05']));

        $this->withAuth($token)
            ->getJson('/ledger/entries')
            ->assertOk()
            ->assertJsonStructure([
                'items' => [['id', 'account_code', 'debit', 'credit', 'reference', 'transaction_date']],
                'total',
                'page',
                'per_page',
                'last_page',
            ])
            ->assertJsonCount(2, 'items');

        $items = $this->withAuth($token)->getJson('/ledger/entries')->json('items');
        $this->assertSame('2105', $items[0]['account_code']);
        $this->assertSame('1101', $items[1]['account_code']);
    }

    public function test_get_ledger_searches_by_account_code_and_reference(): void
    {
        $token = $this->signIn('staff', 'staff-pw');

        LedgerEntry::create($this->validPayload(['account_code' => '1101', 'reference' => 'SEARCH-001', 'transaction_date' => '2026-11-01']));
        LedgerEntry::create($this->validPayload(['account_code' => '2105', 'reference' => 'SEARCH-002', 'transaction_date' => '2026-11-02']));

        $this->withAuth($token)->getJson('/ledger/entries?q=SEARCH-001')
            ->assertOk()
            ->assertJsonCount(1, 'items');

        $this->withAuth($token)->getJson('/ledger/entries?q=SEARCH-002')
            ->assertOk()
            ->assertJsonCount(1, 'items');

        $this->withAuth($token)->getJson('/ledger/entries?q=nope')
            ->assertOk()
            ->assertJsonCount(0, 'items');
    }

    public function test_get_ledger_returns_view_for_html_request(): void
    {
        $token = $this->signIn('staff', 'staff-pw');

        $this->withAuth($token)
            ->get('/ledger/entries')
            ->assertOk()
            ->assertViewIs('ledger_entries');
    }

    // ── Filament ledger pages ─────────────────────────────────

    public function test_filament_create_page_requires_authentication(): void
    {
        $this->get('/admin/ledger-entries/create')
            ->assertStatus(302)
            ->assertRedirect('/login');
    }

    public function test_filament_create_page_loads(): void
    {
        $token = $this->signIn();

        $this->withAuth($token)
            ->get('/admin/ledger-entries/create')
            ->assertOk()
            ->assertSee('Account Code');
    }

    public function test_filament_create_page_stores_entry(): void
    {
        $token = $this->signIn('staff', 'staff-pw');

        // Touch an authenticated route first so the `cmt` guard resolves against
        // a request carrying the cookie before the Livewire mount reads auth('cmt').
        $this->withAuth($token)->get('/ledger/entries')->assertOk();

        // Authenticate the guard directly for Livewire component mount (no request cookie in test)
        $cmtUser = $this->auth()->userForToken($token);
        \Illuminate\Support\Facades\Auth::guard('cmt')->setUser(new \App\Support\Panel\CmtPanelUser($cmtUser));

        Livewire::test(\App\Filament\Resources\LedgerEntries\Pages\CreateLedgerEntry::class)
            ->set('data.account_code', '1102')
            ->set('data.debit', '100.00')
            ->set('data.credit', '0.00')
            ->set('data.reference', 'INV-003')
            ->set('data.transaction_date', '2026-11-20')
            ->call('create');

        $this->assertDatabaseHas('gl_ledger_entries', [
            'account_code' => '1102',
            'reference' => 'INV-003',
        ]);
    }

    public function test_filament_list_page_loads(): void
    {
        $token = $this->signIn('staff', 'staff-pw');

        $this->withAuth($token)
            ->get('/admin/ledger-entries')
            ->assertOk()
            ->assertSee('Account Code');
    }

    public function test_post_ledger_forbidden_for_staff(): void
    {
        $token = $this->signIn('staff', 'staff-pw', 'staff');

        $this->withAuth($token)
            ->postJson('/ledger', $this->validPayload())
            ->assertStatus(403)
            ->assertJson(['detail' => 'Requires role >= management']);

        $this->assertDatabaseCount('gl_ledger_entries', 0);
    }

    public function test_get_ledger_routes_forbidden_for_customer(): void
    {
        $token = $this->signIn('customer', 'customer-pw', 'customer');

        $this->withAuth($token)
            ->getJson('/ledger')
            ->assertStatus(403)
            ->assertJson(['detail' => 'Requires role >= management']);

        $this->withAuth($token)
            ->getJson('/ledger/entries')
            ->assertStatus(403)
            ->assertJson(['detail' => 'Requires role >= management']);

        $entry = LedgerEntry::create($this->validPayload(['transaction_date' => '2026-11-20']));

        $this->withAuth($token)
            ->getJson("/ledger/entries/{$entry->id}")
            ->assertStatus(403);

        $this->assertDatabaseCount('gl_ledger_entries', 1);
    }

    public function test_post_ledger_accepts_both_sides_nonzero(): void
    {
        // Decision: GL writes accept single-sided or both-sides entries;
        // balancing is a Data Quality (F-11) scan concern, not a write
        // constraint. Only both-zero is rejected.
        $token = $this->signIn();

        $this->withAuth($token)
            ->postJson('/ledger', $this->validPayload(['debit' => '100.00', 'credit' => '50.00']))
            ->assertStatus(201)
            ->assertJson(['debit' => '100.00', 'credit' => '50.00']);

        $this->assertDatabaseCount('gl_ledger_entries', 1);
    }

    public function test_post_ledger_rejects_overflow_date(): void
    {
        $token = $this->signIn();

        $this->withAuth($token)
            ->postJson('/ledger', $this->validPayload(['transaction_date' => '2026-02-30']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['transaction_date']);

        $this->assertDatabaseCount('gl_ledger_entries', 0);
    }

    public function test_get_ledger_show_non_numeric_id_404(): void
    {
        $token = $this->signIn();

        $this->withAuth($token)
            ->getJson('/ledger/abc')
            ->assertStatus(404);

        $this->withAuth($token)
            ->get('/ledger/abc')
            ->assertStatus(404);
    }

    public function test_filament_create_page_forbidden_for_staff(): void
    {
        $token = $this->signIn('staff', 'staff-pw', 'staff');

        $this->withAuth($token)
            ->get('/admin/ledger-entries/create')
            ->assertStatus(403)
            ->assertJson(['detail' => 'Requires role >= management']);
    }

    public function test_filament_edit_page_loads(): void
    {
        $token = $this->signIn();

        $entry = LedgerEntry::create($this->validPayload(['account_code' => '1103', 'transaction_date' => '2026-11-20']));

        $this->withAuth($token)
            ->get("/admin/ledger-entries/{$entry->id}/edit")
            ->assertOk()
            ->assertSee('Account Code');
    }
}
