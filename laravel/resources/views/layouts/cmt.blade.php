{{-- Byte-exact port of base.html: CoreUI dark chrome, sync button for
     management+ only, DB dropdown when >1 DB, language dropdown, theme
     toggle, and the footer with the DB-mtime sync time. Text nodes must
     match the Jinja templates so the parity captures align. --}}
<!DOCTYPE html>
<html lang="{{ $_lang }}" data-coreui-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CMT Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
</head>
<body>
    <div class="sidebar sidebar-dark sidebar-narrow bg-dark border-end" id="sidebar">
        <div class="sidebar-header px-3 py-3 d-flex align-items-center">
            <i class="bi bi-box-seam fs-4 me-2 text-info"></i>
            <h6 class="m-0 fw-semibold">CMT Dash</h6>
        </div>
        <ul class="sidebar-nav">
            @if($user->authenticated)
                <li class="nav-item"><a href="/" class="nav-link {{ $active_page === 'home' ? 'active' : '' }}" title="{{ $t('Home Dashboard') }}"><i class="nav-icon bi bi-grid-1x2-fill"></i> <span>{{ $t('Home Dashboard') }}</span></a></li>
                <li class="nav-item"><a href="/sales" class="nav-link {{ $active_page === 'sales' ? 'active' : '' }}" title="{{ $t('Sales') }}"><i class="nav-icon bi bi-graph-up"></i> <span>{{ $t('Sales') }}</span></a></li>
                <li class="nav-item"><a href="/shipments" class="nav-link {{ $active_page === 'shipments' ? 'active' : '' }}" title="{{ $t('Shipments') }}"><i class="nav-icon bi bi-truck"></i> <span>{{ $t('Shipments') }}</span></a></li>
                <li class="nav-group {{ in_array($active_page, ['invoices','ledger']) ? 'show' : '' }}">
                    <a class="nav-group-toggle" href="#">
                        <i class="nav-icon bi bi-cash-stack"></i>
                        <span>{{ $t('Accounting & Finance') }}</span>
                    </a>
                    <ul class="nav-group-items {{ in_array($active_page, ['invoices','ledger']) ? 'show' : '' }}">
                        <li class="nav-item"><a href="/invoices" class="nav-link {{ $active_page === 'invoices' ? 'active' : '' }}" title="{{ $t('Invoices') }}"><i class="nav-icon bi bi-receipt"></i> <span>{{ $t('Invoices') }}</span></a></li>
                        <li class="nav-item"><a href="/ledger" class="nav-link {{ $active_page === 'ledger' ? 'active' : '' }}" title="{{ $t('Ledger') }}"><i class="nav-icon bi bi-journal-bookmark"></i> <span>{{ $t('Ledger') }}</span></a></li>
                    </ul>
                </li>
                @if(in_array($user->role, ['superadmin','admin']))
                <li class="nav-group {{ in_array($active_page, ['admin','users','data_quality']) ? 'show' : '' }}">
                    <a class="nav-group-toggle" href="#">
                        <i class="nav-icon bi bi-gear"></i>
                        <span>{{ $t('Admin') }}</span>
                    </a>
                    <ul class="nav-group-items">
                        <li class="nav-item"><a href="/users" class="nav-link {{ $active_page === 'users' ? 'active' : '' }}" title="{{ $t('Users') }}"><i class="nav-icon bi bi-people"></i> <span>{{ $t('Users') }}</span></a></li>
                        <li class="nav-item"><a href="/data-quality" class="nav-link {{ $active_page === 'data_quality' ? 'active' : '' }}" title="{{ $t('Data Quality') }}"><i class="nav-icon bi bi-clipboard-check"></i> <span>{{ $t('Data Quality') }}</span></a></li>
                    </ul>
                    </li>
                @endif
            @endif
        </ul>
    </div>
    <div class="wrapper" id="main-content">
        <header class="header header-sticky mb-3 border-bottom">
            <div class="container-fluid px-3 px-md-4">
                <div class="header-content d-flex align-items-center justify-content-between py-2">
                    <div class="d-flex align-items-center gap-2">
                        <button class="header-toggler" id="sidebar-toggle" type="button" aria-label="Toggle navigation">
                            <i class="bi bi-list fs-5"></i>
                        </button>
                        @if($user->authenticated && in_array($user->role, ['superadmin','admin','management']))
                        <form method="post" action="/sync" class="m-0">
                            @csrf
                            <button id="sync-btn" type="submit" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-arrow-repeat me-1"></i><span id="sync-btn-label">{{ $t('Sync') }}</span>
                            </button>
                        </form>
                        <div id="sync-result" class="small ms-2"
                             data-progress="{{ $t('Syncing') }}"
                             data-ok="{{ $t('Sync OK') }}"
                             data-denied="{{ $t('Access denied') }}"
                             data-fail="{{ $t('Failed') }}"
                             data-unknown="{{ $t('unknown') }}">
                        </div>
                        @endif
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        @if($user->authenticated)
                            <span class="small text-body-secondary">{{ $user->displayName }}</span>
                            <form method="post" action="/logout" class="m-0">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-secondary" title="{{ $t('Logout') }}">
                                    <i class="bi bi-box-arrow-right" aria-label="Logout"></i>
                                </button>
                           </form>
                        @else
                            <a href="/login" class="btn btn-sm btn-primary">{{ $t('Login') }}</a>
                        @endif
                        @if($available_dbs->count() > 1)
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-info dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-database me-1"></i>{{ $active_db_name }}
                           </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                @foreach($available_dbs as $db)
                                <li>
                                    <form method="post" action="/set-db" class="m-0">
                                        <input type="hidden" name="db_name" value="{{ $db }}">
                                        <button class="dropdown-item {{ $db === $active_db_name ? 'active' : '' }}" type="submit">
                                            <i class="bi bi-database{{ $db === $active_db_name ? '-check' : '' }} me-1"></i>{{ $db }}
                                        </button>
                                   </form>
                               </li>
                                @endforeach
                           </ul>
                       </div>
                       @endif
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                {{ ['id' => 'ID', 'en' => 'EN', 'zh' => 'ZH'][$_lang] }}
                           </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                @foreach(['id' => 'Bahasa Indonesia', 'en' => 'English', 'zh' => '中文'] as $code => $label)
                                <li>
                                    <form method="post" action="/set-lang" class="m-0">
                                        @csrf
                                        <input type="hidden" name="lang" value="{{ $code }}">
                                        <button class="dropdown-item {{ $code === $_lang ? 'active' : '' }}" type="submit">{{ $label }}</button>
                                   </form>
                               </li>
                                @endforeach
                           </ul>
                       </div>
                        <button id="theme-toggle" class="btn btn-sm btn-outline-secondary" type="button">{{ $t('Light') }}</button>
                    </div>
                </div>
            </div>
        </header>
        <div class="body flex-grow-1 px-3 px-md-4 py-3">
            @yield('content')
        </div>
        <footer class="footer px-3 px-md-4 py-3 small text-body-secondary border-top mt-auto">
            {{ $t('Data synced from FoxPro system') }} • <span id="sync-time">{{ $sync_time }}</span>
       </footer>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
