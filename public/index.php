<?php
session_start();

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Router.php';
require_once __DIR__ . '/../src/Auth.php';

spl_autoload_register(function ($class) {
    if (file_exists(__DIR__ . '/../src/Controllers/' . $class . '.php')) {
        require_once __DIR__ . '/../src/Controllers/' . $class . '.php';
    }
});

$router = new Router();

$router->get('/api/migrate', function() { (new MigrationController())->migrate(); });

$router->post('/api/login', function() {
    $data = json_decode(file_get_contents('php://input'), true);
    if (Auth::login($data['username'], $data['password'])) {
        echo json_encode(['success' => true, 'user' => Auth::user()]);
    } else {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
    }
});

$router->post('/api/logout', function() {
    Auth::logout();
    echo json_encode(['success' => true]);
});

$router->get('/api/check-auth', function() {
    if (Auth::check()) {
        echo json_encode(['authenticated' => true, 'user' => Auth::user()]);
    } else {
        echo json_encode(['authenticated' => false]);
    }
});

// --- Public API routes for intranet and public map ---
// Expose active camp intranet content without requiring login
$router->get('/api/public/intranet', function() {
    (new IntranetController())->publicActive();
});
// Expose simplified site data for the public map
$router->get('/api/public/sites-map', function() {
    (new IntranetController())->publicSitesMap();
});

$router->get('/public-map', function() {
    // Serve the public map page (accessible without login)
    if (file_exists(__DIR__ . '/public-map.html')) readfile(__DIR__ . '/public-map.html');
});
$router->get('/waitlist', function() {
    if (file_exists(__DIR__ . '/waitlist.html')) readfile(__DIR__ . '/waitlist.html');
});

// --- Intranet public routes ---
// Redirect /intranet (without slash) to /intranet/ for PWA scope consistency
$router->get('/intranet', function() {
    header('Location: /intranet/', true, 302);
    exit;
});

// Serve intranet shell (no login required)
$router->get('/intranet/', function() {
    $file = __DIR__ . '/intranet/index.html';
    if (file_exists($file)) readfile($file);
    else { http_response_code(404); echo "Not Found"; }
});

// Serve intranet PWA manifest
$router->get('/intranet/manifest.json', function() {
    $file = __DIR__ . '/intranet/manifest.json';
    if (!file_exists($file)) { http_response_code(404); echo "Not Found"; return; }
    header('Content-Type: application/manifest+json');
    readfile($file);
});

// Serve intranet service worker
$router->get('/intranet/sw.js', function() {
    $file = __DIR__ . '/intranet/sw.js';
    if (!file_exists($file)) { http_response_code(404); echo "Not Found"; return; }
    header('Content-Type: application/javascript');
    readfile($file);
});


// Waitlist API
$router->post('/api/waitlist', function() { (new SiteController())->storeWaitlist(); });
$router->get('/api/site/waitlist', function() { (new SiteController())->waitlist(); });
$router->post('/api/site/waitlist-update', function() { (new SiteController())->updateWaitlist(); }); // New Route
$router->post('/api/site/waitlist-delete', function() { (new SiteController())->deleteWaitlist(); });

$router->get('/', function() { readfile(__DIR__ . '/index.html'); });

$pages = ['/login', '/dashboard', '/members', '/sites', '/payments', '/payment-records', '/settings', '/rates', '/camps', '/import', '/prepayments', '/map', '/intranet-admin'];
foreach ($pages as $page) {
    $router->get($page, function() { readfile(__DIR__ . '/index.html'); });
}

$router->post('/api/site/deallocate', function() { (new SiteController())->deallocate(); });

$router->get('/api/members', function() { (new MemberController())->index(); });
$router->post('/api/members', function() { (new MemberController())->store(); });
$router->post('/api/members/delete-all', function() { (new MemberController())->deleteAll(); });
$router->post('/api/member/update', function() { $id = $_GET['id'] ?? null; if ($id) (new MemberController())->update($id); });
$router->post('/api/member/delete', function() { $id = $_GET['id'] ?? null; if ($id) (new MemberController())->delete($id); });
$router->get('/api/member/history', function() { $id = $_GET['id'] ?? null; if ($id) (new MemberController())->history($id); });

$router->get('/api/sites', function() { (new SiteController())->index(); });
$router->post('/api/sites', function() { (new SiteController())->store(); });
$router->post('/api/site/update', function() { $id = $_GET['id'] ?? null; if ($id) (new SiteController())->update($id); });
$router->post('/api/sites/allocate', function() { (new SiteController())->allocate(); });
$router->get('/api/allocations', function() { (new SiteController())->allocations(); });

$router->get('/api/camps', function() { (new CampController())->index(); });
$router->get('/api/camps/active', function() { (new CampController())->active(); });
$router->post('/api/camps', function() { (new CampController())->store(); });
$router->get('/api/camp/rates', function() { $id = $_GET['id'] ?? null; if ($id) (new CampController())->rates($id); });
$router->post('/api/camp/update', function() { $id = $_GET['id'] ?? null; if ($id) (new CampController())->update($id); });
$router->post('/api/camp/delete', function() { $id = $_GET['id'] ?? null; if ($id) (new CampController())->delete($id); });

$router->post('/api/payments', function() { (new PaymentController())->store(); });
$router->get('/api/payments', function() { (new PaymentController())->index(); });
$router->post('/api/payment/update', function() { $id = $_GET['id'] ?? null; if ($id) (new PaymentController())->update($id); });
$router->post('/api/payment/delete', function() { $id = $_GET['id'] ?? null; if ($id) (new PaymentController())->delete($id); });
$router->get('/api/payments/summary', function() { (new PaymentController())->summary(); });
$router->get('/api/payments/dashboard-stats', function() { (new PaymentController())->dashboardStats(); });

// Intranet admin API routes (requires authentication; Auth::check will be handled in controller)
$router->get('/api/intranet', function() {
    (new IntranetController())->adminGet();
});
$router->post('/api/intranet', function() {
    (new IntranetController())->adminSave();
});

$router->post('/api/import', function() { (new ImportController())->upload(); });
$router->post('/api/import/members', function() { (new ImportController())->importMembers(); });
$router->post('/api/import/sites', function() { (new ImportController())->importSites(); });
$router->post('/api/import/prepayments', function() { (new ImportController())->importPrepayments(); });
$router->post('/api/import/rates', function() { (new ImportController())->importRates(); });
$router->get('/api/prepayments', function() { (new PrepaymentController())->index(); });
$router->post('/api/prepayments/match', function() { (new ImportController())->matchPrepayment(); });
$router->post('/api/prepayments/delete-all', function() { (new PrepaymentController())->deleteAll(); });

$router->get('/api/rates', function() { $campId = $_GET['camp_id'] ?? null; if ($campId) (new RateController())->index($campId); });
$router->post('/api/rates', function() { (new RateController())->store(); });
$router->post('/api/rate/update', function() { $id = $_GET['id'] ?? null; if ($id) (new RateController())->update($id); });
$router->post('/api/rate/delete', function() { $id = $_GET['id'] ?? null; if ($id) (new RateController())->delete($id); });

$router->get('/api/dashboard-stats-legacy', function() {
    $db = Database::connect();
    $stmt = $db->query("
        SELECT 
            SUM(amount) as total,
            SUM(CASE WHEN method = 'EFTPOS' THEN amount ELSE 0 END) as eftpos,
            SUM(CASE WHEN method = 'Cash' THEN amount ELSE 0 END) as cash,
            SUM(CASE WHEN method = 'Cheque' THEN amount ELSE 0 END) as cheque
        FROM payment_tenders
    ");
    $revenue = $stmt->fetch();
    echo json_encode([
        'total_revenue' => $revenue['total'],
        'eftpos' => $revenue['eftpos'],
        'cash' => $revenue['cash'],
        'cheque' => $revenue['cheque']
    ]);
});

$router->dispatch();