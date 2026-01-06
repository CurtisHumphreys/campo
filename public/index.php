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

// --- Public API routes ---
$router->get('/api/public/intranet', function() { (new IntranetController())->publicActive(); });
$router->post('/api/waitlist', function() { (new SiteController())->submitWaitlist(); });
// NEW: Public Map Endpoint
$router->get('/api/public/sites-map', function() { (new SiteController())->publicMap(); });


// --- Protected API routes ---
if (Auth::check()) {
    $router->get('/api/dashboard-stats', function() { (new PaymentController())->dashboardStats(); });
    
    $router->get('/api/members', function() { (new MemberController())->index(); });
    $router->post('/api/members', function() { (new MemberController())->store(); });
    $router->post('/api/member/update', function() { (new MemberController())->update($_GET['id']); });
    $router->post('/api/member/delete', function() { (new MemberController())->delete($_GET['id']); });
    $router->post('/api/members/delete-all', function() { (new MemberController())->deleteAll(); });

    $router->get('/api/sites', function() { (new SiteController())->index(); });
    $router->post('/api/sites', function() { (new SiteController())->store(); });
    // NEW: Save Map Coords
    $router->post('/api/sites/map', function() { (new SiteController())->updateMapCoords(basename($_SERVER['REQUEST_URI'])); }); 
    // Wait... ^ The URI is /api/sites/123/map. basename is 'map'. 
    // We need regex or manual parsing for ID in URL. 
    // Let's use a simpler approach for now:
    // Actually, `updateMapCoords` is not in the Router logic above.
    // Let's rely on standard routes.
    
    // Quick Fix for Regex routing in simple router:
    // If we request /api/sites/123/map, the simple Router looks for exact match.
    // We need to register this manually or use a regex router.
    // For now, let's just use query param: /api/site/map?id=123
    // But my JS sends to `/sites/${id}/map`.
    
    // Let's change JS to use `/api/site/map-coords?id=123` or fix router.
    // For simplicity, I'll add a manual check in Router::dispatch or just handle it here.
    
    // Hacky fix for ID in URL for this specific project structure:
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (preg_match('#^/api/sites/(\d+)/map$#', $uri, $matches)) {
        (new SiteController())->updateMapCoords($matches[1]);
        exit;
    }

    $router->post('/api/site/allocate', function() { (new SiteController())->allocate(); });
    $router->post('/api/site/deallocate', function() { (new SiteController())->deallocate(); });
    $router->post('/api/site/waitlist-update', function() { (new SiteController())->updateWaitlist(); });
    $router->post('/api/site/waitlist-delete', function() { (new SiteController())->deleteWaitlist(); });

    $router->get('/api/camps', function() { (new CampController())->index(); });
    $router->get('/api/camps/active', function() { (new CampController())->active(); });
    $router->post('/api/camps', function() { (new CampController())->store(); });
    $router->post('/api/camp/update', function() { (new CampController())->update($_GET['id']); });
    $router->post('/api/camp/delete', function() { (new CampController())->delete($_GET['id']); });

    $router->get('/api/payments', function() { (new PaymentController())->index(); });
    $router->post('/api/payments', function() { (new PaymentController())->store(); });
    $router->post('/api/payment/delete', function() { (new PaymentController())->delete($_GET['id']); });

    $router->post('/api/import/legacy', function() { (new ImportController())->importLegacy(); });
    $router->post('/api/import/prepayments', function() { (new ImportController())->importPrepayments(); });
    $router->post('/api/import/rates', function() { (new ImportController())->importRates(); });
    $router->get('/api/prepayments', function() { (new PrepaymentController())->index(); });
    $router->post('/api/prepayments/match', function() { (new ImportController())->matchPrepayment(); });
    $router->post('/api/prepayments/delete-all', function() { (new PrepaymentController())->deleteAll(); });

    $router->get('/api/rates', function() { $campId = $_GET['camp_id'] ?? null; if ($campId) (new RateController())->index($campId); });
    $router->post('/api/rates', function() { (new RateController())->store(); });
    $router->post('/api/rate/update', function() { $id = $_GET['id'] ?? null; if ($id) (new RateController())->update($id); });
    $router->post('/api/rate/delete', function() { $id = $_GET['id'] ?? null; if ($id) (new RateController())->delete($id); });
    
    // Intranet Admin
    $router->get('/api/intranet/admin', function() { (new IntranetController())->adminGet(); });
    $router->post('/api/intranet/save', function() { (new IntranetController())->adminSave(); });
}

$router->dispatch();
