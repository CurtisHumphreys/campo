<?php
session_start();

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Router.php';
require_once __DIR__ . '/../src/Auth.php';

spl_autoload_register(function ($class) {
    $path = __DIR__ . '/../src/Controllers/' . $class . '.php';
    if (file_exists($path)) require_once $path;
});

$router = new Router();

function requireAuthJson() {
    if (!Auth::check()) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['message' => 'Unauthorised']);
        return false;
    }
    return true;
}

/**
 * PUBLIC PAGES (NO LOGIN)
 */
$router->get('/intranet', function() {
    $file = __DIR__ . '/intranet.html';
    if (file_exists($file)) readfile($file);
    else { http_response_code(404); echo "Not Found"; }
});

$router->get('/public-map', function() {
    $file = __DIR__ . '/public-map.html';
    if (file_exists($file)) readfile($file);
    else { http_response_code(404); echo "Not Found"; }
});

$router->get('/waitlist', function() {
    $file = __DIR__ . '/waitlist.html';
    if (file_exists($file)) readfile($file);
    else { http_response_code(404); echo "Not Found"; }
});

/**
 * SPA SHELL (APP)
 * These must serve index.html so the JS router can render pages.
 */
$router->get('/', function() {
    readfile(__DIR__ . '/index.html');
});

$spaPages = [
    '/login',
    '/dashboard',
    '/members',
    '/sites',
    '/payments',
    '/payment-records',
    '/camps',
    '/prepayments',
    '/import',
    '/rates',
    '/map',
    '/intranet-admin'
];

foreach ($spaPages as $page) {
    $router->get($page, function () {
        readfile(__DIR__ . '/index.html');
    });
}

/**
 * MIGRATIONS / DEBUG
 */
$router->get('/api/migrate', function() { (new MigrationController())->migrate(); });

/**
 * AUTH
 */
$router->post('/api/login', function() {
    header('Content-Type: application/json');
    $data = json_decode(file_get_contents('php://input'), true);

    if (Auth::login($data['username'] ?? '', $data['password'] ?? '')) {
        echo json_encode(['success' => true, 'user' => Auth::user()]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
    }
});

$router->post('/api/logout', function() {
    header('Content-Type: application/json');
    Auth::logout();
    echo json_encode(['success' => true]);
});

$router->get('/api/check-auth', function() {
    header('Content-Type: application/json');
    echo json_encode(Auth::check()
        ? ['authenticated' => true, 'user' => Auth::user()]
        : ['authenticated' => false]
    );
});

/**
 * PUBLIC APIs (NO LOGIN)
 */
$router->get('/api/public/intranet', function() { (new IntranetController())->publicActive(); });
$router->get('/api/public/sites-map', function() { (new IntranetController())->publicSitesMap(); });

// Waitlist submit is public (form)
$router->post('/api/waitlist', function() { (new SiteController())->storeWaitlist(); });

/**
 * INTRANET ADMIN (LOGIN REQUIRED)
 */
$router->get('/api/intranet', function() { if (!requireAuthJson()) return; (new IntranetController())->adminGet(); });
$router->post('/api/intranet', function() { if (!requireAuthJson()) return; (new IntranetController())->adminSave(); });

/**
 * WAITLIST ADMIN (LOGIN REQUIRED)
 */
$router->get('/api/site/waitlist', function() { if (!requireAuthJson()) return; (new SiteController())->waitlist(); });
$router->post('/api/site/waitlist-update', function() { if (!requireAuthJson()) return; (new SiteController())->updateWaitlist(); });
$router->post('/api/site/waitlist-delete', function() { if (!requireAuthJson()) return; (new SiteController())->deleteWaitlist(); });

/**
 * MEMBERS (LOGIN REQUIRED)
 */
$router->get('/api/members', function() { if (!requireAuthJson()) return; (new MemberController())->index(); });
$router->post('/api/members', function() { if (!requireAuthJson()) return; (new MemberController())->store(); });
$router->post('/api/members/delete-all', function() { if (!requireAuthJson()) return; (new MemberController())->deleteAll(); });
$router->post('/api/member/update', function() { if (!requireAuthJson()) return; (new MemberController())->update($_GET['id'] ?? null); });
$router->post('/api/member/delete', function() { if (!requireAuthJson()) return; (new MemberController())->delete($_GET['id'] ?? null); });
$router->get('/api/member/history', function() { if (!requireAuthJson()) return; (new MemberController())->history($_GET['id'] ?? null); });

/**
 * SITES (LOGIN REQUIRED)
 */
$router->get('/api/sites', function() { if (!requireAuthJson()) return; (new SiteController())->index(); });
$router->post('/api/sites', function() { if (!requireAuthJson()) return; (new SiteController())->store(); });
$router->post('/api/site/update', function() { if (!requireAuthJson()) return; (new SiteController())->update($_GET['id'] ?? null); });
$router->post('/api/sites/allocate', function() { if (!requireAuthJson()) return; (new SiteController())->allocate(); });
$router->get('/api/allocations', function() { if (!requireAuthJson()) return; (new SiteController())->allocations(); });

/**
 * CAMPS (LOGIN REQUIRED)
 */
$router->get('/api/camps', function() { if (!requireAuthJson()) return; (new CampController())->index(); });
$router->get('/api/camps/active', function() { if (!requireAuthJson()) return; (new CampController())->active(); });
$router->post('/api/camps', function() { if (!requireAuthJson()) return; (new CampController())->store(); });
$router->post('/api/camp/update', function() { if (!requireAuthJson()) return; (new CampController())->update($_GET['id'] ?? null); });
$router->post('/api/camp/delete', function() { if (!requireAuthJson()) return; (new CampController())->delete($_GET['id'] ?? null); });
$router->get('/api/camp/rates', function() { if (!requireAuthJson()) return; (new CampController())->rates($_GET['id'] ?? null); });

/**
 * PAYMENTS (LOGIN REQUIRED)
 * IMPORTANT: add aliases dashboard.js expects:
 * - /api/payments/summary
 * - /api/payments/dashboard-stats
 */
$router->get('/api/payments', function() { if (!requireAuthJson()) return; (new PaymentController())->index(); });
$router->post('/api/payments', function() { if (!requireAuthJson()) return; (new PaymentController())->store(); });
$router->get('/api/payment-records', function() { if (!requireAuthJson()) return; (new PaymentController())->records(); });
$router->post('/api/payment/delete', function() { if (!requireAuthJson()) return; (new PaymentController())->delete($_GET['id'] ?? null); });
$router->get('/api/payment/receipt', function() { if (!requireAuthJson()) return; (new PaymentController())->receipt($_GET['id'] ?? null); });

$router->get('/api/payments/summary', function() { if (!requireAuthJson()) return; (new PaymentController())->summary(); });
$router->get('/api/payments/dashboard-stats', function() { if (!requireAuthJson()) return; (new PaymentController())->dashboardStats(); });

// keep old path too, in case other code still calls it
$router->get('/api/dashboard-stats', function() { if (!requireAuthJson()) return; (new PaymentController())->dashboardStats(); });

/**
 * IMPORT (LOGIN REQUIRED)
 */
$router->post('/api/import', function() { if (!requireAuthJson()) return; (new ImportController())->upload(); });
$router->post('/api/import/members', function() { if (!requireAuthJson()) return; (new ImportController())->importMembers(); });
$router->post('/api/import/sites', function() { if (!requireAuthJson()) return; (new ImportController())->importSites(); });
$router->post('/api/import/prepayments', function() { if (!requireAuthJson()) return; (new ImportController())->importPrepayments(); });
$router->post('/api/import/rates', function() { if (!requireAuthJson()) return; (new ImportController())->importRates(); });

/**
 * PREPAYMENTS (LOGIN REQUIRED)
 */
$router->get('/api/prepayments', function() { if (!requireAuthJson()) return; (new PrepaymentController())->index(); });
$router->post('/api/prepayments/match', function() { if (!requireAuthJson()) return; (new ImportController())->matchPrepayment(); });
$router->post('/api/prepayments/delete-all', function() { if (!requireAuthJson()) return; (new PrepaymentController())->deleteAll(); });

/**
 * RATES (LOGIN REQUIRED)
 */
$router->get('/api/rates', function() { if (!requireAuthJson()) return; (new RateController())->index($_GET['camp_id'] ?? null); });
$router->post('/api/rates', function() { if (!requireAuthJson()) return; (new RateController())->store(); });
$router->post('/api/rate/update', function() { if (!requireAuthJson()) return; (new RateController())->update($_GET['id'] ?? null); });
$router->post('/api/rate/delete', function() { if (!requireAuthJson()) return; (new RateController())->delete($_GET['id'] ?? null); });

$router->dispatch();
