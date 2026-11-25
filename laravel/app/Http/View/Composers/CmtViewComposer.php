<?php

namespace App\Http\View\Composers;

use App\Support\CompanyDbManager;
use App\Support\CmtI18n;
use App\Support\CmtUser;
use Illuminate\View\View;

/**
 * Shared layout context — the Laravel counterpart of Python _render()'s
 * injected globals: user, i18n closure (_t), language, sync_time (DB file
 * mtime as "%Y-%m-%d %H:%M", or '—'), available DBs + active name for the
 * selector, and the active sidebar page.
 *
 * It is registered for '*' because it is the app's layout contract, but it
 * deliberately does not compose Filament's own views: Filament supplies `user`
 * as the panel's Authenticatable (App\Support\Panel\CmtPanelUser), and letting
 * this composer write the raw CmtUser DTO over that key breaks the panel's
 * user-avatar component (FilamentManager::getUserAvatarUrl() type error).
 */
class CmtViewComposer
{
    public function __construct(
        protected CmtI18n $i18n,
        protected CompanyDbManager $manager,
        protected CmtUser $user,
    ) {
    }

    public function compose(View $view): void
    {
        // Filament panel views own their layout context — see the class note.
        if (str_starts_with($view->getName(), 'filament')) {
            return;
        }

        $lang = $this->i18n->langFromCookie(request()->cookie('cmt_lang'));
        $syncTime = '—';
        try {
            $mtime = @filemtime($this->manager->path());
            if ($mtime !== false) {
                $syncTime = date('Y-m-d H:i', $mtime);
            }
        } catch (\Throwable) {
        }

        $view->with([
            'user' => $this->user,
            't' => fn (string $key): string => $this->i18n->t($lang, $key),
            '_lang' => $lang,
            'sync_time' => $syncTime,
            'available_dbs' => collect($this->manager->available()),
            'active_db_name' => $this->manager->active(),
            'active_page' => $view->getData()['active_page'] ?? null,
        ]);
    }
}
