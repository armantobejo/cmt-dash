<?php

namespace Tests\Feature;

use App\Support\CompanyConnectionFactory;
use App\Support\CmtAuth;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Str;
use Tests\TestCase;

class DebugAuthTest extends TestCase
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

    public function test_debug_seed_and_login(): void
    {
        config(['cmt.users_env' => 'staff:staff-pw:staff']);
        $this->app->make(CmtAuth::class)->seedFromEnv();

        $conn = $this->app->make(CompanyConnectionFactory::class)->authConnection();
        fwrite(STDERR, "users: ".$conn->table('users')->count()."\n");

        $row = $conn->table('users')->where('username', 'staff')->first();
        fwrite(STDERR, "row: ".($row ? json_encode((array) $row) : 'NULL')."\n");

        $tok = $this->app->make(CmtAuth::class)->authenticate('staff', 'staff-pw');
        fwrite(STDERR, "token: ".var_export($tok, true)."\n");
        fwrite(STDERR, "file exists: ".(is_file($this->dataDir.'/CMT.db') ? 'yes' : 'no')."\n");

        $response = $this->post('/login', ['username' => 'staff', 'password' => 'staff-pw']);
        fwrite(STDERR, "login status: ".$response->getStatusCode()."\n");
        fwrite(STDERR, "cookie: ".var_export($response->getCookie('cmt_session', false), true)."\n");

        $this->assertTrue(true);
    }
}
