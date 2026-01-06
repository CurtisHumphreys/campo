import { initAuth, logout } from './modules/auth.js';
import * as API from './api.js';

// Route modules
import { render as renderDashboard } from './modules/dashboard.js';
import { render as renderMembers } from './modules/members.js';
import { render as renderSites } from './modules/sites.js';
import { render as renderWaitlist } from './modules/waitlist.js';
import { render as renderPayments } from './modules/payments.js';
import { render as renderCamps } from './modules/camps.js';
import { render as renderImport } from './modules/import.js';
import { render as renderMap } from './modules/map.js?v=39'; // ✅ cache-bust + v39

import { render as renderIntranetAdmin } from './modules/intranet_admin.js';

const app = document.getElementById('app');

const routes = {
  '/': { auth: true, render: renderDashboard },
  '/dashboard': { auth: true, render: renderDashboard },
  '/members': { auth: true, render: renderMembers },
  '/sites': { auth: true, render: renderSites },
  '/waitlist': { auth: true, render: renderWaitlist },
  '/payments': { auth: true, render: renderPayments },
  '/camps': { auth: true, render: renderCamps },
  '/import': { auth: true, render: renderImport },
  '/map': { auth: true, render: renderMap },

  // Intranet admin editor (still inside admin app, requires login)
  '/intranet-admin': { auth: true, render: renderIntranetAdmin },
};

function normalizePath(path) {
  if (!path) return '/';
  const clean = path.split('?')[0].split('#')[0];
  return clean === '' ? '/' : clean;
}

function setActiveNav(path) {
  const links = document.querySelectorAll('[data-link]');
  links.forEach(a => {
    const p = a.getAttribute('data-link');
    if (p === path) a.classList.add('active');
    else a.classList.remove('active');
  });
}

function renderShell() {
  // Minimal shell to match your current structure (sidebar + topbar + content)
  // Keeps theme intact: uses existing CSS class names already in style.css.
  app.innerHTML = `
    <div class="layout">
      <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
          <div class="brand">Campo</div>
          <button class="hamburger" id="hamburger" aria-label="Menu">☰</button>
        </div>

        <nav class="sidebar-nav" id="sidebarNav">
          <a href="/dashboard" data-link="/dashboard">Dashboard</a>
          <a href="/members" data-link="/members">Members</a>
          <a href="/sites" data-link="/sites">Sites</a>
          <a href="/waitlist" data-link="/waitlist">Waitlist</a>
          <a href="/payments" data-link="/payments">Payments</a>
          <a href="/camps" data-link="/camps">Camps</a>
          <a href="/map" data-link="/map">Camp Map</a>
          <a href="/import" data-link="/import">Import</a>
          <a href="/intranet-admin" data-link="/intranet-admin">Intranet Admin</a>
        </nav>

        <div class="sidebar-footer">
          <button class="btn" id="logoutBtn">Log out</button>
        </div>
      </aside>

      <main class="main">
        <header class="topbar">
          <div class="topbar-title" id="topTitle">Campo</div>
        </header>
        <section class="content" id="content"></section>
      </main>
    </div>
  `;

  // hamburger toggle
  const hamburger = document.getElementById('hamburger');
  const sidebar = document.getElementById('sidebar');
  hamburger?.addEventListener('click', () => {
    sidebar?.classList.toggle('open');
  });

  // logout
  document.getElementById('logoutBtn')?.addEventListener('click', async () => {
    try { await logout(); } catch (e) {}
    window.location.href = '/login';
  });

  // SPA link handling
  document.querySelectorAll('[data-link]').forEach(link => {
    link.addEventListener('click', (e) => {
      e.preventDefault();
      const href = link.getAttribute('href') || link.getAttribute('data-link');
      navigateTo(href);
      // close sidebar on mobile
      sidebar?.classList.remove('open');
    });
  });
}

async function ensureAuth() {
  const res = await API.get('/check-auth');
  return !!(res && res.authenticated);
}

async function renderRoute(path) {
  const content = document.getElementById('content');
  const route = routes[path] || routes['/dashboard'];

  if (route.auth) {
    const authed = await ensureAuth();
    if (!authed) {
      window.location.href = '/login';
      return;
    }
  }

  setActiveNav(path);

  // Set title text
  const titleEl = document.getElementById('topTitle');
  if (titleEl) {
    const titles = {
      '/dashboard': 'Dashboard',
      '/members': 'Members',
      '/sites': 'Sites',
      '/waitlist': 'Waitlist',
      '/payments': 'Payments',
      '/camps': 'Camps',
      '/map': 'Camp Map',
      '/import': 'Import',
      '/intranet-admin': 'Intranet Admin',
    };
    titleEl.textContent = titles[path] || 'Campo';
  }

  // Render module into content
  try {
    await route.render(content);
  } catch (err) {
    console.error(err);
    content.innerHTML = `<div class="card"><h2>Error</h2><p>Failed to load page.</p></div>`;
  }
}

export function navigateTo(url) {
  const path = normalizePath(url);
  window.history.pushState(null, null, path);
  renderRoute(path);
}

async function init() {
  // Login page is separate, so app shell loads only for SPA pages
  renderShell();
  const path = normalizePath(window.location.pathname);
  await renderRoute(path);
}

window.addEventListener('popstate', () => {
  renderRoute(normalizePath(window.location.pathname));
});

// Boot
initAuth();
init();
