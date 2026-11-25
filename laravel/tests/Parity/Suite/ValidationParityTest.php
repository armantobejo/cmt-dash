<?php

namespace Tests\Parity\Suite;

use App\Support\CmtAuth;

/**
 * Validation parity: error envelopes are part of the contract (FastAPI 422
 * item shape for form/query params, Laravel `errors` map for the ledger write
 * path, plain message strings for user administration).
 */
class ValidationParityTest extends ParitySuiteTestCase
{
    public function test_ledger_entry_requires_account_code(): void
    {
        $response = $this->asRole('management')->postJson('/ledger', [
            'transaction_date' => '2026-01-07',
            'debit' => 10,
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('account_code', $response->json('errors'));

        $this->snapshotCase('validation', 'ledger_missing_account_code', [
            'status' => $response->getStatusCode(),
            'error_keys' => array_keys($response->json('errors') ?? []),
            'first_message' => $response->json('errors.account_code.0'),
        ]);
    }

    public function test_ledger_entry_requires_date_format(): void
    {
        $response = $this->asRole('management')->postJson('/ledger', [
            'account_code' => '1101',
            'transaction_date' => '07-01-2026',
            'debit' => 10,
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('transaction_date', $response->json('errors'));
    }

    public function test_ledger_entry_rejects_negative_amount(): void
    {
        $response = $this->asRole('management')->postJson('/ledger', [
            'account_code' => '1101',
            'transaction_date' => '2026-01-07',
            'debit' => -5,
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('debit', $response->json('errors'));
    }

    public function test_ledger_entry_rejects_zero_debit_and_credit(): void
    {
        $response = $this->asRole('management')->postJson('/ledger', [
            'account_code' => '1101',
            'transaction_date' => '2026-01-07',
            'debit' => 0,
            'credit' => 0,
        ]);

        $response->assertStatus(422);
        $this->assertSame(
            ['debit' => ['At least one of debit or credit must be greater than zero.'],
             'credit' => ['At least one of debit or credit must be greater than zero.']],
            $response->json('errors')
        );

        $this->snapshotCase('validation', 'ledger_zero_amount', $response->json());
    }

    public function test_ledger_entry_accepts_valid_payload(): void
    {
        $response = $this->asRole('management')->postJson('/ledger', [
            'account_code' => '1101',
            'transaction_date' => '2026-01-07',
            'debit' => 42.5,
            'reference' => 'REF-TRISCO-001',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseCount('gl_ledger_entries', 1);

        $this->snapshotCase('validation', 'ledger_valid_payload', [
            'status' => $response->getStatusCode(),
            'account_code' => $response->json('account_code'),
            'debit' => $response->json('debit'),
            'reference' => $response->json('reference'),
            'transaction_date' => $response->json('transaction_date'),
        ]);
    }

    public function test_ledger_entry_html_post_redirects_back_with_errors(): void
    {
        $response = $this->asRole('management')
            ->from('/ledger')
            ->post('/ledger', ['account_code' => '']);

        $response->assertStatus(302);
        $this->assertTrue(session()->has('errors'));
        $this->assertArrayHasKey('account_code', session('errors')->getBag('default')->messages());
    }

    public function test_user_create_rejects_invalid_role(): void
    {
        $error = $this->auth()->createUser('newuser', 'New User', 'wizard', 'pw');

        $this->assertSame('Invalid role', $error);
        $this->snapshotCase('validation', 'user_invalid_role', ['error' => $error]);
    }

    public function test_user_create_rejects_duplicate_username(): void
    {
        $error = $this->auth()->createUser('staff1', 'Dup', 'staff', 'pw');

        $this->assertSame('Username already exists', $error);
    }

    public function test_user_create_requires_username_and_password(): void
    {
        $this->assertSame('Username is required', $this->auth()->createUser('  ', 'X', 'staff', 'pw'));
        $this->assertSame('Password is required', $this->auth()->createUser('fresh-user', 'X', 'staff', ''));
    }

    public function test_user_create_cannot_escalate_above_actor_level(): void
    {
        $error = $this->auth()->createUser('escalated', 'Esc', 'superadmin', 'pw',
            '', CmtAuth::ROLE_LEVEL['admin']);

        $this->assertSame('Cannot create user with role above your level', $error);
    }

    public function test_login_missing_both_fields_returns_two_items(): void
    {
        $response = $this->guest()->postJson('/login', []);

        $response->assertStatus(422);
        $detail = $response->json('detail');
        $this->assertCount(2, $detail);
        $this->assertSame(['body', 'username'], $detail[0]['loc']);
        $this->assertSame(['body', 'password'], $detail[1]['loc']);

        $this->snapshotCase('validation', 'login_missing_fields', $detail);
    }
}
