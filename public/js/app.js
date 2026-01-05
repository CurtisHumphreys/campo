import * as API from './api.js';
import * as Auth from './modules/auth.js';

// Define SPA routes. Keys are the pathname without trailing slash. Values are render functions.
const routes = {
    '/login': Auth.renderLogin,
    '/dashboard': (c) => import('./modules/dashboard.js').then(m => m.render(c)),
    '/members': (c) => import('./modules/members.js').then(m => m.render(c)),
    '/sites': (c) => import('./modules/sites.js').then(m => m.render(c)),
    '/payments': (c) => import('./modules/payments.js').then(m => m.render(c)),
    '/payment-records': (c) => import('./modules/payment_records.js').then(m => m.render(c)),
    '/camps': (c) => import('./modules/camps.js').then(m => m.render(c)),
    '/prepayments': (c) => import('./modules/prepayments.js').then(m => m.render(c)),
    '/import': (c) => import('./modules/import.js').then(m => m.render(c)),
    '/rates': (c) => import('./modules/rates.js').then(m => m.render(c)),
    '/map': (c) => import('./modules/map.js').then(m => m.render(c)),
    // Intranet admin management page (requires auth)
    '/intranet-admin': (c) => import('./modules/intranet_admin.js').then(m => m.render(c)),
};

// Prevent duplicate event bindings if init() runs more than once
let navSetupDone = false;

async function init() {
    // Always bind navigation handlers up-front (fixes hamburger not working after login redirect)
    setupNavigation();

    // Normalise the current path by stripping trailing slashes
    let path = window.location.pathname.replace(/\/$/, '');

    // Determine authentication status
    let isAuthenticated = false;
    try {
        const { authenticated } = await API.get('/check-auth');
        isAuthenticated = authenticated;
    } catch (e) {
        console.error('Auth check failed', e);
    }

    // If not authenticated, always redirect to login unless already on login
    if (!isAuthenticated && path !== '/login') {
        navigateTo('/login');
        return;
    }

    // If authenticated and visiting root or login, go to dashboard
    if (isAuthenticated && (path === '' || path === '/' || path === '/login')) {
        navigateTo('/dashboard');
        return;
    }

    // Show or hide sidebar/header based on authentication
    if (isAuthenticated) {
        document.getElementById('sidebar').classList.remove('hidden');
        if (window.innerWidth <= 900) {
            document.getElementById('mobile-header').style.display = 'flex';
        }
    } else {
        document.getElementById('sidebar').classList.add('hidden');
        document.getElementById('mobile-header').style.display = 'none';
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
            const href = link.getAttribute('href');
            if (href) navigateTo(href);
        }
    });

    const logoutBtn = document.getElementById('logout-btn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            await API.post('/logout', {});
            document.getElementById('sidebar').classList.add('hidden');
            navigateTo('/login');
        });
    }

    // New Mobile Menu Button Logic
    const mobileMenuBtn = document.getElementById('mobile-menu-btn');
    if (mobileMenuBtn) {
        mobileMenuBtn.addEventListener('click', (e) => {
            e.stopPropagation();
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
    const path = url.replace(/\/$/, '');

    // Toggling UI: if path is login, hide nav; otherwise show it if user is authenticated
    if (path === '/login') {
        document.getElementById('sidebar').classList.add('hidden');
        document.getElementById('mobile-header').style.display = 'none';
    } else {
        // Sidebar visibility is managed by auth state inside handleRoute
        // On small screens, ensure header is visible
        if (window.innerWidth <= 900) {
            document.getElementById('mobile-header').style.display = 'flex';
        }
    }

    document.body.classList.remove('mobile-menu-open');
    handleRoute(path);
}

async function handleRoute(path) {
    const main = document.getElementById('main-content');
    path = path.replace(/\/$/, '');
    if (path === '') path = '/dashboard';

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

    // Highlight active link in sidebar
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
