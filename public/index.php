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

function requireAuthJson() {
    if (!Auth::check()) {
        if (!headers_sent()) header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['message' => 'Unauthorised']);
        return false;
    }
    return true;
}

$router->get('/intranet', function() {
    if (file_exists(__DIR__ . '/intranet.html')) readfile(__DIR__ . '/intranet.html');
});

$router->get('/public-map', function() {
    if (file_exists(__DIR__ . '/public-map.html')) readfile(__DIR__ . '/public-map.html');
});

$router->get('/waitlist', function() {
    if (file_exists(__DIR__ . '/waitlist.html')) readfile(__DIR__ . '/waitlist.html');
});

$router->get('/api/migrate', function() { (new MigrationController())->migrate(); });

// Auth routes
$router->post('/api/login', function() {
    $data = json_decode(file_get_contents('php://input'), true);
    if (Auth::login($data['username'], $data['password'])) {
        echo json_encode(['success' => true, 'user' => Auth::user()]);
    } else {
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

// Public API (no login)
$router->get('/api/public/intranet', function() { (new IntranetController())->publicActive(); });
$router->get('/api/public/sites-map', function() { (new IntranetController())->publicSitesMap(); });

// Waitlist API
$router->post('/api/waitlist', function() { (new SiteController())->storeWaitlist(); });
$router->get('/api/site/waitlist', function() { if (!requireAuthJson()) return; (new SiteController())->waitlist(); });
$router->post('/api/site/waitlist-update', function() { if (!requireAuthJson()) return; (new SiteController())->updateWaitlist(); }); // New Route
$router->post('/api/site/waitlist-delete', function() { if (!requireAuthJson()) return; (new SiteController())->deleteWaitlist(); });

// Intranet Content (admin)
$router->get('/api/intranet', function() { if (!requireAuthJson()) return; (new IntranetController())->adminGet(); });
$router->post('/api/intranet', function() { if (!requireAuthJson()) return; (new IntranetController())->adminSave(); });

// Members
$router->get('/api/members', function() { if (!requireAuthJson()) return; (new MemberController())->index(); });
$router->post('/api/members', function() { if (!requireAuthJson()) return; (new MemberController())->store(); });
$router->post('/api/members/delete-all', function() { if (!requireAuthJson()) return; (new MemberController())->deleteAll(); });
$router->post('/api/member/update', function() { if (!requireAuthJson()) return; $id = $_GET['id'] ?? null; if ($id) (new MemberController())->update($id); });
$router->post('/api/member/delete', function() { if (!requireAuthJson()) return; $id = $_GET['id'] ?? null; if ($id) (new MemberController())->delete($id); });
$router->get('/api/member/history', function() { if (!requireAuthJson()) return; $id = $_GET['id'] ?? null; if ($id) (new MemberController())->history($id); });

// Sites
$router->get('/api/sites', function() { if (!requireAuthJson()) return; (new SiteController())->index(); });
$router->post('/api/sites', function() { if (!requireAuthJson()) return; (new SiteController())->store(); });
$router->post('/api/site/update', function() { if (!requireAuthJson()) return; $id = $_GET['id'] ?? null; if ($id) (new SiteController())->update($id); });
$router->post('/api/sites/allocate', function() { if (!requireAuthJson()) return; (new SiteController())->allocate(); });
$router->get('/api/allocations', function() { if (!requireAuthJson()) return; (new SiteController())->allocations(); });

// Camps
$router->get('/api/camps', function() { if (!requireAuthJson()) return; (new CampController())->index(); });
$router->get('/api/camps/active', function() { if (!requireAuthJson()) return; (new CampController())->active(); });
$router->post('/api/camps', function() { if (!requireAuthJson()) return; (new CampController())->store(); });
$router->get('/api/camp/rates', function() { if (!requireAuthJson()) return; $id = $_GET['id'] ?? null; if ($id) (new CampController())->rates($id); });
$router->post('/api/camp/update', function() { if (!requireAuthJson()) return; $id = $_GET['id'] ?? null; if ($id) (new CampController())->update($id); });
$router->post('/api/camp/delete', function() { if (!requireAuthJson()) return; $id = $_GET['id'] ?? null; if ($id) (new CampController())->delete($id); });

// Payments
$router->get('/api/payments', function() { if (!requireAuthJson()) return; (new PaymentController())->index(); });
$router->post('/api/payments', function() { if (!requireAuthJson()) return; (new PaymentController())->store(); });
$router->get('/api/payment-records', function() { if (!requireAuthJson()) return; (new PaymentController())->records(); });
$router->get('/api/payment/receipt', function() { if (!requireAuthJson()) return; $id = $_GET['id'] ?? null; if ($id) (new PaymentController())->receipt($id); });
$router->post('/api/payment/delete', function() { if (!requireAuthJson()) return; $id = $_GET['id'] ?? null; if ($id) (new PaymentController())->delete($id); });
$router->get('/api/dashboard-stats', function() { if (!requireAuthJson()) return; (new PaymentController())->dashboardStats(); });

// Import
$router->post('/api/import', function() { if (!requireAuthJson()) return; (new ImportController())->upload(); });
$router->post('/api/import/members', function() { if (!requireAuthJson()) return; (new ImportController())->importMembers(); });
$router->post('/api/import/sites', function() { if (!requireAuthJson()) return; (new ImportController())->importSites(); });
$router->post('/api/import/prepayments', function() { if (!requireAuthJson()) return; (new ImportController())->importPrepayments(); });
$router->post('/api/import/rates', function() { if (!requireAuthJson()) return; (new ImportController())->importRates(); });

// Prepayments
$router->get('/api/prepayments', function() { if (!requireAuthJson()) return; (new PrepaymentController())->index(); });
$router->post('/api/prepayments/match', function() { if (!requireAuthJson()) return; (new ImportController())->matchPrepayment(); });
$router->post('/api/prepayments/delete-all', function() { if (!requireAuthJson()) return; (new PrepaymentController())->deleteAll(); });

// Rates
$router->get('/api/rates', function() { if (!requireAuthJson()) return; $campId = $_GET['camp_id'] ?? null; if ($campId) (new RateController())->index($campId); });
$router->post('/api/rates', function() { if (!requireAuthJson()) return; (new RateController())->store(); });
$router->post('/api/rate/update', function() { if (!requireAuthJson()) return; $id = $_GET['id'] ?? null; if ($id) (new RateController())->update($id); });
$router->post('/api/rate/delete', function() { if (!requireAuthJson()) return; $id = $_GET['id'] ?? null; if ($id) (new RateController())->delete($id); });

// Revenue
$router->get('/api/revenue', function() {
    if (!requireAuthJson()) return;

    header('Content-Type: application/json');
    $db = Database::connect();

    // Total Revenue by tender
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
