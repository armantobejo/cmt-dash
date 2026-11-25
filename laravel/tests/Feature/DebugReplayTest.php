<?php

namespace Tests\Feature;

use App\Support\CompanyConnectionFactory;
use App\Support\CmtAuth;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Str;
use Tests\TestCase;

class DebugReplayTest extends TestCase
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

    public function test_debug_mimic_ledgertest(): void
    {
        $this->seedEnv('staff:staff-pw:staff');
        $token = $this->post('/login', ['username' => 'staff', 'password' => 'staff-pw'])
            ->assertStatus(302)
            ->getCookie('cmt_session', false)->getValue();
        fwrite(STDERR, "token: $token\n");

        $res = $this->withUnencryptedCookie('cmt_session', $token)->postJson('/ledger', [
            'account_code' => '1101',
            'debit' => '150.00',
            'credit' => '0.00',
            'reference' => 'INV-001',
            'transaction_date' => '2026-11-20',
        ]);
        fwrite(STDERR, "ledger status: ".$res->getStatusCode()."\n");
        fwrite(STDERR, "ledger body: ".$res->getContent()."\n");

        $this->assertTrue(true);
    }

    private function seedEnv(string $env): void
    {
        config(['cmt.users_env' => $env]);
        $this->app->make(CmtAuth::class)->seedFromEnv();
    }
}
