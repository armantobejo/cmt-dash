<?php

namespace App\Providers;

use App\Support\CmtAnomalies;
use App\Support\CompanyConnectionFactory;
use App\Support\CompanyDbManager;
use App\Support\CmtAuth;
use App\Support\CmtI18n;
use App\Support\CmtQueries;
use App\Support\CmtUser;
use App\Support\Dbf\DbfReader;
use App\Support\Dbf\SyncService;
use App\Support\Panel\CmtGuard;
use App\Http\View\Composers\CmtViewComposer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // `cmt` auth guard: the application's own cmt_session cookie, exposed
        // to Laravel's Auth facade so Filament's panel authenticates against
        // the same session as the rest of the app (no Eloquent user provider,
        // no second login). See App\Support\Panel\CmtGuard.
        Auth::extend('cmt', function ($app, string $name, array $config): CmtGuard {
            return new CmtGuard(
                request: $app['request'],
                auth: $app->make(CmtAuth::class),
            );
        });

        // Connections live in the DatabaseManager (app-wide), so the factory
        // that registers them is a plain singleton.
        $this->app->singleton(CompanyConnectionFactory::class, function ($app) {
            return new CompanyConnectionFactory(
                resolver: $app->make(\Illuminate\Database\ConnectionResolverInterface::class),
                dataDir: rtrim((string) config('cmt.data_dir'), '/\\'),
            );
        });

        // Auth service (stateless beyond the DB): singleton.
        $this->app->singleton(CmtAuth::class);

        // One manager per request: its ->active() IS the per-request state.
        $this->app->scoped(CompanyDbManager::class, function ($app) {
            return new CompanyDbManager(
                dataDir: rtrim((string) config('cmt.data_dir'), '/\\'),
                default: (string) config('cmt.default_db'),
            );
        });

        // Per-request current user; CmtSession middleware overwrites it.
        $this->app->scoped(CmtUser::class, fn () => CmtUser::anonymous());

        // i18n (Python i18n.py): default lang from CMT_LANG, JSON files under
        // lang/cmt (copied verbatim from src/app/translations).
        $this->app->scoped(CmtI18n::class, function ($app) {
            return new CmtI18n(
                defaultLang: (string) config('cmt.lang_default', 'id'),
                langDir: $app->basePath('lang'.DIRECTORY_SEPARATOR.'cmt'),
            );
        });

        // Query port (Python queries.py). Scoped: reads the per-request
        // active company DB through CompanyDbManager.
        $this->app->scoped(CmtQueries::class);

        // DBF sync (P3): the reader facade is the only php-xbase-aware file;
        // the service is stateless per run.
        $this->app->singleton(DbfReader::class);
        $this->app->singleton(SyncService::class);

        // GL data-quality queries (P4): stateless per request; fileBound()
        // clones rebind the DB file for tests and the audit harness.
        $this->app->singleton(CmtAnomalies::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // SC-001 (CWE-307): failures against one username must not lock out a
        // different account sharing the same egress IP. Per-(username, IP)
        // bucket for the real limit, plus a generous IP-wide backstop.
        RateLimiter::for('login', function (\Illuminate\Http\Request $request): array {
            return [
                Limit::perMinute(5)->by(Str::lower((string) $request->input('username')).'|'.$request->ip()),
                Limit::perMinute(60)->by('ip:'.$request->ip()),
            ];
        });

        // CMT_USERS seeding at boot (Python UserStore.__init__ parity);
        // idempotent, no-op when CMT_USERS is unset. Guarded BEFORE resolving
        // CmtAuth: boot-time resolution would cache the singleton with the
        // boot-time CompanyConnectionFactory, so tests that rebind the data
        // dir would inherit a factory frozen on the real repo path (and its
        // cmt_* connection registrations would poison database.connections).
        if ((string) config('cmt.users_env') !== '') {
            $this->app->make(CmtAuth::class)->seedFromEnv();
        }

        // Structured access logging — RequestHandled fires after the
        // response is finalized, so 404s log status 404 (not a 500).
        Event::listen(
            \Illuminate\Foundation\Http\Events\RequestHandled::class,
            \App\Listeners\LogRequestCompleted::class,
        );

        // Layout context on every view (Python _render globals).
        View::composer('*', CmtViewComposer::class);

        // Money filter as a Blade directive: @money($v, 'usd') / @moneyabbr.
        Blade::directive('money', function ($expression) {
            return "<?php echo \\App\\Support\\CmtMoney::format({$expression}); ?>";
        });

        // Python Jinja `num` filter (symbol-free, IDR grouping) — @num($v).
        Blade::directive('num', function ($expression) {
            return "<?php echo \\App\\Support\\CmtMoney::num({$expression}); ?>";
        });
    }
}
