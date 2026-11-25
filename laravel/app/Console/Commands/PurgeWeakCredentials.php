<?php

namespace App\Console\Commands;

use App\Support\CmtAuth;
use App\Support\CompanyConnectionFactory;
use Illuminate\Console\Command;

/**
 * Deploy-time purge of legacy weak/default credentials (SC-001 hardening).
 *
 * The CMT_USERS seeding path now rejects weak entries, but pre-existing
 * weak rows in upgraded DBs survive the idempotent seed. This command
 * scans the users table and purges any account whose stored hash matches
 * a known weak password for its username (admin:admin, superadmin:superadmin,
 * username==password, etc.).
 *
 * Usage:
 *   php artisan cmt:auth:purge-weak-credentials        # report only
 *   php artisan cmt:auth:purge-weak-credentials --delete  # delete found accounts
 */
class PurgeWeakCredentials extends Command
{
    protected $signature = 'cmt:auth:purge-weak-credentials
        {--delete : Delete weak accounts (default: report only)}';

    protected $description = 'Find and optionally purge users with weak/default credentials (SC-001)';

    public function handle(CompanyConnectionFactory $connections): int
    {
        $auth = app(CmtAuth::class);
        $conn = $connections->authConnection();
        $auth->ensureSchema($conn);

        $users = $conn->table('users')->get(['id', 'username', 'pw_hash', 'salt', 'role']);

        if ($users->isEmpty()) {
            $this->info('No users table or no users found.');
            return self::SUCCESS;
        }

        $weakAccounts = [];

        foreach ($users as $u) {
            $weakPwds = CmtAuth::weakPasswordsFor($u->username);
            // Also check username==password case (covered by isWeakCredential logic)
            if (! in_array(strtolower($u->username), $weakPwds, true)) {
                $weakPwds[] = strtolower($u->username);
            }

            foreach ($weakPwds as $pw) {
                $hash = CmtAuth::hashPw($pw, $u->salt);
                if ($hash === $u->pw_hash) {
                    $weakAccounts[] = [
                        'id'       => $u->id,
                        'username' => $u->username,
                        'role'     => $u->role,
                        'weak_pwd' => $pw,
                    ];
                    break;
                }
            }
        }

        if (empty($weakAccounts)) {
            $this->info('No weak/default credentials found.');
            return self::SUCCESS;
        }

        $headers = ['ID', 'Username', 'Role', 'Matched Weak Password'];
        $rows = array_map(fn ($a) => [$a['id'], $a['username'], $a['role'], $a['weak_pwd']], $weakAccounts);
        $this->table($headers, $rows);

        if ($this->option('delete')) {
            $this->warn('Deleting ' . count($weakAccounts) . ' weak account(s)...');
            foreach ($weakAccounts as $a) {
                $conn->table('sessions')->where('user_id', $a['id'])->delete();
                $conn->table('users')->where('id', $a['id'])->delete();
                $this->line("  Deleted {$a['username']} (id={$a['id']})");
            }
            $this->info('Purge complete.');
        } else {
            $this->warn('Report only — re-run with --delete to purge these accounts.');
        }

        return self::SUCCESS;
    }
}