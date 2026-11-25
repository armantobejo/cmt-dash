<?php

namespace Tests\Parity\Suite;

use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

/**
 * Eloquent parity: the two Eloquent-backed models and the query shapes they
 * emit. The rest of the app talks to the legacy SQLite schema through the
 * query builder (parity pinned by the Python baseline); these are the layers
 * that must stay Eloquent-consistent.
 */
class EloquentParityTest extends ParitySuiteTestCase
{
    public function test_ledger_entry_table_and_casts(): void
    {
        $model = new LedgerEntry();

        $this->assertSame('gl_ledger_entries', $model->getTable());
        $this->assertSame(
            ['account_code', 'debit', 'credit', 'reference', 'transaction_date'],
            $model->getFillable()
        );

        $this->snapshotCase('eloquent', 'ledger_entry_config', [
            'table' => $model->getTable(),
            'fillable' => $model->getFillable(),
            'casts' => array_intersect_key(
                $model->getCasts(),
                array_flip(['debit', 'credit', 'transaction_date'])
            ),
            'connection' => $model->getConnectionName() ?? 'default',
        ]);
    }

    public function test_ledger_entry_create_and_retrieve_roundtrip(): void
    {
        $entry = LedgerEntry::create([
            'account_code' => '1101',
            'debit' => 125.5,
            'credit' => 0,
            'reference' => 'REF-TRISCO-001',
            'transaction_date' => '2026-01-07',
        ]);

        $fresh = LedgerEntry::findOrFail($entry->id);

        $this->assertSame('1101', $fresh->account_code);
        $this->assertSame('125.50', $fresh->debit, 'decimal:2 cast renders a fixed-precision string');
        $this->assertSame('2026-01-07', $fresh->transaction_date->format('Y-m-d'));

        $this->snapshotCase('eloquent', 'ledger_entry_row', [
            'id' => $fresh->id,
            'account_code' => $fresh->account_code,
            'debit' => $fresh->debit,
            'credit' => $fresh->credit,
            'reference' => $fresh->reference,
            'transaction_date' => $fresh->transaction_date->format('Y-m-d'),
        ]);
    }

    public function test_ledger_entry_mass_assignment_guard(): void
    {
        $entry = LedgerEntry::create([
            'account_code' => '1101',
            'debit' => 1,
            'credit' => 0,
            'transaction_date' => '2026-01-07',
            'id' => 999,
            'created_at' => '2020-01-01 00:00:00',
        ]);

        $this->assertNotSame(999, $entry->id, 'non-fillable attributes are ignored by mass assignment');
        $this->assertNotSame('2020-01-01 00:00:00', (string) $entry->created_at);
    }

    public function test_ledger_entry_query_shape(): void
    {
        LedgerEntry::create([
            'account_code' => '1101',
            'debit' => 10,
            'credit' => 0,
            'transaction_date' => '2026-01-05',
        ]);
        LedgerEntry::create([
            'account_code' => '2101',
            'debit' => 0,
            'credit' => 20,
            'transaction_date' => '2026-01-06',
        ]);

        DB::enableQueryLog();
        $rows = LedgerEntry::query()
            ->where('account_code', '1101')
            ->orderBy('transaction_date', 'desc')
            ->limit(10)
            ->get();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $rows);
        $this->snapshotCase('eloquent', 'ledger_entry_query', [
            'sql' => $this->sqlShapes($log),
            'bindings' => array_values($log[0]['bindings'] ?? []),
            'row_count' => $rows->count(),
        ]);
    }

    public function test_ledger_entry_to_array_keys(): void
    {
        $entry = new LedgerEntry([
            'account_code' => '1101',
            'debit' => 5,
            'credit' => 0,
            'transaction_date' => '2026-01-07',
        ]);

        $this->snapshotCase('eloquent', 'ledger_entry_array_keys', [
            'keys' => array_keys($entry->toArray()),
        ]);
    }

    public function test_user_model_configuration(): void
    {
        $model = new User();

        $this->assertSame('users', $model->getTable());
        $this->assertContains(Notifiable::class, class_uses_recursive(User::class));
        $this->assertNotContains('password', array_keys($model->toArray()));

        $this->snapshotCase('eloquent', 'user_model_config', [
            'table' => $model->getTable(),
            'fillable' => $model->getFillable(),
            'hidden' => $model->getHidden(),
            'notifiable' => in_array(Notifiable::class, class_uses_recursive(User::class), true),
            'is_model' => $model instanceof Model,
        ]);
    }

    public function test_ledger_entry_persistence_and_freshness(): void
    {
        $entry = LedgerEntry::create([
            'account_code' => '1101',
            'debit' => 5,
            'credit' => 0,
            'transaction_date' => '2026-01-07',
        ]);

        $this->assertTrue($entry->exists);
        $this->assertTrue(LedgerEntry::whereKey($entry->id)->exists());
        $this->assertSame(1, LedgerEntry::whereKey($entry->id)->count());
        $this->assertFalse((new LedgerEntry(['account_code' => 'x']))->exists);

        $this->snapshotCase('eloquent', 'ledger_entry_persistence', [
            'exists_after_create' => $entry->exists,
            'count_for_key' => LedgerEntry::whereKey($entry->id)->count(),
        ]);
    }

    public function test_ledger_entry_globally_scoped_query_returns_plain_collection(): void
    {
        $rows = LedgerEntry::query()->get();

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $rows);
        $this->assertSame(0, $rows->count());
    }
}
