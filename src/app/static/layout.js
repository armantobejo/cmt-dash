// Default sidebar to narrow (collapsed) on first visit.
// CoreUI auto-init from data-coreui="sidebar" manages the sidebar-narrow
// class internally, so we must call toggleNarrow() via the JS API after
// CoreUI has constructed the Sidebar instance.  Use a localStorage flag
// so the first visit always starts narrow, but the user can still toggle.
(function () {
    var key = 'cmt_sidebar_narrow';
    var saved = localStorage.getItem(key);
    if (saved === null) {
        // First visit: default to narrow
        localStorage.setItem(key, '1');
        saved = '1';
    }
    if (saved === '1') {
        document.addEventListener('DOMContentLoaded', function () {
            var sidebar = document.getElementById('sidebar');
            if (!sidebar || !window.coreui || !coreui.Sidebar) return;
            var inst = coreui.Sidebar.getOrCreateInstance(sidebar);
            // CoreUI's _isNarrow() reads the class set in HTML, so it
            // should already be narrow.  Belt-and-suspenders: ensure it.
            if (!sidebar.classList.contains('sidebar-narrow')) {
                inst.toggleNarrow();
            }
        });
    }
})();

// Theme toggle: CoreUI uses data-coreui-theme on <html>; persist in localStorage.
(function () {
    const saved = localStorage.getItem('cmt_theme');
    if (saved) document.documentElement.setAttribute('data-coreui-theme', saved);

    const btn = document.getElementById('theme-toggle');
    if (btn) {
        btn.textContent = document.documentElement.getAttribute('data-coreui-theme') === 'dark' ? 'Light' : 'Dark';
        btn.addEventListener('click', () => {
            const next = document.documentElement.getAttribute('data-coreui-theme') === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-coreui-theme', next);
            localStorage.setItem('cmt_theme', next);
            btn.textContent = next === 'dark' ? 'Light' : 'Dark';
        });
    }
})();

// Sidebar toggle: CoreUI v5 only wires [data-coreui-toggle] for elements INSIDE
// the sidebar (values "narrow"/"unfoldable"), so the header hamburger needs an
// explicit handler. Delegated on document + lazy coreui lookup so it survives
// late bundle parse or DOM re-renders. Desktop: minimize/maximize (sidebar-
// narrow). Mobile: show/hide the off-canvas sidebar (toggle()).
document.addEventListener('click', function (e) {
    if (!e.target.closest('#sidebar-toggle')) return;
    const sidebar = document.getElementById('sidebar');
    if (!sidebar || !window.coreui || !coreui.Sidebar) return;
    const inst = coreui.Sidebar.getOrCreateInstance(sidebar);
    // CoreUI sets --cui-is-mobile: true below the lg breakpoint (CSS media query).
    const isMobile = window.getComputedStyle(sidebar).getPropertyValue('--cui-is-mobile').trim() !== '';
    if (isMobile) {
        inst.toggle();
    } else {
        inst.toggleNarrow();
    }
});

// CoreUI v5 narrow sidebar intercepts nav-link clicks to unfold first,
// swallowing the navigation.  Force navigate on any sidebar nav-link click.
document.addEventListener('click', function (e) {
    var link = e.target.closest('.sidebar-nav a.nav-link[href]');
    if (link) {
        window.location.href = link.getAttribute('href');
    }
});

// Sync button: POST /sync via fetch, show inline result.
// ponytail: blocks until done. Add SSE when sync exceeds 10s.
window.cmSyncSubmit = function (form) {
    const btn = document.getElementById('sync-btn');
    const label = document.getElementById('sync-btn-label');
    const out = document.getElementById('sync-result');
    const t = (k) => out ? out.dataset[k] || '' : '';
    const orig = label ? label.textContent : '';
    if (btn) btn.disabled = true;
    if (label) label.textContent = t('progress');
    if (out) out.textContent = '';

    fetch('/sync', { method: 'POST', credentials: 'same-origin' })
        .then(function (r) {
            return r.json().then(function (body) { return { status: r.status, body: body }; });
        })
        .then(function (res) {
            const body = res.body || {};
            if (res.status === 200 && body.ok) {
                if (out) out.innerHTML = '<span class="text-success">&#10003; ' + t('ok') + '</span>';
            } else if (res.status === 403) {
                if (out) out.innerHTML = '<span class="text-danger">' + t('denied') + '</span>';
            } else {
                if (out) out.innerHTML = '<span class="text-danger">' + t('fail') + ': ' + (body.detail || t('unknown')) + '</span>';
            }
        })
        .catch(function (err) {
            if (out) out.innerHTML = '<span class="text-danger">' + t('fail') + ': ' + err + '</span>';
        })
        .then(function () {
            if (btn) btn.disabled = false;
            if (label) label.textContent = orig;
        });
    return false;
};