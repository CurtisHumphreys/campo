import * as API from './api.js';
import * as Auth from './modules/auth.js';

const routes = {
    '/campo/login': Auth.renderLogin,
    '/campo/dashboard': (c) => import('./modules/dashboard.js').then(m => m.render(c)),
    '/campo/members': (c) => import('./modules/members.js').then(m => m.render(c)),
    '/campo/sites': (c) => import('./modules/sites.js').then(m => m.render(c)),
    '/campo/payments': (c) => import('./modules/payments.js').then(m => m.render(c)),
    '/campo/payment-records': (c) => import('./modules/payment_records.js').then(m => m.render(c)),
    '/campo/camps': (c) => import('./modules/camps.js').then(m => m.render(c)),
    '/campo/prepayments': (c) => import('./modules/prepayments.js').then(m => m.render(c)),
    '/campo/import': (c) => import('./modules/import.js').then(m => m.render(c)),
    '/campo/rates': (c) => import('./modules/rates.js').then(m => m.render(c)),
    '/campo/map': (c) => import('./modules/map.js').then(m => m.render(c)),
};

// Prevent duplicate event bindings if init() runs more than once
let navSetupDone = false;

// Track auth state across client-side navigations
let isAuthenticated = false;

export function setAuthState(state) {
    isAuthenticated = !!state;

    const sidebar = document.getElementById('sidebar');
    const mobileHeader = document.getElementById('mobile-header');

    // Keep UI consistent with auth state (prevents sidebar/hamburger bypassing login)
    if (!isAuthenticated) {
        if (sidebar) sidebar.classList.add('hidden');
        if (mobileHeader) mobileHeader.style.display = 'none';
        document.body.classList.remove('mobile-menu-open');
    } else {
        if (sidebar) sidebar.classList.remove('hidden');
        if (mobileHeader && window.innerWidth <= 900) mobileHeader.style.display = 'flex';
    }
}

function isPublicRoute(path) {
    return path === '/campo/map' || path.endsWith('/map');
}

function isLoginRoute(path) {
    return path === '/campo/login';
}

function enforceAuthForPath(path) {
    // Public route (map) is always allowed
    if (isPublicRoute(path)) return path;

    // If not authed, only login route is allowed
    if (!isAuthenticated && !isLoginRoute(path)) {
        return '/campo/login';
    }

    // If authed and user hits root or login, send them to dashboard
    if (isAuthenticated && (path === '/campo' || path === '/campo/' || path === '/campo/login')) {
        return '/campo/dashboard';
    }

    return path;
}

async function init() {
    // ✅ Always bind navigation handlers up-front (fixes hamburger not working after login redirect)
    setupNavigation();

    let path = window.location.pathname.replace(/\/$/, "");

    if (isPublicRoute(path)) {
        document.getElementById('sidebar').classList.add('hidden');
        document.getElementById('mobile-header').style.display = 'none';
        document.body.classList.add('public-view');
        handleRoute(path);
        return;
    }

    // Determine auth state once on load
    try {
        const { authenticated } = await API.get('/check-auth');
        setAuthState(authenticated);
    } catch (e) {
        console.error('Auth check failed', e);
        setAuthState(false);
    }

    const enforced = enforceAuthForPath(path);
    if (enforced !== path) {
        // Use replaceState to avoid back-button loop to protected route
        history.replaceState(null, null, enforced);
        path = enforced;
    }

    // Login route should not show the mobile header
    if (isLoginRoute(path)) {
        const mh = document.getElementById('mobile-header');
        if (mh) mh.style.display = 'none';
        document.getElementById('sidebar').classList.add('hidden');
    }

    handleRoute(path);
}

function setupNavigation() {
    if (navSetupDone) return;
    navSetupDone = true;

    document.body.addEventListener('click', e => {
        const link = e.target.closest('[data-link]');
        if (link) {
            e.preventDefault();
            navigateTo(link.getAttribute('href'));
        }
    });

    const logoutBtn = document.getElementById('logout-btn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            await API.post('/logout', {});
            setAuthState(false);
            navigateTo('/campo/login');
        });
    }

    // New Mobile Menu Button Logic
    const mobileMenuBtn = document.getElementById('mobile-menu-btn');
    if (mobileMenuBtn) {
        mobileMenuBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            // Don't allow hamburger to open a menu on login/unauth pages
            if (!isAuthenticated) return;

            const sidebar = document.getElementById('sidebar');
            if (sidebar && sidebar.classList.contains('hidden')) {
                // Ensure sidebar is visible before sliding it in
                sidebar.classList.remove('hidden');
            }
            document.body.classList.toggle('mobile-menu-open');
        });
    }

    // Close sidebar overlay click
    document.body.addEventListener('click', (e) => {
        if (document.body.classList.contains('mobile-menu-open')) {
            const sidebar = document.getElementById('sidebar');
            const header = document.getElementById('mobile-header');

            // If click is NOT inside sidebar and NOT inside header, close it
            if (!sidebar.contains(e.target) && !header.contains(e.target)) {
                document.body.classList.remove('mobile-menu-open');
            }
        }
    });
}

export function navigateTo(url) {
    history.pushState(null, null, url);
    let path = url.replace(/\/$/, "");

    // Enforce auth on client-side navigations too
    path = enforceAuthForPath(path);
    if (window.location.pathname.replace(/\/$/, "") !== path) {
        history.replaceState(null, null, path);
    }

    if (isPublicRoute(path)) {
        document.getElementById('sidebar').classList.add('hidden');
        document.getElementById('mobile-header').style.display = 'none';
        document.body.classList.add('public-view');
    } else {
        // Login route should not show nav
        if (isLoginRoute(path) || !isAuthenticated) {
            document.getElementById('sidebar').classList.add('hidden');
            document.getElementById('mobile-header').style.display = 'none';
        } else {
            document.getElementById('sidebar').classList.remove('hidden');
            if (window.innerWidth <= 900) {
                document.getElementById('mobile-header').style.display = 'flex';
            }
        }
        document.body.classList.remove('public-view');
    }

    document.body.classList.remove('mobile-menu-open');
    handleRoute(path);
}

async function handleRoute(path) {
    const main = document.getElementById('main-content');
    path = path.replace(/\/$/, "");
    if (path === '/campo') path = '/campo/dashboard';

    // Safety net: never render protected pages if not authed
    if (!isAuthenticated && !isLoginRoute(path) && !isPublicRoute(path)) {
        history.replaceState(null, null, '/campo/login');
        path = '/campo/login';
    }

    const renderer = routes[path];

    if (renderer) {
        main.innerHTML = '<div class="loader">Loading...</div>';
        try {
            await renderer(main);
        } catch (e) {
            console.error(e);
            main.innerHTML = `<div class="error">Error loading page: ${e.message}</div>`;
        }
    } else {
        main.innerHTML = '<h1>404 Not Found</h1>';
    }

    document.querySelectorAll('.nav-links a').forEach(a => {
        a.classList.remove('active');
        if (a.getAttribute('href') === path) {
            a.classList.add('active');
        }
    });
}

// Initial call
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
