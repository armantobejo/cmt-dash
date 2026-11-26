<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LedgerController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\SetDbController;
use App\Http\Controllers\UserAdminController;
use Illuminate\Support\Facades\Route;

// ── Auth (public) ────────────────────────────────────────────────────────────

// Named `login` so framework middleware (Filament's panel Authenticate) can
// redirect guests to the app's one login page instead of a vendor login form.
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
// Security: 5 failures/min per (username, IP) — see AppServiceProvider's
// named `login` limiter — plus a generous IP-wide backstop.
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/logout', [AuthController::class, 'logout']);

// ── Company DB + language switchers (public POST — KG-3 parity) ─────────────

Route::post('/set-db', [SetDbController::class, 'setDb']);
Route::post('/set-lang', [SetDbController::class, 'setLang']);

// ── User management (admin+; delete superadmin-only) ────────────────────────

Route::get('/users', UserAdminController::class.'@index')->middleware('cmt.role:admin');
Route::post('/users/new', UserAdminController::class.'@store')->middleware('cmt.role:admin');
Route::get('/users/{user_id}/edit', UserAdminController::class.'@edit')->middleware('cmt.role:admin');
Route::post('/users/{user_id}/edit', UserAdminController::class.'@update')->middleware('cmt.role:admin');
Route::post('/users/{user_id}/delete', UserAdminController::class.'@destroy')->middleware('cmt.role:superadmin');

// ── Data quality (superadmin only) ─────────────────────────────────────

Route::get('/data-quality', [DashboardController::class, 'dataQuality'])->middleware('cmt.role:superadmin');
Route::get('/data-quality/detail', [DashboardController::class, 'dataQualityDetail'])->middleware('cmt.role:superadmin');

// ── Dashboard + shipments + invoices (login required) ───────────────────────

Route::get('/', [DashboardController::class, 'index'])->middleware('cmt.auth');
Route::get('/shipments', [DashboardController::class, 'shipments'])->middleware('cmt.auth');
Route::get('/shipments/detail', [DashboardController::class, 'shipmentDetail'])->middleware('cmt.auth');
Route::get('/invoices', [DashboardController::class, 'invoices'])->middleware('cmt.auth');
Route::get('/invoices/detail', [DashboardController::class, 'invoiceDetail'])->middleware('cmt.auth');

// ── Ledger (GL) ──────────────────────────────────────────────────────────────
// Reads & writes: management+ only.

Route::get('/ledger', [LedgerController::class, 'accounts'])->middleware('cmt.role:management')->name('ledger.accounts');
Route::post('/ledger', [LedgerController::class, 'entriesStore'])->middleware('cmt.role:management')->name('ledger.store');
Route::get('/ledger/detail', [LedgerController::class, 'detail'])->middleware('cmt.role:management')->name('ledger.detail');
Route::get('/ledger/entries', [LedgerController::class, 'entriesIndex'])->middleware('cmt.role:management')->name('ledger.entries.index');
Route::get('/ledger/entries/{id}', [LedgerController::class, 'entriesShow'])->middleware('cmt.role:management')->whereNumber('id')->name('ledger.entries.show');
Route::get('/ledger/{id}', [LedgerController::class, 'entriesShow'])->middleware('cmt.role:management')->whereNumber('id')->name('ledger.show');

// ── Sales Dashboard ──────────────────────────────────────────────────────────
Route::get('/sales', [SalesController::class, 'sales'])->middleware('cmt.auth')->name('sales.index');

// ── Sync trigger (management+; real sync arrives in P3) ─────────────────────

Route::post('/sync', [DashboardController::class, 'sync'])->middleware('cmt.role:management');
