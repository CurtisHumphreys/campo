<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Router.php';
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/Mailer.php';

spl_autoload_register(function ($class) {
    if (file_exists(__DIR__ . '/src/Controllers/' . $class . '.php')) {
        require_once __DIR__ . '/src/Controllers/' . $class . '.php';
    }
});

$router = new Router();

$jsonError = function ($status, $message) {
    if (!headers_sent()) {
        http_response_code((int)$status);
        header('Content-Type: application/json');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }
    echo json_encode([
        'success' => false,
        'message' => $message
    ]);
};

$requireId = function ($name = 'id') use ($jsonError) {
    $value = $_GET[$name] ?? null;
    if ($value === null || $value === '') {
        $jsonError(400, 'Missing ID.');
        return null;
    }
    return $value;
};

$protected = function ($capability, callable $handler) {
    return function () use ($capability, $handler) {
        if (!Auth::requireCapability($capability)) {
            return;
        }
        $handler();
    };
};

$router->get('/api/migrate', $protected('manage_system', function () {
    (new MigrationController())->migrate();
}));

$router->post('/api/login', function () use ($jsonError) {
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $username = trim((string)($data['username'] ?? ''));
    $password = (string)($data['password'] ?? '');

    if (Auth::login($username, $password)) {
        echo json_encode(['success' => true, 'user' => Auth::user()]);
        return;
    }

    $jsonError(401, 'Invalid credentials');
});

$router->post('/api/logout', function () {
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    Auth::logout();
    echo json_encode(['success' => true]);
});

$router->get('/api/check-auth', function () {
    if (!headers_sent()) {
        header('Content-Type: application/json');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }

    $user = Auth::user();
    if ($user) {
        echo json_encode(['authenticated' => true, 'user' => $user]);
        return;
    }

    echo json_encode(['authenticated' => false]);
});

// Public intranet and public map routes
$router->get('/api/public/intranet', function () {
    (new IntranetController())->publicActive();
});
$router->post('/api/public/intranet/visit', function () {
    (new IntranetController())->publicTrackVisit();
});
$router->get('/api/public/intranet/features', function () {
    (new IntranetFeaturesController())->publicFeatures();
});
$router->post('/api/public/intranet/message', function () {
    (new IntranetFeaturesController())->publicSubmitMessage();
});
$router->post('/api/public/intranet/site-update', function () {
    (new IntranetFeaturesController())->publicSubmitSiteUpdate();
});
$router->post('/api/public/intranet/check-in', function () {
    (new IntranetFeaturesController())->publicSubmitCheckIn();
});
$router->get('/api/public/intranet/check-in-search', function () {
    (new IntranetFeaturesController())->publicSearchCheckInMembers();
});
$router->post('/api/public/intranet/lost-found', function () {
    (new IntranetFeaturesController())->publicSubmitLostFound();
});
$router->post('/api/public/intranet/noticeboard', function () {
    (new IntranetFeaturesController())->publicSubmitNoticeboard();
});
$router->post('/api/public/intranet/poll-response', function () {
    (new IntranetFeaturesController())->publicSubmitPollResponse();
});

// ── Campo user auth (OTP) ────────────────────────────────────────────────────
foreach (['/api/public/auth/request-otp', '/api/public/auth/verify-otp',
          '/api/public/auth/logout', '/api/public/auth/me',
          '/api/public/auth/update-profile'] as $_authPath) {
    $router->options($_authPath, function () { (new CampoAuthController())->preflight(); });
}
$router->post('/api/public/auth/request-otp', function () {
    (new CampoAuthController())->requestOtp();
});
$router->post('/api/public/auth/verify-otp', function () {
    (new CampoAuthController())->verifyOtp();
});
$router->post('/api/public/auth/logout', function () {
    (new CampoAuthController())->logout();
});
$router->get('/api/public/auth/me', function () {
    (new CampoAuthController())->me();
});
$router->post('/api/public/auth/update-profile', function () {
    (new CampoAuthController())->updateProfile();
});

$router->get('/api/public/sites-map', function () {
    (new IntranetController())->publicSitesMap();
});

$router->get('/api/public/map-config', function () {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = ['https://campo.urbantek.online', 'http://campo.urbantek.online', 'https://campo.nix.local', 'http://campo.nix.local'];
    header('Content-Type: application/json');
    if (in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
    try {
        $db = Database::connect();
        $key = $db->query("SELECT setting_value FROM app_settings WHERE setting_key='google_maps_api_key'")->fetchColumn() ?: '';
        $camp = $db->query("SELECT map_center_lat, map_center_lng FROM camps ORDER BY CASE WHEN status='active' THEN 0 ELSE 1 END, id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        echo json_encode([
            'google_maps_api_key' => $key,
            'map_center_lat' => $camp['map_center_lat'] ?? null,
            'map_center_lng' => $camp['map_center_lng'] ?? null,
        ]);
    } catch (Exception $e) {
        echo json_encode(['google_maps_api_key' => '', 'map_center_lat' => null, 'map_center_lng' => null]);
    }
});

$router->get('/public-map', function () {
    if (file_exists(__DIR__ . '/public-map.html')) {
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . '/public-map.html');
    }
});
$router->get('/waitlist', function () {
    if (file_exists(__DIR__ . '/waitlist.html')) {
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . '/waitlist.html');
    }
});

$router->get('/intranet', function () {
    $file = __DIR__ . '/intranet/index.html';
    if (file_exists($file)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($file);
        return;
    }
    http_response_code(404);
    echo "Not Found";
});

$router->get('/intranet/manifest.json', function () {
    $file = __DIR__ . '/intranet/manifest.json';
    if (!file_exists($file)) {
        http_response_code(404);
        echo "Not Found";
        return;
    }
    header('Content-Type: application/manifest+json');
    readfile($file);
});

$router->get('/intranet/sw.js', function () {
    $file = __DIR__ . '/intranet/sw.js';
    if (!file_exists($file)) {
        http_response_code(404);
        echo "Not Found";
        return;
    }
    header('Content-Type: application/javascript');
    readfile($file);
});

// Public waitlist submission
$router->post('/api/waitlist', function () {
    (new SiteController())->storeWaitlist();
});

$router->get('/', function () {
    header('Location: /login', true, 302);
    exit;
});

$pages = ['/login', '/dashboard', '/members', '/sites', '/payments', '/payment-records', '/settings', '/rates', '/camps', '/import', '/prepayments', '/map', '/intranet-admin'];
foreach ($pages as $page) {
    $router->get($page, function () {
        header('Content-Type: text/html; charset=utf-8');
        readfile('/var/www/html/apps/campoffice/index.html');
    });
}

// Operational app routes
$router->get('/api/site/waitlist', $protected('access_operations', function () {
    (new SiteController())->waitlist();
}));
$router->post('/api/site/waitlist-update', $protected('access_operations', function () {
    (new SiteController())->updateWaitlist();
}));
$router->post('/api/site/waitlist-delete', $protected('access_operations', function () {
    (new SiteController())->deleteWaitlist();
}));
$router->post('/api/site/deallocate', $protected('access_operations', function () {
    (new SiteController())->deallocate();
}));

$router->get('/api/members', $protected('access_operations', function () {
    (new MemberController())->index();
}));
$router->post('/api/members', $protected('access_operations', function () {
    (new MemberController())->store();
}));
$router->post('/api/members/delete-all', $protected('access_operations', function () {
    (new MemberController())->deleteAll();
}));
$router->post('/api/member/update', $protected('access_operations', function () use ($requireId) {
    $id = $requireId();
    if ($id !== null) {
        (new MemberController())->update($id);
    }
}));
$router->post('/api/member/merge', $protected('access_operations', function () use ($requireId) {
    $id = $requireId();
    if ($id !== null) {
        (new MemberController())->merge($id);
    }
}));
$router->post('/api/member/delete', $protected('access_operations', function () use ($requireId) {
    $id = $requireId();
    if ($id !== null) {
        (new MemberController())->delete($id);
    }
}));
$router->get('/api/member/history', $protected('access_operations', function () use ($requireId) {
    $id = $requireId();
    if ($id !== null) {
        (new MemberController())->history($id);
    }
}));
$router->get('/api/household', $protected('access_operations', function () use ($requireId) {
    $id = $requireId();
    if ($id !== null) {
        (new HouseholdController())->show($id);
    }
}));
$router->post('/api/household/update', $protected('access_operations', function () use ($requireId) {
    $id = $requireId();
    if ($id !== null) {
        (new HouseholdController())->update($id);
    }
}));
$router->post('/api/household/ensure', $protected('access_operations', function () use ($requireId) {
    $id = $requireId('member_id');
    if ($id !== null) {
        (new HouseholdController())->ensureForMember($id);
    }
}));
$router->post('/api/household/agreement/upload', $protected('access_operations', function () use ($requireId) {
    $id = $requireId('household_id');
    if ($id !== null) {
        (new HouseholdController())->uploadAgreement($id);
    }
}));
$router->post('/api/household/agreement/delete', $protected('access_operations', function () {
    (new HouseholdController())->deleteAgreement();
}));

$router->get('/api/sites', $protected('access_operations', function () {
    (new SiteController())->index();
}));
$router->post('/api/sites', $protected('access_operations', function () {
    (new SiteController())->store();
}));
$router->post('/api/site/update', $protected('access_operations', function () use ($requireId) {
    $id = $requireId();
    if ($id !== null) {
        (new SiteController())->update($id);
    }
}));
$router->post('/api/site/map-pin', $protected('access_operations', function () {
    (new SiteController())->saveMapPin();
}));
$router->post('/api/site/map-pin/delete', $protected('access_operations', function () {
    (new SiteController())->deleteMapPin();
}));
$router->post('/api/site/map-pins/bulk-delete', $protected('access_operations', function () {
    (new SiteController())->bulkDeleteMapPins();
}));
$router->get('/api/site/revisions', $protected('access_operations', function () {
    (new SiteController())->revisions();
}));
$router->post('/api/sites/allocate', $protected('access_operations', function () {
    (new SiteController())->allocate();
}));
$router->get('/api/allocations', $protected('access_operations', function () {
    (new SiteController())->allocations();
}));

$router->get('/api/camps', $protected('access_operations', function () {
    (new CampController())->index();
}));
$router->get('/api/camps/active', $protected('access_operations', function () {
    (new CampController())->active();
}));
$router->post('/api/camps', $protected('access_operations', function () {
    (new CampController())->store();
}));
$router->get('/api/camp/rates', $protected('access_operations', function () use ($requireId) {
    $id = $requireId();
    if ($id !== null) {
        (new CampController())->rates($id);
    }
}));
$router->post('/api/camp/update', $protected('access_operations', function () use ($requireId) {
    $id = $requireId();
    if ($id !== null) {
        (new CampController())->update($id);
    }
}));
$router->post('/api/camp/delete', $protected('access_operations', function () use ($requireId) {
    $id = $requireId();
    if ($id !== null) {
        (new CampController())->delete($id);
    }
}));
$router->post('/api/camp/churchsuite-sync', $protected('access_operations', function () use ($requireId) {
    $id = $requireId();
    if ($id !== null) {
        (new ChurchSuiteController())->syncCamp($id);
    }
}));
$router->get('/api/churchsuite/status', $protected('access_operations', function () {
    (new ChurchSuiteController())->status();
}));
$router->get('/api/churchsuite/events', $protected('access_operations', function () {
    (new ChurchSuiteController())->events();
}));
$router->get('/api/churchsuite/diagnostics', $protected('access_operations', function () {
    (new ChurchSuiteController())->diagnostics();
}));
$router->post('/api/churchsuite/directory-sync', $protected('access_operations', function () {
    (new ChurchSuiteController())->syncDirectory();
}));
$router->get('/api/churchsuite/oauth/start', $protected('access_operations', function () {
    (new ChurchSuiteController())->startAuthorization();
}));
$router->post('/api/churchsuite/oauth/disconnect', $protected('access_operations', function () {
    (new ChurchSuiteController())->disconnect();
}));
$router->get('/api/churchsuite/oauth/callback', function () {
    (new ChurchSuiteController())->oauthCallback();
});

$router->post('/api/payments', $protected('access_operations', function () {
    (new PaymentController())->store();
}));
$router->get('/api/payments', $protected('access_operations', function () {
    (new PaymentController())->index();
}));
$router->get('/api/payment-records/check-ins', $protected('access_operations', function () {
    (new PaymentController())->checkInRecords();
}));
$router->get('/api/payment-records/site-fee-audit', $protected('access_operations', function () {
    (new PaymentController())->siteFeeAudit();
}));
$router->post('/api/payment-records/site-fee-audit/recalculate', $protected('access_operations', function () use ($requireId) {
    $id = $requireId('member_id');
    if ($id !== null) {
        (new PaymentController())->siteFeeAuditRecalculate($id);
    }
}));
$router->post('/api/payment-records/site-fee-audit/apply-expected', $protected('access_operations', function () use ($requireId) {
    $id = $requireId('member_id');
    if ($id !== null) {
        (new PaymentController())->siteFeeAuditApplyExpected($id);
    }
}));
$router->post('/api/payment-records/site-fee-audit/custom', $protected('access_operations', function () use ($requireId) {
    $id = $requireId('member_id');
    if ($id !== null) {
        (new PaymentController())->siteFeeAuditSetCustom($id);
    }
}));
$router->post('/api/payment-records/site-fee-audit/review', $protected('access_operations', function () use ($requireId) {
    $id = $requireId('member_id');
    if ($id !== null) {
        (new PaymentController())->siteFeeAuditReview($id);
    }
}));
$router->post('/api/payment/update', $protected('access_operations', function () use ($requireId) {
    $id = $requireId();
    if ($id !== null) {
        (new PaymentController())->update($id);
    }
}));
$router->post('/api/payment/delete', $protected('access_operations', function () use ($requireId) {
    $id = $requireId();
    if ($id !== null) {
        (new PaymentController())->delete($id);
    }
}));
$router->get('/api/payments/summary', $protected('access_operations', function () {
    (new PaymentController())->summary();
}));
$router->get('/api/dashboard/overview', $protected('access_operations', function () {
    (new PaymentController())->dashboardOverview();
}));
$router->get('/api/payments/dashboard-stats', $protected('access_operations', function () {
    (new PaymentController())->dashboardStats();
}));

$router->post('/api/import', $protected('access_operations', function () {
    (new ImportController())->upload();
}));
$router->post('/api/import/members', $protected('access_operations', function () {
    (new ImportController())->importMembers();
}));
$router->post('/api/import/sites', $protected('access_operations', function () {
    (new ImportController())->importSites();
}));
$router->post('/api/import/prepayments', $protected('access_operations', function () {
    (new ImportController())->importPrepayments();
}));
$router->post('/api/import/rates', $protected('access_operations', function () {
    (new ImportController())->importRates();
}));
$router->get('/api/prepayments', $protected('access_operations', function () {
    (new PrepaymentController())->index();
}));
$router->post('/api/prepayments/match', $protected('access_operations', function () {
    (new ImportController())->matchPrepayment();
}));
$router->post('/api/prepayments/reset', $protected('access_operations', function () {
    (new PrepaymentController())->reset();
}));
$router->post('/api/prepayments/delete-all', $protected('access_operations', function () {
    (new PrepaymentController())->deleteAll();
}));

$router->get('/api/rates', $protected('access_operations', function () use ($jsonError) {
    $campId = $_GET['camp_id'] ?? null;
    if ($campId) {
        (new RateController())->index($campId);
        return;
    }
    $jsonError(400, 'Missing camp ID.');
}));
$router->post('/api/rates', $protected('access_operations', function () {
    (new RateController())->store();
}));
$router->post('/api/rate/update', $protected('access_operations', function () use ($requireId) {
    $id = $requireId();
    if ($id !== null) {
        (new RateController())->update($id);
    }
}));
$router->post('/api/rate/delete', $protected('access_operations', function () use ($requireId) {
    $id = $requireId();
    if ($id !== null) {
        (new RateController())->delete($id);
    }
}));

$router->get('/api/dashboard-stats-legacy', $protected('access_operations', function () {
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
}));

// Intranet admin routes
$router->get('/api/intranet', $protected('access_intranet', function () {
    (new IntranetController())->adminGet();
}));
$router->post('/api/intranet', $protected('access_intranet', function () {
    (new IntranetController())->adminSave();
}));
$router->get('/api/intranet/features', $protected('access_intranet', function () {
    (new IntranetFeaturesController())->adminFeatures();
}));
$router->get('/api/admin/notifications', $protected('access_intranet', function () {
    (new IntranetFeaturesController())->adminNotifications();
}));
$router->post('/api/admin/notifications/mark-read', $protected('access_intranet', function () {
    (new IntranetFeaturesController())->adminMarkNotificationRead();
}));
$router->post('/api/admin/notifications/mark-all-read', $protected('access_intranet', function () {
    (new IntranetFeaturesController())->adminMarkAllNotificationsRead();
}));
$router->get('/api/admin/notification-recipients', $protected('access_intranet', function () {
    (new IntranetFeaturesController())->adminNotificationRecipients();
}));
$router->post('/api/admin/notification-recipients/save', $protected('access_intranet', function () {
    (new IntranetFeaturesController())->adminSaveNotificationRecipient();
}));
$router->post('/api/admin/notification-recipients/delete', $protected('access_intranet', function () {
    (new IntranetFeaturesController())->adminDeleteNotificationRecipient();
}));
$router->post('/api/admin/notification-recipients/test', $protected('access_intranet', function () {
    (new IntranetFeaturesController())->adminTestNotificationRecipient();
}));
$router->post('/api/intranet/message/update', $protected('access_intranet', function () use ($requireId) {
    $id = $requireId();
    if ($id !== null) {
        (new IntranetFeaturesController())->adminUpdateMessage($id);
    }
}));
$router->post('/api/intranet/message/delete', $protected('access_intranet', function () use ($requireId) {
    $id = $requireId();
    if ($id !== null) {
        (new IntranetFeaturesController())->adminDeleteMessage($id);
    }
}));
$router->post('/api/intranet/site-update/update', $protected('access_intranet', function () use ($requireId) {
    $id = $requireId();
    if ($id !== null) {
        (new IntranetFeaturesController())->adminUpdateSiteUpdate($id);
    }
}));
$router->post('/api/intranet/site-update/delete', $protected('access_intranet', function () use ($requireId) {
    $id = $requireId();
    if ($id !== null) {
        (new IntranetFeaturesController())->adminDeleteSiteUpdate($id);
    }
}));
$router->post('/api/intranet/check-in/update', $protected('access_operations', function () use ($requireId) {
    $id = $requireId();
    if ($id !== null) {
        (new IntranetFeaturesController())->adminUpdateCheckIn($id);
    }
}));
$router->post('/api/intranet/check-in/delete', $protected('access_operations', function () use ($requireId) {
    $id = $requireId();
    if ($id !== null) {
        (new IntranetFeaturesController())->adminDeleteCheckIn($id);
    }
}));
// ── Campo app user accounts (admin) ──────────────────────────────────────────
$router->get('/api/intranet/campo-users', $protected('access_intranet', function () {
    $db   = Database::connect();
    $rows = $db->query(
        "SELECT cu.id, cu.email, cu.name, cu.phone, cu.household_id,
                h.name AS household_name,
                cu.created_at, cu.last_login_at,
                (SELECT COUNT(*) FROM campo_sessions cs WHERE cs.user_id = cu.id AND cs.expires_at > NOW()) AS active_sessions,
                sa.site_id,
                s.site_number
         FROM campo_users cu
         LEFT JOIN households h  ON h.id  = cu.household_id
         LEFT JOIN site_allocations sa ON sa.household_id = cu.household_id
         LEFT JOIN sites s ON s.id = sa.site_id
         ORDER BY cu.last_login_at DESC, cu.created_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rows);
}));

$router->post('/api/intranet/campo-user/link-household', $protected('access_intranet', function () use ($requireId) {
    $id = $requireId();
    if ($id === null) return;
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $householdId = isset($data['household_id']) ? (int)$data['household_id'] : null;
    $db = Database::connect();
    $db->prepare("UPDATE campo_users SET household_id = ? WHERE id = ?")->execute([$householdId ?: null, $id]);
    echo json_encode(['success' => true]);
}));

$router->post('/api/intranet/campo-user/delete', $protected('access_intranet', function () use ($requireId) {
    $id = $requireId();
    if ($id === null) return;
    $db = Database::connect();
    $db->prepare("DELETE FROM campo_sessions WHERE user_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM campo_users WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
}));

$router->get('/api/intranet/campo-user/household-search', $protected('access_intranet', function () {
    $q  = trim((string)($_GET['q'] ?? ''));
    $db = Database::connect();
    if ($q === '') {
        echo json_encode([]);
        return;
    }
    $like = '%' . $q . '%';
    $stmt = $db->prepare(
        "SELECT h.id, h.name,
                s.site_number,
                (SELECT COUNT(*) FROM members m WHERE m.household_id = h.id) AS member_count
         FROM households h
         LEFT JOIN site_allocations sa ON sa.household_id = h.id
         LEFT JOIN sites s ON s.id = sa.site_id
         WHERE h.name LIKE ? OR s.site_number LIKE ?
         ORDER BY h.name LIMIT 20"
    );
    $stmt->execute([$like, $like]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
}));

$router->post('/api/intranet/lost-found/save', $protected('access_intranet', function () {
    $id = $_GET['id'] ?? null;
    (new IntranetFeaturesController())->adminSaveLostFound($id);
}));
$router->post('/api/intranet/lost-found/delete', $protected('access_intranet', function () use ($requireId) {
    $id = $requireId();
    if ($id !== null) {
        (new IntranetFeaturesController())->adminDeleteLostFound($id);
    }
}));
$router->post('/api/intranet/noticeboard/save', $protected('access_intranet', function () {
    $id = $_GET['id'] ?? null;
    (new IntranetFeaturesController())->adminSaveNoticeboard($id);
}));
$router->post('/api/intranet/noticeboard/delete', $protected('access_intranet', function () use ($requireId) {
    $id = $requireId();
    if ($id !== null) {
        (new IntranetFeaturesController())->adminDeleteNoticeboard($id);
    }
}));
$router->post('/api/intranet/poll/save', $protected('access_intranet', function () {
    $id = $_GET['id'] ?? null;
    (new IntranetFeaturesController())->adminSavePoll($id);
}));
$router->post('/api/intranet/poll/delete', $protected('access_intranet', function () use ($requireId) {
    $id = $requireId();
    if ($id !== null) {
        (new IntranetFeaturesController())->adminDeletePoll($id);
    }
}));

// Full admin user management routes
$router->get('/api/users', $protected('manage_users', function () {
    (new UserController())->index();
}));
$router->post('/api/users', $protected('manage_users', function () {
    (new UserController())->store();
}));
$router->post('/api/user/update', $protected('manage_users', function () use ($requireId) {
    $id = $requireId();
    if ($id !== null) {
        (new UserController())->update($id);
    }
}));
$router->post('/api/user/delete', $protected('manage_users', function () use ($requireId) {
    $id = $requireId();
    if ($id !== null) {
        (new UserController())->delete($id);
    }
}));

// ── v2 routes (last-registered wins in this router) ───────────────────────────

// Dashboard overview
$router->get('/api/dashboard', $protected('access_operations', function () {
    $db = Database::connect();
    $memberCount = $db->query("SELECT COUNT(*) FROM members")->fetchColumn();
    $siteCount   = $db->query("SELECT COUNT(*) FROM sites")->fetchColumn();
    $occupied    = $db->query("SELECT COUNT(*) FROM sites WHERE status='allocated'")->fetchColumn();
    $camp        = $db->query("SELECT * FROM camps WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $balance     = $db->query("SELECT COALESCE(SUM(amount),0) FROM payment_tenders")->fetchColumn();
    echo json_encode([
        'members'     => (int)$memberCount,
        'sites'       => (int)$siteCount,
        'occupied'    => (int)$occupied,
        'active_camp' => $camp ?: null,
        'balance'     => (float)$balance,
    ]);
}));

// Activate a camp
$router->post('/api/camp/set-active', $protected('access_operations', function () use ($requireId) {
    $id = $requireId();
    if ($id === null) return;
    $db = Database::connect();
    $db->prepare("UPDATE camps SET status='closed' WHERE status='active'")->execute();
    $db->prepare("UPDATE camps SET status='active' WHERE id=?")->execute([$id]);
    echo json_encode(['ok' => true]);
}));

// Member payment balance
$router->get('/api/member/balance', $protected('access_operations', function () {
    $memberId = isset($_GET['member_id']) ? (int)$_GET['member_id'] : null;
    if (!$memberId) { http_response_code(400); echo json_encode(['error' => 'member_id required']); return; }
    $db = Database::connect();
    $paid    = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM payment_tenders WHERE member_id=?");
    $paid->execute([$memberId]);
    $recent  = $db->prepare("SELECT * FROM payment_tenders WHERE member_id=? ORDER BY created_at DESC LIMIT 20");
    $recent->execute([$memberId]);
    echo json_encode(['balance' => (float)$paid->fetchColumn(), 'payments' => $recent->fetchAll(PDO::FETCH_ASSOC)]);
}));

// Update individual prepayment
$router->post('/api/prepayment/update', $protected('access_operations', function () use ($requireId) {
    $id = $requireId();
    if ($id === null) return;
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $db = Database::connect();
    $db->prepare("UPDATE prepayments SET imported_name=?, amount=?, notes=? WHERE id=?")
       ->execute([$data['imported_name'] ?? $data['name'] ?? '', $data['amount'] ?? 0, $data['notes'] ?? '', $id]);
    echo json_encode(['ok' => true]);
}));

// Delete individual prepayment
$router->post('/api/prepayment/delete', $protected('access_operations', function () use ($requireId) {
    $id = $requireId();
    if ($id === null) return;
    Database::connect()->prepare("DELETE FROM prepayments WHERE id=?")->execute([$id]);
    echo json_encode(['ok' => true]);
}));

// Change current user password
$router->post('/api/user/change-password', $protected('access_operations', function () {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $current = $data['current_password'] ?? '';
    $newPass = $data['new_password'] ?? '';
    if (!$newPass) { http_response_code(400); echo json_encode(['error' => 'new_password required']); return; }
    $session = Auth::getSession();
    $db = Database::connect();
    $user = $db->prepare("SELECT * FROM users WHERE id=?");
    $user->execute([$session['user_id']]);
    $row = $user->fetch(PDO::FETCH_ASSOC);
    if (!$row || !password_verify($current, $row['password_hash'])) {
        http_response_code(403); echo json_encode(['error' => 'Current password incorrect']); return;
    }
    $hash = password_hash($newPass, PASSWORD_DEFAULT);
    $db->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$hash, $row['id']]);
    echo json_encode(['ok' => true]);
}));

// Config endpoint — serves non-secret config to any logged-in user
$router->get('/api/config/maps', function () {
    Auth::requireLogin();
    $db = Database::connect();
    try {
        $key = $db->query("SELECT setting_value FROM app_settings WHERE setting_key='google_maps_api_key'")->fetchColumn();
        echo json_encode(['google_maps_api_key' => $key ?: '']);
    } catch (Exception $e) { echo json_encode(['google_maps_api_key' => '']); }
});

// Settings (key-value in a simple table, create on first use)
$router->get('/api/settings', $protected('manage_users', function () {
    $db = Database::connect();
    try {
        $rows = $db->query("SELECT setting_key, setting_value FROM app_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        echo json_encode($rows ?: (object)[]);
    } catch (Exception $e) { echo json_encode((object)[]); }
}));
$router->post('/api/settings', $protected('manage_users', function () {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $db = Database::connect();
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS app_settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT)");
        $stmt = $db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?");
        foreach ($data as $k => $v) { $stmt->execute([$k, $v, $v]); }
        echo json_encode(['ok' => true]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
}));

// Program sessions
$router->get('/api/intranet/program', $protected('access_intranet', function () {
    $db = Database::connect();
    $camp = $db->query("SELECT id FROM camps WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$camp) { echo json_encode([]); return; }
    $stmt = $db->prepare("SELECT * FROM program_sessions WHERE camp_id=? ORDER BY date ASC, start_time ASC");
    $stmt->execute([$camp['id']]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
}));
$router->post('/api/intranet/session/create', $protected('access_intranet', function () {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $db = Database::connect();
    $camp = $db->query("SELECT id FROM camps WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$camp) { http_response_code(400); echo json_encode(['error' => 'No active camp']); return; }
    $stmt = $db->prepare("INSERT INTO program_sessions (camp_id, title, date, start_time, end_time, location, description) VALUES (?,?,?,?,?,?,?)");
    $stmt->execute([$camp['id'], $data['title'] ?? '', $data['date'] ?? null, $data['start_time'] ?? null, $data['end_time'] ?? null, $data['location'] ?? null, $data['description'] ?? null]);
    echo json_encode(['id' => $db->lastInsertId()]);
}));
$router->post('/api/intranet/session/update', $protected('access_intranet', function () use ($requireId) {
    $id = $requireId();
    if ($id === null) return;
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    Database::connect()->prepare("UPDATE program_sessions SET title=?, date=?, start_time=?, end_time=?, location=?, description=? WHERE id=?")
        ->execute([$data['title'] ?? '', $data['date'] ?? null, $data['start_time'] ?? null, $data['end_time'] ?? null, $data['location'] ?? null, $data['description'] ?? null, $id]);
    echo json_encode(['ok' => true]);
}));
$router->post('/api/intranet/session/delete', $protected('access_intranet', function () use ($requireId) {
    $id = $requireId();
    if ($id === null) return;
    Database::connect()->prepare("DELETE FROM program_sessions WHERE id=?")->execute([$id]);
    echo json_encode(['ok' => true]);
}));

// Intranet list endpoints
$router->get('/api/intranet/noticeboard/list', $protected('access_intranet', function () {
    $db = Database::connect();
    $camp = $db->query("SELECT id FROM camps WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$camp) { echo json_encode([]); return; }
    $stmt = $db->prepare("SELECT * FROM camp_intranet_noticeboard WHERE camp_id=? ORDER BY created_at DESC");
    $stmt->execute([$camp['id']]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
}));
$router->get('/api/intranet/polls/list', $protected('access_intranet', function () {
    $db = Database::connect();
    $camp = $db->query("SELECT id FROM camps WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$camp) { echo json_encode([]); return; }
    $stmt = $db->prepare("SELECT p.*, GROUP_CONCAT(o.option_text ORDER BY o.id SEPARATOR '\n') as options_text FROM camp_intranet_polls p LEFT JOIN camp_intranet_poll_options o ON o.poll_id=p.id WHERE p.camp_id=? GROUP BY p.id ORDER BY p.created_at DESC");
    $stmt->execute([$camp['id']]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
}));
$router->get('/api/intranet/lost-found/list', $protected('access_intranet', function () {
    $db = Database::connect();
    $camp = $db->query("SELECT id FROM camps WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$camp) { echo json_encode([]); return; }
    $stmt = $db->prepare("SELECT * FROM camp_intranet_lost_found WHERE camp_id=? ORDER BY created_at DESC");
    $stmt->execute([$camp['id']]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
}));
$router->post('/api/intranet/lost-found/resolve', $protected('access_intranet', function () use ($requireId) {
    $id = $requireId();
    if ($id === null) return;
    Database::connect()->prepare("UPDATE camp_intranet_lost_found SET status='resolved' WHERE id=?")->execute([$id]);
    echo json_encode(['ok' => true]);
}));

// ── Members: paginated + search (overrides old no-pagination route) ───────────
$router->get('/api/members', $protected('access_operations', function () {
    $db = Database::connect();
    $search  = trim($_GET['search'] ?? '');
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(200, max(1, (int)($_GET['per_page'] ?? 25)));
    $offset  = ($page - 1) * $perPage;

    $where  = ''; $params = [];
    if ($search !== '') {
        $t = '%' . $search . '%';
        $where  = 'WHERE (m.first_name LIKE ? OR m.last_name LIKE ? OR m.fellowship LIKE ?)';
        $params = [$t, $t, $t];
    }
    $countStmt = $db->prepare("SELECT COUNT(*) FROM members m $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT m.*,
               COALESCE(ms.site_number,'') AS site_number
        FROM members m
        LEFT JOIN (
            SELECT sa.member_id, MAX(s.site_number) AS site_number
            FROM site_allocations sa
            JOIN sites s ON s.id = sa.site_id
            WHERE sa.is_current = 1
            GROUP BY sa.member_id
        ) ms ON ms.member_id = m.id
        $where
        ORDER BY m.last_name, m.first_name
        LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute($params);
    echo json_encode(['members' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total]);
}));

// Create member — only columns that exist in local schema
$router->post('/api/members', $protected('access_operations', function () {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $db   = Database::connect();
    $concession = in_array(strtolower(trim((string)($data['concession'] ?? ''))), ['yes','y','1','true'], true) ? 'Yes' : 'No';
    $stmt = $db->prepare("INSERT INTO members (first_name, last_name, fellowship, concession) VALUES (?,?,?,?)");
    $stmt->execute([trim($data['first_name'] ?? ''), trim($data['last_name'] ?? ''), trim($data['fellowship'] ?? ''), $concession]);
    echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
}));

// Update member
$router->post('/api/member/update', $protected('access_operations', function () use ($requireId) {
    $id   = $requireId(); if ($id === null) return;
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $db   = Database::connect();
    $concession = in_array(strtolower(trim((string)($data['concession'] ?? ''))), ['yes','y','1','true'], true) ? 'Yes' : 'No';
    $db->prepare("UPDATE members SET first_name=?, last_name=?, fellowship=?, concession=? WHERE id=?")
       ->execute([trim($data['first_name'] ?? ''), trim($data['last_name'] ?? ''), trim($data['fellowship'] ?? ''), $concession, $id]);
    echo json_encode(['success' => true]);
}));

// ── Camps: return is_active boolean (overrides old route) ─────────────────────
$router->get('/api/camps', $protected('access_operations', function () {
    $db   = Database::connect();
    $rows = $db->query("SELECT *, (status = 'active') AS is_active FROM camps ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['is_active'] = (bool)$r['is_active']; }
    echo json_encode($rows);
}));

$router->get('/api/camps/active', $protected('access_operations', function () {
    $db   = Database::connect();
    $camp = $db->query("SELECT *, 1 AS is_active FROM camps WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($camp) $camp['is_active'] = true;
    echo json_encode($camp ?: null);
}));

$router->post('/api/camps', $protected('access_operations', function () {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $db   = Database::connect();
    $year = $data['year'] ?? (isset($data['start_date']) ? date('Y', strtotime($data['start_date'])) : (int)date('Y'));
    $stmt = $db->prepare("INSERT INTO camps (name, year, start_date, end_date, status) VALUES (?,?,?,?,?)");
    $status = (isset($data['is_active']) && $data['is_active']) ? 'active' : 'draft';
    $stmt->execute([$data['name'] ?? 'Unnamed Camp', $year, $data['start_date'] ?? null, $data['end_date'] ?? null, $status]);
    $id = (int)$db->lastInsertId();
    if ($status === 'active') {
        $db->prepare("UPDATE camps SET status='closed' WHERE status='active' AND id != ?")->execute([$id]);
    }
    echo json_encode(['success' => true, 'id' => $id]);
}));


// ── Payments: simple list from payment_tenders (overrides old route) ──────────
$router->get('/api/payments', $protected('access_operations', function () {
    $db       = Database::connect();
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $perPage  = min(100, max(1, (int)($_GET['per_page'] ?? 30)));
    $offset   = ($page - 1) * $perPage;
    $search   = trim($_GET['search']   ?? '');
    $method   = trim($_GET['method']   ?? '');
    $category = trim($_GET['category'] ?? '');
    $memberId = isset($_GET['member_id']) ? (int)$_GET['member_id'] : 0;

    $where = []; $params = [];
    if ($search !== '') {
        $where[] = "(CONCAT(m.first_name,' ',m.last_name) LIKE ?)";
        $params[] = '%' . $search . '%';
    }
    if ($memberId > 0) { $where[] = 'p.member_id = ?'; $params[] = $memberId; }
    $methodMap = ['cash' => 'Cash', 'card' => 'EFTPOS', 'bank_transfer' => 'Other'];
    if ($method && isset($methodMap[$method])) { $where[] = 'pt.method = ?'; $params[] = $methodMap[$method]; }
    $wClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $c = $db->prepare("SELECT COUNT(*), COALESCE(SUM(pt.amount),0) FROM payment_tenders pt JOIN payments p ON p.id=pt.payment_id LEFT JOIN members m ON m.id=p.member_id $wClause");
    $c->execute($params); $cRow = $c->fetch(PDO::FETCH_NUM);
    $total = (int)($cRow[0] ?? 0); $totalAmount = (float)($cRow[1] ?? 0);

    $stmt = $db->prepare("
        SELECT pt.id, p.member_id,
               CONCAT(m.first_name,' ',m.last_name) AS member_name,
               ms.site_number, pt.amount, pt.method, pt.reference, p.notes, pt.created_at
        FROM payment_tenders pt
        JOIN payments p ON p.id=pt.payment_id
        LEFT JOIN members m ON m.id=p.member_id
        LEFT JOIN (SELECT sa.member_id, MAX(s.site_number) AS site_number FROM site_allocations sa JOIN sites s ON s.id=sa.site_id WHERE sa.is_current=1 GROUP BY sa.member_id) ms ON ms.member_id=p.member_id
        $wClause ORDER BY pt.created_at DESC LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $ref = (isset($r['reference']) && strlen($r['reference']) > 0 && $r['reference'][0] === '{')
            ? (json_decode($r['reference'], true) ?: []) : [];
        $r['category'] = $ref['category'] ?? '';
        $r['notes']    = $ref['notes'] ?? ($r['notes'] ?? '');
        unset($r['reference']);
        // Normalize method to lowercase for frontend
        $r['method'] = strtolower($r['method'] === 'EFTPOS' ? 'card' : $r['method']);
    }
    // category filter after decode
    if ($category !== '') {
        $rows = array_values(array_filter($rows, fn($r) => $r['category'] === $category));
    }
    echo json_encode(['payments' => $rows, 'total' => $total, 'total_amount' => $totalAmount]);
}));

// Simple payment create (overrides complex PaymentController.store)
$router->post('/api/payments', $protected('access_operations', function () {
    $data     = json_decode(file_get_contents('php://input'), true) ?: [];
    $memberId = (int)($data['member_id'] ?? 0);
    $amount   = (float)($data['amount'] ?? 0);
    if (!$memberId || $amount <= 0) {
        http_response_code(400); echo json_encode(['error' => 'member_id and amount required']); return;
    }
    $db     = Database::connect();
    $camp   = $db->query("SELECT id FROM camps WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $campId = $camp ? $camp['id'] : null;
    $methodMap = ['cash' => 'Cash', 'card' => 'EFTPOS', 'bank_transfer' => 'Other', 'other' => 'Other'];
    $dbMethod  = $methodMap[strtolower($data['method'] ?? '')] ?? 'Other';
    $notes     = trim($data['notes'] ?? '');
    $reference = json_encode(['category' => $data['category'] ?? '', 'notes' => $notes]);
    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO payments (member_id, camp_id, payment_date, total_amount, notes) VALUES (?,?,NOW(),?,?)")
           ->execute([$memberId, $campId, $amount, $notes]);
        $paymentId = $db->lastInsertId();
        $db->prepare("INSERT INTO payment_tenders (payment_id, method, amount, reference) VALUES (?,?,?,?)")
           ->execute([$paymentId, $dbMethod, $amount, $reference]);
        $db->commit();
        echo json_encode(['success' => true, 'id' => $paymentId]);
    } catch (Exception $e) {
        $db->rollBack(); http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
    }
}));

// Payment update (pt.id is the tender id used by the list view)
$router->post('/api/payment/update', $protected('access_operations', function () use ($requireId) {
    $id   = $requireId(); if ($id === null) return;
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $db   = Database::connect();
    $methodMap = ['cash' => 'Cash', 'card' => 'EFTPOS', 'bank_transfer' => 'Other', 'other' => 'Other'];
    $dbMethod  = $methodMap[strtolower($data['method'] ?? '')] ?? 'Other';
    $amount    = (float)($data['amount'] ?? 0);
    $notes     = trim($data['notes'] ?? '');
    $reference = json_encode(['category' => $data['category'] ?? '', 'notes' => $notes]);
    $db->prepare("UPDATE payment_tenders SET method=?, amount=?, reference=? WHERE id=?")->execute([$dbMethod, $amount, $reference, $id]);
    $tender = $db->prepare("SELECT payment_id FROM payment_tenders WHERE id=?"); $tender->execute([$id]);
    if ($pid = $tender->fetchColumn()) {
        $db->prepare("UPDATE payments SET total_amount=?, notes=? WHERE id=?")->execute([$amount, $notes, $pid]);
    }
    echo json_encode(['success' => true]);
}));

// Payment delete (deletes tender; cleans up payment row if last tender)
$router->post('/api/payment/delete', $protected('access_operations', function () use ($requireId) {
    $id  = $requireId(); if ($id === null) return;
    $db  = Database::connect();
    $t   = $db->prepare("SELECT payment_id FROM payment_tenders WHERE id=?"); $t->execute([$id]);
    $pid = $t->fetchColumn();
    $db->prepare("DELETE FROM payment_tenders WHERE id=?")->execute([$id]);
    if ($pid) {
        $remaining = $db->prepare("SELECT COUNT(*) FROM payment_tenders WHERE payment_id=?"); $remaining->execute([$pid]);
        if ((int)$remaining->fetchColumn() === 0) {
            $db->prepare("DELETE FROM payments WHERE id=?")->execute([$pid]);
        }
    }
    echo json_encode(['success' => true]);
}));

// Member balance (overrides earlier v2 version)
$router->get('/api/member/balance', $protected('access_operations', function () {
    $memberId = isset($_GET['member_id']) ? (int)$_GET['member_id'] : 0;
    if (!$memberId) { http_response_code(400); echo json_encode(['error' => 'member_id required']); return; }
    $db   = Database::connect();
    $stmt = $db->prepare("SELECT COALESCE(SUM(pt.amount),0) FROM payment_tenders pt JOIN payments p ON p.id=pt.payment_id WHERE p.member_id=?");
    $stmt->execute([$memberId]);
    $total = (float)$stmt->fetchColumn();
    $s2 = $db->prepare("SELECT pt.id, pt.amount, pt.method, pt.reference, pt.created_at FROM payment_tenders pt JOIN payments p ON p.id=pt.payment_id WHERE p.member_id=? ORDER BY pt.created_at DESC LIMIT 20");
    $s2->execute([$memberId]);
    $rows = $s2->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $ref = (isset($r['reference']) && $r['reference'][0] === '{') ? (json_decode($r['reference'], true) ?: []) : [];
        $r['category'] = $ref['category'] ?? '';
        $r['notes']    = $ref['notes'] ?? '';
        $r['method']   = strtolower($r['method'] === 'EFTPOS' ? 'card' : $r['method']);
        unset($r['reference']);
    }
    echo json_encode(['balance' => $total, 'payments' => $rows]);
}));

// ── Rates: from camp_rates for active camp ─────────────────────────────────────
$router->get('/api/rates', $protected('access_operations', function () {
    $db   = Database::connect();
    $camp = $db->query("SELECT id FROM camps WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$camp) { echo json_encode([]); return; }
    $stmt = $db->prepare("SELECT id, item AS name, amount, category, TRIM(CONCAT_WS(' ', NULLIF(user_type,''), NULLIF(accommodation_type,''), NULLIF(guest_type,''))) AS description FROM camp_rates WHERE camp_id=? ORDER BY category, item");
    $stmt->execute([$camp['id']]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
}));

$router->post('/api/rates', $protected('access_operations', function () {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $db   = Database::connect();
    $camp = $db->query("SELECT id FROM camps WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$camp) { http_response_code(400); echo json_encode(['error' => 'No active camp']); return; }
    $stmt = $db->prepare("INSERT INTO camp_rates (camp_id, item, category, amount, accommodation_type) VALUES (?,?,?,?,?)");
    $stmt->execute([$camp['id'], $data['name'] ?? '', $data['category'] ?? 'other', (float)($data['amount'] ?? 0), $data['description'] ?? '']);
    echo json_encode(['success' => true, 'id' => (int)$db->lastInsertId()]);
}));

$router->post('/api/rate/update', $protected('access_operations', function () use ($requireId) {
    $id   = $requireId(); if ($id === null) return;
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    Database::connect()->prepare("UPDATE camp_rates SET item=?, category=?, amount=?, accommodation_type=? WHERE id=?")
        ->execute([$data['name'] ?? '', $data['category'] ?? '', (float)($data['amount'] ?? 0), $data['description'] ?? '', $id]);
    echo json_encode(['success' => true]);
}));

$router->post('/api/rate/delete', $protected('access_operations', function () use ($requireId) {
    $id = $requireId(); if ($id === null) return;
    Database::connect()->prepare("DELETE FROM camp_rates WHERE id=?")->execute([$id]);
    echo json_encode(['success' => true]);
}));

// ── Activate camp ──────────────────────────────────────────────────────────────
$router->post('/api/camp/set-active', $protected('access_operations', function () use ($requireId) {
    $id = $requireId(); if ($id === null) return;
    $db = Database::connect();
    $db->prepare("UPDATE camps SET status='closed' WHERE status='active'")->execute();
    $db->prepare("UPDATE camps SET status='active' WHERE id=?")->execute([$id]);
    echo json_encode(['ok' => true]);
}));

// ── Prepayment update/delete ───────────────────────────────────────────────────
$router->post('/api/prepayment/update', $protected('access_operations', function () use ($requireId) {
    $id   = $requireId(); if ($id === null) return;
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    Database::connect()->prepare("UPDATE prepayments SET imported_name=?, amount=? WHERE id=?")
        ->execute([$data['imported_name'] ?? $data['name'] ?? '', (float)($data['amount'] ?? 0), $id]);
    echo json_encode(['ok' => true]);
}));

$router->post('/api/prepayment/delete', $protected('access_operations', function () use ($requireId) {
    $id = $requireId(); if ($id === null) return;
    Database::connect()->prepare("DELETE FROM prepayments WHERE id=?")->execute([$id]);
    echo json_encode(['ok' => true]);
}));

// ── Change password ────────────────────────────────────────────────────────────
$router->post('/api/user/change-password', $protected('access_operations', function () {
    $data    = json_decode(file_get_contents('php://input'), true) ?: [];
    $current = $data['current_password'] ?? '';
    $newPass = $data['new_password'] ?? '';
    if (!$newPass) { http_response_code(400); echo json_encode(['error' => 'new_password required']); return; }
    $user = Auth::user();
    if (!$user) { http_response_code(401); echo json_encode(['error' => 'Not authenticated']); return; }
    $db   = Database::connect();
    $row  = $db->prepare("SELECT * FROM users WHERE id=?"); $row->execute([$user['id']]);
    $u    = $row->fetch(PDO::FETCH_ASSOC);
    if (!$u || !password_verify($current, $u['password_hash'])) {
        http_response_code(403); echo json_encode(['error' => 'Current password incorrect']); return;
    }
    $db->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash($newPass, PASSWORD_DEFAULT), $u['id']]);
    echo json_encode(['ok' => true]);
}));

// ── Settings ───────────────────────────────────────────────────────────────────
$router->get('/api/settings', $protected('manage_users', function () {
    $db = Database::connect();
    try {
        $rows = $db->query("SELECT setting_key, setting_value FROM app_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        echo json_encode($rows ?: (object)[]);
    } catch (Exception $e) { echo json_encode((object)[]); }
}));

$router->post('/api/settings', $protected('manage_users', function () {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $db   = Database::connect();
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS app_settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT)");
        $stmt = $db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?");
        foreach ($data as $k => $v) { $stmt->execute([$k, $v, $v]); }
        echo json_encode(['ok' => true]);
    } catch (Exception $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
}));

// ── Intranet program sessions ──────────────────────────────────────────────────
$router->get('/api/intranet/program', $protected('access_intranet', function () {
    $db   = Database::connect();
    $camp = $db->query("SELECT id FROM camps WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$camp) { echo json_encode([]); return; }
    $stmt = $db->prepare("SELECT * FROM program_sessions WHERE camp_id=? ORDER BY date ASC, start_time ASC");
    $stmt->execute([$camp['id']]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
}));

$router->post('/api/intranet/session/create', $protected('access_intranet', function () {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $db   = Database::connect();
    $camp = $db->query("SELECT id FROM camps WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$camp) { http_response_code(400); echo json_encode(['error' => 'No active camp']); return; }
    $db->prepare("INSERT INTO program_sessions (camp_id,title,date,start_time,end_time,location,description,session_type) VALUES (?,?,?,?,?,?,?,?)")
       ->execute([$camp['id'], $data['title']??'', $data['date']??null, $data['start_time']??null, $data['end_time']??null, $data['location']??null, $data['description']??null, $data['session_type']??'general']);
    echo json_encode(['id' => (int)$db->lastInsertId()]);
}));

$router->post('/api/intranet/session/update', $protected('access_intranet', function () use ($requireId) {
    $id   = $requireId(); if ($id === null) return;
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    Database::connect()->prepare("UPDATE program_sessions SET title=?,date=?,start_time=?,end_time=?,location=?,description=?,session_type=? WHERE id=?")
        ->execute([$data['title']??'', $data['date']??null, $data['start_time']??null, $data['end_time']??null, $data['location']??null, $data['description']??null, $data['session_type']??'general', $id]);
    echo json_encode(['ok' => true]);
}));

$router->post('/api/intranet/session/delete', $protected('access_intranet', function () use ($requireId) {
    $id = $requireId(); if ($id === null) return;
    Database::connect()->prepare("DELETE FROM program_sessions WHERE id=?")->execute([$id]);
    echo json_encode(['ok' => true]);
}));

// Intranet list endpoints
$router->get('/api/intranet/noticeboard/list', $protected('access_intranet', function () {
    $db   = Database::connect();
    $camp = $db->query("SELECT id FROM camps WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$camp) { echo json_encode([]); return; }
    $stmt = $db->prepare("SELECT * FROM camp_intranet_noticeboard WHERE camp_id=? ORDER BY created_at DESC");
    $stmt->execute([$camp['id']]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
}));

$router->get('/api/intranet/polls/list', $protected('access_intranet', function () {
    $db   = Database::connect();
    $camp = $db->query("SELECT id FROM camps WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$camp) { echo json_encode([]); return; }
    $stmt = $db->prepare("SELECT p.*, GROUP_CONCAT(o.label ORDER BY o.sort_order, o.id SEPARATOR '\n') AS options_text FROM camp_intranet_polls p LEFT JOIN camp_intranet_poll_options o ON o.poll_id=p.id WHERE p.camp_id=? GROUP BY p.id ORDER BY p.created_at DESC");
    $stmt->execute([$camp['id']]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
}));

$router->get('/api/intranet/lost-found/list', $protected('access_intranet', function () {
    $db   = Database::connect();
    $camp = $db->query("SELECT id FROM camps WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$camp) { echo json_encode([]); return; }
    $stmt = $db->prepare("SELECT * FROM camp_intranet_lost_found WHERE camp_id=? ORDER BY created_at DESC");
    $stmt->execute([$camp['id']]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
}));

$router->post('/api/intranet/lost-found/resolve', $protected('access_intranet', function () use ($requireId) {
    $id = $requireId(); if ($id === null) return;
    Database::connect()->prepare("UPDATE camp_intranet_lost_found SET status='resolved' WHERE id=?")->execute([$id]);
    echo json_encode(['ok' => true]);
}));

// ── Campo sync API (API-key authenticated) ────────────────────────────────────
$router->get('/api/sync/camp', function () {
    (new SyncController())->camp();
});
$router->get('/api/sync/schedule', function () {
    (new SyncController())->schedule();
});
$router->get('/api/sync/features', function () {
    (new SyncController())->features();
});
$router->post('/api/sync/noticeboard/submit', function () {
    (new SyncController())->submitNoticeboard();
});
$router->post('/api/sync/lost-found/submit', function () {
    (new SyncController())->submitLostFound();
});
$router->post('/api/sync/messages/submit', function () {
    (new SyncController())->submitMessage();
});
$router->post('/api/sync/polls/vote', function () {
    (new SyncController())->pollVote();
});
$router->post('/api/sync/push/subscribe', function () {
    (new SyncController())->pushSubscribe();
});

// ── Site Allocations ──────────────────────────────────────────────────────────
$router->get('/api/site-allocations', $protected('access_operations', function () {
    $db     = Database::connect();
    $campId = isset($_GET['camp_id']) ? (int)$_GET['camp_id'] : 0;
    if ($campId <= 0) {
        $active = $db->query("SELECT id FROM camps WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $campId = $active ? (int)$active['id'] : 0;
    }
    if ($campId <= 0) { echo json_encode(['allocations' => [], 'unassigned_households' => [], 'camp_id' => null]); return; }

    // All sites with their allocation for this camp
    $stmt = $db->prepare("
        SELECT s.id AS site_id, s.site_number, s.site_type, s.power, s.capacity,
               s.map_lat, s.map_lng,
               sa.id AS allocation_id, sa.household_id, sa.notes AS allocation_notes,
               h.name AS household_name,
               COUNT(m.id) AS member_count
        FROM sites s
        LEFT JOIN site_allocations sa ON sa.site_id = s.id AND sa.camp_id = ?
        LEFT JOIN households h ON h.id = sa.household_id
        LEFT JOIN members m ON m.household_id = h.id
        GROUP BY s.id, sa.id
        ORDER BY CAST(s.site_number AS UNSIGNED), s.site_number
    ");
    $stmt->execute([$campId]);
    $allocations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allocations as &$a) {
        $a['power']        = (bool)$a['power'];
        $a['member_count'] = (int)$a['member_count'];
        $a['allocation_id']= $a['allocation_id'] ? (int)$a['allocation_id'] : null;
        $a['household_id'] = $a['household_id']  ? (int)$a['household_id']  : null;
    }

    // Households not yet assigned to any site for this camp
    $assigned = $db->prepare("SELECT household_id FROM site_allocations WHERE camp_id=?");
    $assigned->execute([$campId]);
    $assignedIds = array_column($assigned->fetchAll(PDO::FETCH_ASSOC), 'household_id');

    $unassigned = $db->query("
        SELECT h.id, h.name, COUNT(m.id) AS member_count
        FROM households h
        LEFT JOIN members m ON m.household_id = h.id
        GROUP BY h.id
        ORDER BY h.name
    ")->fetchAll(PDO::FETCH_ASSOC);
    $unassigned = array_values(array_filter($unassigned, fn($h) => !in_array((int)$h['id'], array_map('intval', $assignedIds))));
    foreach ($unassigned as &$u) { $u['member_count'] = (int)$u['member_count']; }

    echo json_encode(['allocations' => $allocations, 'unassigned_households' => $unassigned, 'camp_id' => $campId]);
}));

$router->post('/api/site-allocations', $protected('access_operations', function () {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $db   = Database::connect();
    try {
        $stmt = $db->prepare("INSERT INTO site_allocations (camp_id, site_id, household_id, notes) VALUES (?,?,?,?)");
        $stmt->execute([(int)$data['camp_id'], (int)$data['site_id'], (int)$data['household_id'], trim($data['notes'] ?? '')]);
        echo json_encode(['success' => true, 'id' => (int)$db->lastInsertId()]);
    } catch (PDOException $e) {
        http_response_code(409);
        echo json_encode(['message' => 'Site or household already allocated for this camp']);
    }
}));

$router->post('/api/site-allocation/update', $protected('access_operations', function () use ($requireId) {
    $id   = $requireId(); if ($id === null) return;
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $db   = Database::connect();
    try {
        $db->prepare("UPDATE site_allocations SET household_id=?, notes=? WHERE id=?")
           ->execute([(int)$data['household_id'], trim($data['notes'] ?? ''), $id]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        http_response_code(409);
        echo json_encode(['message' => 'Household already allocated to another site for this camp']);
    }
}));

$router->post('/api/site-allocation/delete', $protected('access_operations', function () use ($requireId) {
    $id = $requireId(); if ($id === null) return;
    Database::connect()->prepare("DELETE FROM site_allocations WHERE id=?")->execute([$id]);
    echo json_encode(['success' => true]);
}));

// ── Prepayments ───────────────────────────────────────────────────────────────
$router->get('/api/prepayments', $protected('access_operations', function () {
    $db     = Database::connect();
    $campId = isset($_GET['camp_id']) ? (int)$_GET['camp_id'] : 0;
    if ($campId <= 0) {
        $active = $db->query("SELECT id FROM camps WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $campId = $active ? (int)$active['id'] : 0;
    }
    if ($campId <= 0) { echo json_encode(['prepayments' => [], 'summary' => [], 'camp_id' => null]); return; }

    $filter = trim($_GET['filter'] ?? '');
    $where  = ['p.camp_id = ?']; $params = [$campId];
    if ($filter === 'matched')   { $where[] = 'p.household_id IS NOT NULL'; }
    if ($filter === 'unmatched') { $where[] = 'p.household_id IS NULL'; }
    $wClause = 'WHERE ' . implode(' AND ', $where);

    $stmt = $db->prepare("
        SELECT p.*, h.name AS household_name
        FROM prepayments p
        LEFT JOIN households h ON h.id = p.household_id
        $wClause
        ORDER BY p.paid_at DESC, p.created_at DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sum = $db->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(amount),0) AS total_amount,
        SUM(household_id IS NOT NULL) AS matched, SUM(household_id IS NULL) AS unmatched
        FROM prepayments WHERE camp_id=?");
    $sum->execute([$campId]);
    $summary = $sum->fetch(PDO::FETCH_ASSOC);
    $summary['total']        = (int)$summary['total'];
    $summary['matched']      = (int)$summary['matched'];
    $summary['unmatched']    = (int)$summary['unmatched'];
    $summary['total_amount'] = (float)$summary['total_amount'];

    echo json_encode(['prepayments' => $rows, 'summary' => $summary, 'camp_id' => $campId]);
}));

$router->post('/api/prepayments', $protected('access_operations', function () {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $db   = Database::connect();
    $hid  = !empty($data['household_id']) ? (int)$data['household_id'] : null;
    $db->prepare("INSERT INTO prepayments (camp_id, household_id, name, amount, method, reference, paid_at, notes, source) VALUES (?,?,?,?,?,?,?,?,?)")
       ->execute([
           (int)($data['camp_id']   ?? 0),
           $hid,
           trim($data['name']       ?? ''),
           (float)($data['amount']  ?? 0),
           trim($data['method']     ?? 'bank_transfer'),
           trim($data['reference']  ?? ''),
           $data['paid_at'] ?: null,
           trim($data['notes']      ?? ''),
           trim($data['source']     ?? 'manual'),
       ]);
    echo json_encode(['success' => true, 'id' => (int)$db->lastInsertId()]);
}));

$router->post('/api/prepayment/update', $protected('access_operations', function () use ($requireId) {
    $id   = $requireId(); if ($id === null) return;
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $db   = Database::connect();
    $hid  = !empty($data['household_id']) ? (int)$data['household_id'] : null;
    $db->prepare("UPDATE prepayments SET household_id=?, name=?, amount=?, method=?, reference=?, paid_at=?, notes=? WHERE id=?")
       ->execute([
           $hid,
           trim($data['name']       ?? ''),
           (float)($data['amount']  ?? 0),
           trim($data['method']     ?? 'bank_transfer'),
           trim($data['reference']  ?? ''),
           $data['paid_at'] ?: null,
           trim($data['notes']      ?? ''),
           $id,
       ]);
    echo json_encode(['success' => true]);
}));

$router->post('/api/prepayment/delete', $protected('access_operations', function () use ($requireId) {
    $id = $requireId(); if ($id === null) return;
    Database::connect()->prepare("DELETE FROM prepayments WHERE id=?")->execute([$id]);
    echo json_encode(['success' => true]);
}));

// ── Import: members CSV ───────────────────────────────────────────────────────
$router->post('/api/import/members', $protected('access_operations', function () {
    if (empty($_FILES['file'])) { http_response_code(400); echo json_encode(['message' => 'No file uploaded']); return; }
    $handle = fopen($_FILES['file']['tmp_name'], 'r');
    $headers = array_map('strtolower', array_map('trim', fgetcsv($handle) ?: []));
    $db = Database::connect();
    $imported = 0; $skipped = 0; $errors = [];
    $householdCache = []; // lowercase name → id

    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) < 2) { $skipped++; continue; }
        $data      = array_combine(array_slice($headers, 0, count($row)), array_slice($row, 0, count($headers)));
        $firstName = trim($data['first_name'] ?? '');
        if (!$firstName) { $skipped++; continue; }

        // Resolve household
        $householdId   = null;
        $householdName = trim($data['household_name'] ?? '');
        if ($householdName !== '') {
            $key = strtolower($householdName);
            if (!isset($householdCache[$key])) {
                $h = $db->prepare("SELECT id FROM households WHERE LOWER(name)=? LIMIT 1");
                $h->execute([$key]);
                $found = $h->fetch(PDO::FETCH_ASSOC);
                if ($found) {
                    $householdCache[$key] = (int)$found['id'];
                } else {
                    $ins = $db->prepare("INSERT INTO households (name) VALUES (?)");
                    $ins->execute([$householdName]);
                    $householdCache[$key] = (int)$db->lastInsertId();
                }
            }
            $householdId = $householdCache[$key];
        }

        try {
            $db->prepare("INSERT INTO members (first_name, last_name, household_id, member_type, gender, mobile, email) VALUES (?,?,?,?,?,?,?)")
               ->execute([
                   $firstName,
                   trim($data['last_name']   ?? ''),
                   $householdId,
                   trim($data['member_type'] ?? 'adult'),
                   trim($data['gender']      ?? ''),
                   trim($data['mobile']      ?? ''),
                   trim($data['email']       ?? ''),
               ]);
            $imported++;
        } catch (PDOException $e) {
            $errors[] = "Row: $firstName " . trim($data['last_name'] ?? '') . " — " . $e->getMessage();
            $skipped++;
        }
    }
    fclose($handle);
    echo json_encode(['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors]);
}));

// ── Import: sites CSV ─────────────────────────────────────────────────────────
$router->post('/api/import/sites', $protected('access_operations', function () {
    if (empty($_FILES['file'])) { http_response_code(400); echo json_encode(['message' => 'No file uploaded']); return; }
    $handle  = fopen($_FILES['file']['tmp_name'], 'r');
    $headers = array_map('strtolower', array_map('trim', fgetcsv($handle) ?: []));
    $db = Database::connect();
    $imported = 0; $skipped = 0; $errors = [];

    while (($row = fgetcsv($handle)) !== false) {
        $data    = array_combine(array_slice($headers, 0, count($row)), array_slice($row, 0, count($headers)));
        $siteNum = trim($data['site_number'] ?? '');
        if (!$siteNum) { $skipped++; continue; }

        $exists = $db->prepare("SELECT id FROM sites WHERE site_number=? LIMIT 1");
        $exists->execute([$siteNum]);
        if ($exists->fetch()) { $skipped++; continue; }

        try {
            $db->prepare("INSERT INTO sites (site_number, site_type, power, capacity, notes) VALUES (?,?,?,?,?)")
               ->execute([
                   $siteNum,
                   trim($data['site_type'] ?? ''),
                   (!empty($data['power']) && $data['power'] !== '0') ? 1 : 0,
                   max(1, (int)($data['capacity'] ?? 6)),
                   trim($data['notes'] ?? ''),
               ]);
            $imported++;
        } catch (PDOException $e) {
            $errors[] = "Site $siteNum: " . $e->getMessage();
            $skipped++;
        }
    }
    fclose($handle);
    echo json_encode(['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors]);
}));

// ── Camp map center ────────────────────────────────────────────────────────────
$router->post('/api/camp/map-center', $protected('manage_system', function () use ($requireId) {
    $id   = $requireId(); if ($id === null) return;
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $lat  = isset($data['map_center_lat']) && $data['map_center_lat'] !== '' ? (float)$data['map_center_lat'] : null;
    $lng  = isset($data['map_center_lng']) && $data['map_center_lng'] !== '' ? (float)$data['map_center_lng'] : null;
    Database::connect()->prepare("UPDATE camps SET map_center_lat=?, map_center_lng=? WHERE id=?")
        ->execute([$lat, $lng, $id]);
    echo json_encode(['success' => true]);
}));

// ── Site pin (map coordinate update only) ─────────────────────────────────────
$router->post('/api/site/pin', $protected('access_operations', function () use ($requireId) {
    $id   = $requireId(); if ($id === null) return;
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $lat  = isset($data['map_lat']) && $data['map_lat'] !== null ? (float)$data['map_lat'] : null;
    $lng  = isset($data['map_lng']) && $data['map_lng'] !== null ? (float)$data['map_lng'] : null;
    Database::connect()->prepare("UPDATE sites SET map_lat=?, map_lng=? WHERE id=?")
        ->execute([$lat, $lng, $id]);
    echo json_encode(['success' => true]);
}));

// ── Sites: permanent site registry (overrides legacy routes) ──────────────────
$router->get('/api/sites', $protected('access_operations', function () {
    $db     = Database::connect();
    $search = trim($_GET['search'] ?? '');
    $type   = trim($_GET['type']   ?? '');

    $where = []; $params = [];
    if ($search !== '') {
        $where[]  = '(site_number LIKE ? OR notes LIKE ?)';
        $params   = array_merge($params, ['%'.$search.'%', '%'.$search.'%']);
    }
    if ($type !== '') {
        $where[]  = 'site_type = ?';
        $params[] = $type;
    }
    $wClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $db->prepare("SELECT * FROM sites $wClause ORDER BY CAST(site_number AS UNSIGNED), site_number");
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
}));

$router->post('/api/sites', $protected('access_operations', function () {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $db   = Database::connect();
    $stmt = $db->prepare("INSERT INTO sites (site_number, site_type, power, capacity, notes) VALUES (?,?,?,?,?)");
    $stmt->execute([
        trim($data['site_number'] ?? ''),
        trim($data['site_type']   ?? ''),
        !empty($data['power']) ? 1 : 0,
        max(1, (int)($data['capacity'] ?? 6)),
        trim($data['notes']       ?? ''),
    ]);
    echo json_encode(['success' => true, 'id' => (int)$db->lastInsertId()]);
}));

$router->post('/api/site/update', $protected('access_operations', function () use ($requireId) {
    $id   = $requireId(); if ($id === null) return;
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $db   = Database::connect();
    $lat  = isset($data['map_lat']) && $data['map_lat'] !== '' ? (float)$data['map_lat'] : null;
    $lng  = isset($data['map_lng']) && $data['map_lng'] !== '' ? (float)$data['map_lng'] : null;
    $db->prepare("UPDATE sites SET site_number=?, site_type=?, power=?, capacity=?, notes=?, map_lat=?, map_lng=? WHERE id=?")
       ->execute([
           trim($data['site_number'] ?? ''),
           trim($data['site_type']   ?? ''),
           !empty($data['power']) ? 1 : 0,
           max(1, (int)($data['capacity'] ?? 6)),
           trim($data['notes']       ?? ''),
           $lat, $lng,
           $id,
       ]);
    echo json_encode(['success' => true]);
}));

$router->post('/api/site/delete', $protected('access_operations', function () use ($requireId) {
    $id = $requireId(); if ($id === null) return;
    Database::connect()->prepare("DELETE FROM sites WHERE id=?")->execute([$id]);
    echo json_encode(['success' => true]);
}));

// ── Households ────────────────────────────────────────────────────────────────
$router->get('/api/households', $protected('access_operations', function () {
    $db   = Database::connect();
    $rows = $db->query("
        SELECT h.*, COUNT(m.id) AS member_count
        FROM households h
        LEFT JOIN members m ON m.household_id = h.id
        GROUP BY h.id
        ORDER BY h.name
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['member_count'] = (int)$r['member_count']; }
    echo json_encode($rows);
}));

$router->post('/api/households', $protected('access_operations', function () {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $name = trim($data['name'] ?? '');
    if ($name === '') { http_response_code(400); echo json_encode(['message' => 'Name required']); return; }
    $db   = Database::connect();
    $stmt = $db->prepare("INSERT INTO households (name, notes) VALUES (?,?)");
    $stmt->execute([$name, trim($data['notes'] ?? '')]);
    $id   = (int)$db->lastInsertId();
    echo json_encode(['success' => true, 'id' => $id, 'name' => $name]);
}));

$router->post('/api/household/update', $protected('access_operations', function () use ($requireId) {
    $id   = $requireId(); if ($id === null) return;
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $db   = Database::connect();
    $db->prepare("UPDATE households SET name=?, notes=? WHERE id=?")
       ->execute([trim($data['name'] ?? ''), trim($data['notes'] ?? ''), $id]);
    echo json_encode(['success' => true]);
}));

$router->post('/api/household/delete', $protected('access_operations', function () use ($requireId) {
    $id = $requireId(); if ($id === null) return;
    Database::connect()->prepare("DELETE FROM households WHERE id=?")->execute([$id]);
    echo json_encode(['success' => true]);
}));

// ── Rates: per-camp CRUD with sheet support (overrides legacy routes) ─────────
$router->get('/api/rates', $protected('access_operations', function () {
    $db     = Database::connect();
    $campId = isset($_GET['camp_id']) ? (int)$_GET['camp_id'] : 0;
    if ($campId <= 0) {
        $active = $db->query("SELECT id FROM camps WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $campId = $active ? (int)$active['id'] : 0;
    }
    if ($campId <= 0) { echo json_encode(['rates' => [], 'sheets' => [], 'camp_id' => null]); return; }

    // Available sheets for this camp
    $shStmt = $db->prepare("SELECT DISTINCT sheet FROM rates WHERE camp_id=? ORDER BY sheet");
    $shStmt->execute([$campId]);
    $sheets = array_column($shStmt->fetchAll(PDO::FETCH_ASSOC), 'sheet');

    // Filter by sheet if provided
    $sheet = trim($_GET['sheet'] ?? '');
    if ($sheet !== '') {
        $stmt = $db->prepare("SELECT * FROM rates WHERE camp_id=? AND sheet=? ORDER BY member_type, period, label");
        $stmt->execute([$campId, $sheet]);
    } else {
        $stmt = $db->prepare("SELECT * FROM rates WHERE camp_id=? ORDER BY sheet, member_type, period, label");
        $stmt->execute([$campId]);
    }
    echo json_encode(['rates' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'sheets' => $sheets, 'camp_id' => $campId]);
}));

$router->post('/api/rates', $protected('access_operations', function () {
    $data  = json_decode(file_get_contents('php://input'), true) ?: [];
    $db    = Database::connect();
    $sheet = trim($data['sheet'] ?? 'Standard') ?: 'Standard';
    $stmt  = $db->prepare("INSERT INTO rates (camp_id, sheet, label, member_type, period, amount) VALUES (?,?,?,?,?,?)");
    $stmt->execute([
        (int)($data['camp_id']    ?? 0),
        $sheet,
        trim($data['label']       ?? ''),
        trim($data['member_type'] ?? 'adult'),
        trim($data['period']      ?? 'full'),
        (float)($data['amount']   ?? 0),
    ]);
    echo json_encode(['success' => true, 'id' => (int)$db->lastInsertId()]);
}));

$router->post('/api/rate/update', $protected('access_operations', function () use ($requireId) {
    $id    = $requireId(); if ($id === null) return;
    $data  = json_decode(file_get_contents('php://input'), true) ?: [];
    $db    = Database::connect();
    $sheet = trim($data['sheet'] ?? 'Standard') ?: 'Standard';
    $db->prepare("UPDATE rates SET sheet=?, label=?, member_type=?, period=?, amount=? WHERE id=?")
       ->execute([
           $sheet,
           trim($data['label']       ?? ''),
           trim($data['member_type'] ?? 'adult'),
           trim($data['period']      ?? 'full'),
           (float)($data['amount']   ?? 0),
           $id,
       ]);
    echo json_encode(['success' => true]);
}));

$router->post('/api/rate/delete', $protected('access_operations', function () use ($requireId) {
    $id = $requireId(); if ($id === null) return;
    Database::connect()->prepare("DELETE FROM rates WHERE id=?")->execute([$id]);
    echo json_encode(['success' => true]);
}));

// ── Members: household-aware CRUD (overrides legacy routes) ───────────────────
$router->get('/api/members', $protected('access_operations', function () {
    $db      = Database::connect();
    $search  = trim($_GET['search'] ?? '');
    $type    = trim($_GET['type'] ?? '');
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(5000, max(1, (int)($_GET['per_page'] ?? 25)));
    $offset  = ($page - 1) * $perPage;

    $where = []; $params = [];
    if ($search !== '') {
        $t = '%' . $search . '%';
        $where[]  = '(m.first_name LIKE ? OR m.last_name LIKE ? OR m.mobile LIKE ? OR m.email LIKE ? OR h.name LIKE ?)';
        $params = array_merge($params, [$t, $t, $t, $t, $t]);
    }
    if ($type !== '') {
        $where[]  = 'm.member_type = ?';
        $params[] = $type;
    }
    $wClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $db->prepare("SELECT COUNT(*) FROM members m LEFT JOIN households h ON h.id = m.household_id $wClause");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT m.*, h.name AS household_name,
            ms.site_numbers
        FROM members m
        LEFT JOIN households h ON h.id = m.household_id
        LEFT JOIN (
            SELECT mhm.member_id,
                GROUP_CONCAT(DISTINCT s.site_number ORDER BY s.site_number SEPARATOR ', ') AS site_numbers
            FROM member_household_members mhm
            JOIN site_allocations sa ON sa.household_id = mhm.household_id
            LEFT JOIN sites s ON s.id = sa.site_id
            WHERE s.site_number IS NOT NULL
            GROUP BY mhm.member_id
        ) ms ON ms.member_id = m.id
        $wClause
        ORDER BY m.last_name, m.first_name
        LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute($params);
    echo json_encode(['members' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total]);
}));

$router->post('/api/members', $protected('access_operations', function () {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $db   = Database::connect();
    $householdId = isset($data['household_id']) && $data['household_id'] ? (int)$data['household_id'] : null;
    $stmt = $db->prepare("INSERT INTO members (first_name, last_name, household_id, member_type, gender, mobile, email, medical_notes, notes) VALUES (?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        trim($data['first_name']  ?? ''),
        trim($data['last_name']   ?? ''),
        $householdId,
        trim($data['member_type'] ?? 'adult'),
        trim($data['gender']      ?? ''),
        trim($data['mobile']      ?? ''),
        trim($data['email']       ?? ''),
        trim($data['medical_notes'] ?? ''),
        trim($data['notes']       ?? ''),
    ]);
    echo json_encode(['success' => true, 'id' => (int)$db->lastInsertId()]);
}));

$router->post('/api/member/update', $protected('access_operations', function () use ($requireId) {
    $id   = $requireId(); if ($id === null) return;
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $db   = Database::connect();
    $householdId = isset($data['household_id']) && $data['household_id'] ? (int)$data['household_id'] : null;
    $db->prepare("UPDATE members SET first_name=?, last_name=?, household_id=?, member_type=?, gender=?, mobile=?, email=?, medical_notes=?, notes=? WHERE id=?")
       ->execute([
           trim($data['first_name']  ?? ''),
           trim($data['last_name']   ?? ''),
           $householdId,
           trim($data['member_type'] ?? 'adult'),
           trim($data['gender']      ?? ''),
           trim($data['mobile']      ?? ''),
           trim($data['email']       ?? ''),
           trim($data['medical_notes'] ?? ''),
           trim($data['notes']       ?? ''),
           $id,
       ]);
    echo json_encode(['success' => true]);
}));

$router->post('/api/member/delete', $protected('access_operations', function () use ($requireId) {
    $id = $requireId(); if ($id === null) return;
    Database::connect()->prepare("DELETE FROM members WHERE id=?")->execute([$id]);
    echo json_encode(['success' => true]);
}));

// ── Payments ──────────────────────────────────────────────────────────────────

// Search households with site allocation for payment form dropdown
$router->get('/api/households/search', $protected('access_operations', function () {
    $campId = isset($_GET['camp_id']) ? (int)$_GET['camp_id'] : 0;
    $q      = trim($_GET['q'] ?? '');
    $db     = Database::connect();
    $params = [];
    $where  = '';
    if ($q !== '') {
        $like   = '%' . $q . '%';
        $where  = 'WHERE (h.name LIKE ? OR s.site_number LIKE ?)';
        $params = [$like, $like];
    }
    if ($campId) {
        array_unshift($params, $campId);
        $joinCond = 'sa.household_id = h.id AND sa.camp_id = ?';
    } else {
        $joinCond = 'sa.household_id = h.id';
    }
    $sql = "
        SELECT h.id, h.name,
               s.site_number,
               (SELECT COUNT(*) FROM members WHERE household_id = h.id) AS member_count
        FROM households h
        LEFT JOIN site_allocations sa ON {$joinCond}
        LEFT JOIN sites s ON sa.site_id = s.id
        {$where}
        ORDER BY h.name ASC
        LIMIT 12
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll());
}));

// Full context for selected household + camp (for payment form)
$router->get('/api/household/payment-context', $protected('access_operations', function () {
    $hid    = isset($_GET['household_id']) ? (int)$_GET['household_id'] : 0;
    $campId = isset($_GET['camp_id'])      ? (int)$_GET['camp_id']      : 0;
    if (!$hid || !$campId) { http_response_code(400); echo json_encode(['error' => 'household_id and camp_id required']); return; }
    $db = Database::connect();

    // Household
    $h = $db->prepare("SELECT id, name, notes FROM households WHERE id=? LIMIT 1");
    $h->execute([$hid]);
    $household = $h->fetch();
    if (!$household) { http_response_code(404); echo json_encode(['error' => 'Household not found']); return; }

    // Site allocation
    $sa = $db->prepare("
        SELECT s.id AS site_id, s.site_number
        FROM site_allocations sa
        JOIN sites s ON sa.site_id = s.id
        WHERE sa.household_id = ? AND sa.camp_id = ?
        LIMIT 1
    ");
    $sa->execute([$hid, $campId]);
    $alloc = $sa->fetch();

    // Members
    $mem = $db->prepare("
        SELECT id, CONCAT(first_name,' ',last_name) AS name, member_type, gender
        FROM members WHERE household_id = ? ORDER BY first_name, last_name
    ");
    $mem->execute([$hid]);
    $members = $mem->fetchAll();

    // Available prepayments (amount > 0, matched to this household, for this camp)
    $pre = $db->prepare("
        SELECT id, name, amount, method, reference
        FROM prepayments
        WHERE household_id = ? AND camp_id = ? AND amount > 0
        ORDER BY id ASC
    ");
    $pre->execute([$hid, $campId]);
    $prepayments = $pre->fetchAll();
    $prepaymentBalance = array_sum(array_column($prepayments, 'amount'));

    // Recent payments for this household + camp
    $pays = $db->prepare("
        SELECT id, payment_date, camp_fee, site_fee, prepaid_applied, other_amount, total,
               tender_eftpos, tender_cash, tender_bank, notes, headcount, arrival_date, departure_date
        FROM payments
        WHERE household_id = ? AND camp_id = ?
        ORDER BY payment_date DESC, id DESC
        LIMIT 10
    ");
    $pays->execute([$hid, $campId]);
    $payments = $pays->fetchAll();
    $totalPaid = array_sum(array_column($payments, 'total'));

    echo json_encode([
        'household'          => $household,
        'site_id'            => $alloc ? (int)$alloc['site_id'] : null,
        'site_number'        => $alloc ? $alloc['site_number'] : null,
        'members'            => $members,
        'prepayments'        => $prepayments,
        'prepayment_balance' => (float)$prepaymentBalance,
        'payments'           => $payments,
        'total_paid'         => (float)$totalPaid,
    ]);
}));

// List payments (filterable by camp, household, search)
$router->get('/api/payments', $protected('access_operations', function () {
    $db         = Database::connect();
    $campId     = isset($_GET['camp_id'])     && $_GET['camp_id']     !== '' ? (int)$_GET['camp_id']     : null;
    $hid        = isset($_GET['household_id'])&& $_GET['household_id']!== '' ? (int)$_GET['household_id']: null;
    $search     = trim($_GET['search'] ?? '');
    $conditions = [];
    $params     = [];
    if ($campId) { $conditions[] = 'p.camp_id = ?';     $params[] = $campId; }
    if ($hid)    { $conditions[] = 'p.household_id = ?'; $params[] = $hid; }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $conditions[] = '(h.name LIKE ? OR p.notes LIKE ?)';
        $params[] = $like; $params[] = $like;
    }
    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
    $stmt = $db->prepare("
        SELECT p.*, h.name AS household_name, s.site_number
        FROM payments p
        JOIN households h ON p.household_id = h.id
        LEFT JOIN site_allocations sa ON sa.household_id = p.household_id AND sa.camp_id = p.camp_id
        LEFT JOIN sites s ON sa.site_id = s.id
        {$where}
        ORDER BY p.payment_date DESC, p.id DESC
    ");
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll());
}));

// Create payment
$router->post('/api/payments', $protected('access_operations', function () {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $db   = Database::connect();
    $db->beginTransaction();
    try {
        $hid            = (int)($data['household_id'] ?? 0);
        $campId         = (int)($data['camp_id']      ?? 0);
        $campFee        = (float)($data['camp_fee']       ?? 0);
        $siteFee        = (float)($data['site_fee']       ?? 0);
        $otherAmount    = (float)($data['other_amount']   ?? 0);
        $prepaidApplied = (float)($data['prepaid_applied']?? 0);
        $total          = round($campFee + $siteFee + $otherAmount - $prepaidApplied, 2);
        $tenders        = is_array($data['tenders'] ?? null) ? $data['tenders'] : [];
        $tEft  = 0.0; $tCash = 0.0; $tBank = 0.0;
        foreach ($tenders as $t) {
            $amt = (float)($t['amount'] ?? 0);
            switch (strtolower($t['method'] ?? '')) {
                case 'eftpos': $tEft  += $amt; break;
                case 'cash':   $tCash += $amt; break;
                case 'bank':   $tBank += $amt; break;
            }
        }
        $payDate  = !empty($data['payment_date']) ? $data['payment_date'] : date('Y-m-d H:i:s');
        $arrDate  = !empty($data['arrival_date'])   ? $data['arrival_date']   : null;
        $depDate  = !empty($data['departure_date']) ? $data['departure_date'] : null;
        $headcount = isset($data['headcount']) && $data['headcount'] !== '' ? (int)$data['headcount'] : null;

        $stmt = $db->prepare("
            INSERT INTO payments
                (household_id, camp_id, payment_date, camp_fee, site_fee, prepaid_applied,
                 other_amount, total, headcount, notes, arrival_date, departure_date,
                 tender_eftpos, tender_cash, tender_bank)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $hid, $campId, $payDate, $campFee, $siteFee, $prepaidApplied,
            $otherAmount, $total, $headcount, trim($data['notes'] ?? ''),
            $arrDate, $depDate, $tEft, $tCash, $tBank
        ]);
        $paymentId = (int)$db->lastInsertId();

        // Record tender lines
        if ($tenders) {
            $ts = $db->prepare("INSERT INTO payment_tenders (payment_id, method, amount, reference) VALUES (?,?,?,?)");
            foreach ($tenders as $t) {
                $amt = (float)($t['amount'] ?? 0);
                if ($amt != 0) {
                    $ts->execute([$paymentId, strtolower($t['method'] ?? 'other'), $amt, trim($t['reference'] ?? '')]);
                }
            }
        }

        // Apply prepayments
        if ($prepaidApplied > 0 && !empty($data['prepayment_ids']) && is_array($data['prepayment_ids'])) {
            $remaining = $prepaidApplied;
            $preStmt   = $db->prepare("SELECT id, amount FROM prepayments WHERE id=? AND household_id=? AND amount>0 LIMIT 1");
            $upStmt    = $db->prepare("UPDATE prepayments SET amount=? WHERE id=?");
            $allocStmt = $db->prepare("INSERT INTO payment_prepayment_allocations (payment_id, prepayment_id, amount_applied) VALUES (?,?,?)");
            foreach ($data['prepayment_ids'] as $pid) {
                if ($remaining <= 0) break;
                $preStmt->execute([(int)$pid, $hid]);
                $pre = $preStmt->fetch();
                if (!$pre) continue;
                $apply   = min((float)$pre['amount'], $remaining);
                $newAmt  = round((float)$pre['amount'] - $apply, 2);
                $upStmt->execute([$newAmt, (int)$pre['id']]);
                $allocStmt->execute([$paymentId, (int)$pre['id'], $apply]);
                $remaining = round($remaining - $apply, 2);
            }
        }

        $db->commit();
        echo json_encode(['success' => true, 'id' => $paymentId]);
    } catch (Exception $e) {
        $db->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}));

// Update payment (notes, amounts, dates)
$router->post('/api/payment/update', $protected('access_operations', function () use ($requireId) {
    $id   = $requireId(); if ($id === null) return;
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $db   = Database::connect();
    $campFee     = (float)($data['camp_fee']     ?? 0);
    $siteFee     = (float)($data['site_fee']     ?? 0);
    $otherAmount = (float)($data['other_amount'] ?? 0);
    $prepaid     = (float)($data['prepaid_applied'] ?? 0);
    $total       = round($campFee + $siteFee + $otherAmount - $prepaid, 2);
    $payDate     = !empty($data['payment_date']) ? $data['payment_date'] : null;
    $arrDate     = !empty($data['arrival_date'])   ? $data['arrival_date']   : null;
    $depDate     = !empty($data['departure_date']) ? $data['departure_date'] : null;
    $db->prepare("
        UPDATE payments SET camp_fee=?, site_fee=?, prepaid_applied=?, other_amount=?, total=?,
               payment_date=COALESCE(?,payment_date), arrival_date=?, departure_date=?, notes=?
        WHERE id=?
    ")->execute([$campFee, $siteFee, $prepaid, $otherAmount, $total,
                 $payDate, $arrDate, $depDate, trim($data['notes'] ?? ''), $id]);
    echo json_encode(['success' => true]);
}));

// Delete payment — restores any consumed prepayments
$router->post('/api/payment/delete', $protected('access_operations', function () use ($requireId) {
    $id = $requireId(); if ($id === null) return;
    $db = Database::connect();
    $db->beginTransaction();
    try {
        // Restore prepayments
        $allocs = $db->prepare("SELECT prepayment_id, amount_applied FROM payment_prepayment_allocations WHERE payment_id=?");
        $allocs->execute([$id]);
        $upPre  = $db->prepare("UPDATE prepayments SET amount = amount + ? WHERE id=?");
        foreach ($allocs->fetchAll() as $a) {
            $upPre->execute([(float)$a['amount_applied'], (int)$a['prepayment_id']]);
        }
        $db->prepare("DELETE FROM payments WHERE id=?")->execute([$id]);
        $db->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $db->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}));

// Financial summary for a camp
$router->get('/api/payments/summary', $protected('access_operations', function () {
    $campId = isset($_GET['camp_id']) && $_GET['camp_id'] !== '' ? (int)$_GET['camp_id'] : null;
    $db     = Database::connect();
    if (!$campId) {
        $row = $db->query("SELECT id FROM camps WHERE status='active' ORDER BY id DESC LIMIT 1")->fetch();
        $campId = $row ? (int)$row['id'] : 0;
    }
    if (!$campId) { echo json_encode(['error' => 'No camp']); return; }
    $stmt = $db->prepare("
        SELECT
            COUNT(*)                      AS payment_count,
            COALESCE(SUM(camp_fee),0)     AS total_camp_fees,
            COALESCE(SUM(site_fee),0)     AS total_site_fees,
            COALESCE(SUM(other_amount),0) AS total_other,
            COALESCE(SUM(prepaid_applied),0) AS total_prepaid_applied,
            COALESCE(SUM(total),0)        AS total_tendered,
            COALESCE(SUM(tender_eftpos),0)AS total_eftpos,
            COALESCE(SUM(tender_cash),0)  AS total_cash,
            COALESCE(SUM(tender_bank),0)  AS total_bank
        FROM payments WHERE camp_id=?
    ");
    $stmt->execute([$campId]);
    echo json_encode($stmt->fetch());
}));

// ── Dashboard (overrides earlier stub) ───────────────────────────────────────
$router->get('/api/dashboard', $protected('access_operations', function () {
    $db = Database::connect();

    // Active camp — fall back to most recent
    $camp = $db->query("SELECT * FROM camps WHERE status='active' ORDER BY id DESC LIMIT 1")->fetch();
    if (!$camp) {
        $camp = $db->query("SELECT * FROM camps ORDER BY id DESC LIMIT 1")->fetch();
    }
    $campId = $camp ? (int)$camp['id'] : 0;

    // Global counts
    $memberCount    = (int)$db->query("SELECT COUNT(*) FROM members")->fetchColumn();
    $householdCount = (int)$db->query("SELECT COUNT(*) FROM households")->fetchColumn();
    $siteCount      = (int)$db->query("SELECT COUNT(*) FROM sites")->fetchColumn();

    if ($campId) {
        // Site allocations (perpetual — not scoped to camp)
        $allocCount = (int)$db->query("SELECT COUNT(*) FROM site_allocations")->fetchColumn();

        // Payments
        $payStmt = $db->prepare("
            SELECT COUNT(*) AS cnt,
                   COALESCE(SUM(total),0)         AS total_tendered,
                   COALESCE(SUM(tender_eftpos),0)  AS total_eftpos,
                   COALESCE(SUM(tender_cash),0)    AS total_cash,
                   COALESCE(SUM(tender_bank),0)    AS total_bank,
                   COALESCE(SUM(headcount),0)      AS total_headcount
            FROM payments WHERE camp_id=?
        ");
        $payStmt->execute([$campId]);
        $payStats = $payStmt->fetch();

        // Prepayments
        $preStmt = $db->prepare("
            SELECT COUNT(*) AS cnt,
                   COALESCE(SUM(amount),0) AS remaining_balance,
                   SUM(CASE WHEN household_id IS NOT NULL THEN 1 ELSE 0 END) AS matched
            FROM prepayments WHERE camp_id=?
        ");
        $preStmt->execute([$campId]);
        $preStats = $preStmt->fetch();

        // Intranet content
        $sessionCount = (int)$db->prepare("SELECT COUNT(*) FROM camp_sessions WHERE camp_id=?")->execute([$campId]) ? 0 : 0;
        $sesStmt = $db->prepare("SELECT COUNT(*) FROM camp_sessions WHERE camp_id=?");
        $sesStmt->execute([$campId]);
        $sessionCount = (int)$sesStmt->fetchColumn();

        $noticeStmt = $db->prepare("SELECT COUNT(*) FROM notices WHERE camp_id=?");
        $noticeStmt->execute([$campId]);
        $noticeCount = (int)$noticeStmt->fetchColumn();
    } else {
        $allocCount = 0;
        $payStats   = ['cnt' => 0, 'total_tendered' => 0, 'total_eftpos' => 0, 'total_cash' => 0, 'total_bank' => 0, 'total_headcount' => 0];
        $preStats   = ['cnt' => 0, 'remaining_balance' => 0, 'matched' => 0];
        $sessionCount = 0;
        $noticeCount  = 0;
    }

    echo json_encode([
        'camp'            => $camp ?: null,
        'members'         => $memberCount,
        'households'      => $householdCount,
        'total_sites'     => $siteCount,
        'allocated_sites' => $allocCount,
        'payments'        => [
            'count'          => (int)$payStats['cnt'],
            'total_tendered' => (float)$payStats['total_tendered'],
            'total_eftpos'   => (float)$payStats['total_eftpos'],
            'total_cash'     => (float)$payStats['total_cash'],
            'total_bank'     => (float)$payStats['total_bank'],
            'total_headcount'=> (int)$payStats['total_headcount'],
        ],
        'prepayments' => [
            'count'             => (int)$preStats['cnt'],
            'remaining_balance' => (float)$preStats['remaining_balance'],
            'matched'           => (int)$preStats['matched'],
        ],
        'sessions' => $sessionCount,
        'notices'  => $noticeCount,
    ]);
}));

// ── Intranet Admin ───────────────────────────────────────────────────────────

// Helper: resolve active camp_id from query param or fall back to active camp
$activeCampId = function () {
    $db = Database::connect();
    if (!empty($_GET['camp_id'])) return (int)$_GET['camp_id'];
    $row = $db->query("SELECT id FROM camps WHERE status='active' ORDER BY id DESC LIMIT 1")->fetch();
    return $row ? (int)$row['id'] : 0;
};

// Program / Sessions
$router->get('/api/intranet/program', $protected('access_intranet', function () use ($activeCampId) {
    $campId = $activeCampId();
    if (!$campId) { echo json_encode([]); return; }
    $stmt = Database::connect()->prepare(
        "SELECT * FROM camp_sessions WHERE camp_id=? ORDER BY date, start_time, id"
    );
    $stmt->execute([$campId]);
    echo json_encode($stmt->fetchAll());
}));

$router->post('/api/intranet/session/create', $protected('access_intranet', function () {
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $db = Database::connect();
    $db->prepare("INSERT INTO camp_sessions (camp_id,title,date,start_time,end_time,location,session_type,description) VALUES (?,?,?,?,?,?,?,?)")
       ->execute([
           (int)($d['camp_id'] ?? 0), trim($d['title'] ?? ''),
           $d['date'] ?: null, $d['start_time'] ?: null, $d['end_time'] ?: null,
           trim($d['location'] ?? ''), $d['session_type'] ?? 'general', trim($d['description'] ?? ''),
       ]);
    echo json_encode(['success' => true, 'id' => (int)$db->lastInsertId()]);
}));

$router->post('/api/intranet/session/update', $protected('access_intranet', function () use ($requireId) {
    $id = $requireId(); $d = json_decode(file_get_contents('php://input'), true) ?: [];
    Database::connect()->prepare(
        "UPDATE camp_sessions SET title=?,date=?,start_time=?,end_time=?,location=?,session_type=?,description=? WHERE id=?"
    )->execute([
        trim($d['title'] ?? ''), $d['date'] ?: null, $d['start_time'] ?: null, $d['end_time'] ?: null,
        trim($d['location'] ?? ''), $d['session_type'] ?? 'general', trim($d['description'] ?? ''), $id,
    ]);
    echo json_encode(['success' => true]);
}));

$router->post('/api/intranet/session/delete', $protected('access_intranet', function () use ($requireId) {
    $id = $requireId();
    Database::connect()->prepare("DELETE FROM camp_sessions WHERE id=?")->execute([$id]);
    echo json_encode(['success' => true]);
}));

// Notices
$router->get('/api/intranet/noticeboard/list', $protected('access_intranet', function () use ($activeCampId) {
    $campId = $activeCampId();
    if (!$campId) { echo json_encode([]); return; }
    $stmt = Database::connect()->prepare(
        "SELECT id, category, title, message, contact_details, author_name, site_number, status, is_verified, verification_note, approved_at, expires_at, created_at, updated_at
         FROM camp_intranet_noticeboard
         WHERE camp_id=?
         ORDER BY FIELD(status, 'pending', 'approved', 'expired', 'rejected', 'archived'), created_at DESC"
    );
    $stmt->execute([$campId]);
    echo json_encode($stmt->fetchAll());
}));

$router->post('/api/intranet/noticeboard/save', $protected('access_intranet', function () use ($requireId) {
    $id = isset($_GET['id']) && $_GET['id'] !== '' ? (int)$_GET['id'] : null;
    (new IntranetFeaturesController())->adminSaveNoticeboard($id);
}));

$router->post('/api/intranet/noticeboard/delete', $protected('access_intranet', function () use ($requireId) {
    $id = $requireId();
    if ($id !== null) {
        (new IntranetFeaturesController())->adminDeleteNoticeboard($id);
    }
}));

// Polls
$router->get('/api/intranet/polls/list', $protected('access_intranet', function () use ($activeCampId) {
    $campId = $activeCampId();
    if (!$campId) { echo json_encode([]); return; }
    $stmt = Database::connect()->prepare("
        SELECT p.*, GROUP_CONCAT(o.label ORDER BY o.sort_order, o.id SEPARATOR '\n') AS options_text
        FROM camp_intranet_polls p
        LEFT JOIN camp_intranet_poll_options o ON o.poll_id = p.id
        WHERE p.camp_id=?
        GROUP BY p.id
        ORDER BY FIELD(p.status, 'live', 'draft', 'closed', 'archived'), p.created_at DESC
    ");
    $stmt->execute([$campId]);
    echo json_encode($stmt->fetchAll());
}));

$router->post('/api/intranet/poll/save', $protected('access_intranet', function () use ($requireId) {
    $id      = isset($_GET['id']) && $_GET['id'] !== '' ? (int)$_GET['id'] : null;
    (new IntranetFeaturesController())->adminSavePoll($id);
}));

$router->post('/api/intranet/poll/delete', $protected('access_intranet', function () use ($requireId) {
    $id = $requireId();
    if ($id !== null) {
        (new IntranetFeaturesController())->adminDeletePoll($id);
    }
}));

// Lost & Found
$router->get('/api/intranet/lost-found/list', $protected('access_intranet', function () use ($activeCampId) {
    $campId = $activeCampId();
    if (!$campId) { echo json_encode([]); return; }
    $stmt = Database::connect()->prepare("
        SELECT id, item_type, title, description, location_details, contact_details, reporter_name, site_number, status, is_verified, verification_note, admin_notes, approved_at, created_at, updated_at
        FROM camp_intranet_lost_found
        WHERE camp_id=?
        ORDER BY FIELD(status, 'pending', 'approved', 'returned', 'rejected', 'archived'), created_at DESC
    ");
    $stmt->execute([$campId]);
    echo json_encode($stmt->fetchAll());
}));

$router->post('/api/intranet/lost-found/resolve', $protected('access_intranet', function () use ($requireId) {
    $id = $requireId();
    Database::connect()->prepare("UPDATE camp_intranet_lost_found SET status='returned' WHERE id=?")->execute([$id]);
    echo json_encode(['success' => true]);
}));

// Sites list with optional camp_id — returns household allocation info per site
$router->get('/api/sites', $protected('access_operations', function () {
    $db     = Database::connect();
    $search = trim($_GET['search'] ?? '');
    $type   = trim($_GET['type']   ?? '');
    $campId = isset($_GET['camp_id']) ? (int)$_GET['camp_id'] : 0;

    // Auto-resolve active camp if not supplied
    if ($campId <= 0) {
        $active = $db->query("SELECT id FROM camps WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $campId = $active ? (int)$active['id'] : 0;
    }

    $where = []; $params = [];
    if ($search !== '') {
        $where[]  = '(s.site_number LIKE ? OR s.notes LIKE ?)';
        $params   = array_merge($params, ['%'.$search.'%', '%'.$search.'%']);
    }
    if ($type !== '') {
        $where[]  = 's.site_type = ?';
        $params[] = $type;
    }
    $wClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    if ($campId > 0) {
        $sql = "
            SELECT s.*,
                   sa.id         AS allocation_id,
                   sa.household_id,
                   h.name        AS household_name
            FROM sites s
            LEFT JOIN site_allocations sa ON sa.site_id = s.id AND sa.camp_id = $campId
            LEFT JOIN households h ON h.id = sa.household_id
            $wClause
            ORDER BY CAST(s.site_number AS UNSIGNED), s.site_number
        ";
    } else {
        $sql = "SELECT s.*, NULL AS allocation_id, NULL AS household_id, NULL AS household_name
                FROM sites s $wClause
                ORDER BY CAST(s.site_number AS UNSIGNED), s.site_number";
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['power']         = (bool)$r['power'];
        $r['allocation_id'] = $r['allocation_id'] ? (int)$r['allocation_id'] : null;
        $r['household_id']  = $r['household_id']  ? (int)$r['household_id']  : null;
    }
    echo json_encode($rows);
}));

// ── Sites (camp-aware, v2) ────────────────────────────────────────────────────
$router->get('/api/sites', $protected('access_operations', function () {
    $db     = Database::connect();
    $search = trim($_GET['search'] ?? '');
    $type   = trim($_GET['type']   ?? '');
    $filter = trim($_GET['filter'] ?? 'all'); // all | allocated | available
    $campId = isset($_GET['camp_id']) ? (int)$_GET['camp_id'] : 0;

    // Auto-resolve: active camp first, then most recent
    if ($campId <= 0) {
        $active = $db->query("SELECT id FROM camps WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($active) {
            $campId = (int)$active['id'];
        } else {
            $recent = $db->query("SELECT id FROM camps ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $campId = $recent ? (int)$recent['id'] : 0;
        }
    }

    $whereConds = []; $whereParams = [];
    if ($search !== '') {
        $whereConds[]  = '(s.site_number LIKE ? OR s.notes LIKE ?)';
        $whereParams[] = '%'.$search.'%';
        $whereParams[] = '%'.$search.'%';
    }
    if ($type !== '') {
        $whereConds[]  = 's.site_type = ?';
        $whereParams[] = $type;
    }
    if ($filter === 'allocated') $whereConds[] = 'sa.id IS NOT NULL';
    if ($filter === 'available') $whereConds[] = 'sa.id IS NULL';
    $wClause = $whereConds ? 'WHERE ' . implode(' AND ', $whereConds) : '';

    if ($campId > 0) {
        $sql = "
            SELECT s.*,
                   sa.id           AS allocation_id,
                   sa.household_id,
                   h.name          AS household_name,
                   p.id            AS payment_id,
                   p.site_fee,
                   CASE
                     WHEN p.arrival_date IS NOT NULL AND p.departure_date IS NOT NULL
                       THEN DATEDIFF(p.departure_date, p.arrival_date)
                     ELSE NULL
                   END             AS camp_nights
            FROM sites s
            LEFT JOIN site_allocations sa ON sa.site_id = s.id AND sa.camp_id = ?
            LEFT JOIN households h ON h.id = sa.household_id
            LEFT JOIN payments p
                   ON p.household_id = sa.household_id AND p.camp_id = ?
                  AND p.id = (SELECT MAX(p2.id) FROM payments p2
                              WHERE p2.household_id = sa.household_id AND p2.camp_id = ?)
            $wClause
            ORDER BY CAST(s.site_number AS UNSIGNED), s.site_number
        ";
        $allParams = array_merge([$campId, $campId, $campId], $whereParams);
    } else {
        $sql = "SELECT s.*,
                       NULL AS allocation_id, NULL AS household_id, NULL AS household_name,
                       NULL AS payment_id, NULL AS site_fee, NULL AS camp_nights
                FROM sites s $wClause
                ORDER BY CAST(s.site_number AS UNSIGNED), s.site_number";
        $allParams = $whereParams;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($allParams);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $allocated = 0; $available = 0;
    foreach ($rows as &$r) {
        $r['power']         = (bool)$r['power'];
        $r['allocation_id'] = $r['allocation_id'] ? (int)$r['allocation_id'] : null;
        $r['household_id']  = $r['household_id']  ? (int)$r['household_id']  : null;
        $r['payment_id']    = $r['payment_id']     ? (int)$r['payment_id']   : null;
        $r['site_fee']      = $r['site_fee'] !== null ? (float)$r['site_fee'] : null;
        $r['camp_nights']   = $r['camp_nights'] !== null ? (int)$r['camp_nights'] : null;
        if ($r['allocation_id']) {
            $allocated++;
            if ($r['payment_id'] && $r['site_fee'] > 0)  $r['fee_status'] = 'paid';
            elseif ($r['payment_id'])                     $r['fee_status'] = 'unpaid';
            else                                          $r['fee_status'] = 'none';
        } else {
            $available++;
            $r['fee_status'] = null;
        }
    }
    echo json_encode([
        'sites'   => $rows,
        'summary' => ['total' => count($rows), 'allocated' => $allocated, 'available' => $available],
        'camp_id' => $campId,
    ]);
}));

// ── Site detail (members + payment history per camp) ─────────────────────────
$router->get('/api/site/detail', $protected('access_operations', function () {
    $db     = Database::connect();
    $siteId = isset($_GET['site_id']) ? (int)$_GET['site_id'] : 0;
    $campId = isset($_GET['camp_id']) ? (int)$_GET['camp_id'] : 0;

    if ($siteId <= 0) { http_response_code(400); echo json_encode(['error' => 'site_id required']); return; }

    if ($campId <= 0) {
        $active = $db->query("SELECT id FROM camps WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($active) $campId = (int)$active['id'];
        else {
            $recent = $db->query("SELECT id FROM camps ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $campId = $recent ? (int)$recent['id'] : 0;
        }
    }

    // Site
    $siteStmt = $db->prepare("SELECT * FROM sites WHERE id=?");
    $siteStmt->execute([$siteId]);
    $site = $siteStmt->fetch(PDO::FETCH_ASSOC);
    if (!$site) { http_response_code(404); echo json_encode(['error' => 'Not found']); return; }
    $site['power'] = (bool)$site['power'];

    // Allocation for this camp
    $allocStmt = $db->prepare("SELECT sa.*, h.name AS household_name FROM site_allocations sa JOIN households h ON h.id=sa.household_id WHERE sa.site_id=? AND sa.camp_id=? LIMIT 1");
    $allocStmt->execute([$siteId, $campId]);
    $alloc = $allocStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // Household members
    $members = [];
    if ($alloc) {
        $memStmt = $db->prepare("SELECT id, first_name, last_name, member_type, gender, mobile, email FROM members WHERE household_id=? ORDER BY FIELD(member_type,'adult','youth','child','infant'), first_name");
        $memStmt->execute([$alloc['household_id']]);
        $members = $memStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Payment history for this household+camp
    $payments = [];
    if ($alloc && $campId > 0) {
        $payStmt = $db->prepare("SELECT p.*, GROUP_CONCAT(pt.method, ':', pt.amount ORDER BY pt.id SEPARATOR '|') AS tenders_raw FROM payments p LEFT JOIN payment_tenders pt ON pt.payment_id=p.id WHERE p.household_id=? AND p.camp_id=? GROUP BY p.id ORDER BY p.payment_date DESC");
        $payStmt->execute([$alloc['household_id'], $campId]);
        foreach ($payStmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $tenders = [];
            if ($p['tenders_raw']) {
                foreach (explode('|', $p['tenders_raw']) as $t) {
                    [$method, $amount] = explode(':', $t, 2);
                    $tenders[] = ['method' => $method, 'amount' => (float)$amount];
                }
            }
            unset($p['tenders_raw']);
            $p['total']        = (float)$p['total'];
            $p['camp_fee']     = (float)$p['camp_fee'];
            $p['site_fee']     = (float)$p['site_fee'];
            $p['tenders']      = $tenders;
            $payments[] = $p;
        }
    }

    echo json_encode([
        'site'     => $site,
        'camp_id'  => $campId,
        'alloc'    => $alloc,
        'members'  => $members,
        'payments' => $payments,
    ]);
}));

// ── Dashboard v2: summary ─────────────────────────────────────────────────────
$router->get('/api/dashboard/summary', function() {
    Auth::requireLogin();
    $db  = Database::connect();
    $cid = isset($_GET['camp_id']) ? (int)$_GET['camp_id'] : 0;

    // Resolve camp
    if ($cid > 0) {
        $camp = $db->prepare("SELECT * FROM camps WHERE id = ?");
        $camp->execute([$cid]);
        $camp = $camp->fetch(PDO::FETCH_ASSOC);
    } else {
        $camp = $db->query("SELECT * FROM camps WHERE status='active' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$camp) $camp = $db->query("SELECT * FROM camps ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    }

    if (!$camp) { echo json_encode(['camp' => null, 'finance' => null]); return; }
    $cid = (int)$camp['id'];

    // Finance totals
    $fin = $db->prepare("SELECT COUNT(*) as tx_count,
        COALESCE(SUM(camp_fee),0) as camp_fees,
        COALESCE(SUM(site_fee),0) as site_fees,
        COALESCE(SUM(camp_fee + site_fee + COALESCE(other_amount,0)),0) as total_taken,
        COALESCE(SUM(prepaid_applied),0) as prepaid_applied,
        COALESCE(SUM(tender_eftpos),0) as tender_eftpos,
        COALESCE(SUM(tender_cash),0) as tender_cash,
        COALESCE(SUM(tender_bank),0) as tender_bank,
        COALESCE(SUM(headcount),0) as total_headcount
        FROM payments WHERE camp_id=?");
    $fin->execute([$cid]);
    $f = $fin->fetch(PDO::FETCH_ASSOC);

    // Prepayments
    $ps = $db->prepare("SELECT COUNT(*) as cnt,
        COALESCE(SUM(amount),0) as total_amount,
        SUM(CASE WHEN household_id IS NOT NULL THEN 1 ELSE 0 END) as matched,
        SUM(CASE WHEN household_id IS NULL THEN 1 ELSE 0 END) as unmatched
        FROM prepayments WHERE camp_id=?");
    $ps->execute([$cid]);
    $pp = $ps->fetch(PDO::FETCH_ASSOC);

    // In camp now
    $today = date('Y-m-d');
    $ic = $db->prepare("SELECT p.headcount, p.departure_date,
        h.name as household_name,
        s.site_number
        FROM payments p
        LEFT JOIN households h ON h.id=p.household_id
        LEFT JOIN site_allocations sa ON sa.household_id=p.household_id
        LEFT JOIN sites s ON s.id=sa.site_id
        WHERE p.camp_id=? AND p.arrival_date<=? AND p.departure_date>=?
        ORDER BY (s.site_number+0) ASC");
    $ic->execute([$cid, $today, $today]);
    $icRows = $ic->fetchAll(PDO::FETCH_ASSOC);

    // All camps list for selector
    $allCamps = $db->query("SELECT id,name,year,status FROM camps ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'camp'      => $camp,
        'all_camps' => $allCamps,
        'finance'   => [
            'tx_count'        => (int)$f['tx_count'],
            'total_taken'     => (float)$f['total_taken'],
            'camp_fees'       => (float)$f['camp_fees'],
            'site_fees'       => (float)$f['site_fees'],
            'total_headcount' => (int)$f['total_headcount'],
            'tender_eftpos'   => (float)$f['tender_eftpos'],
            'tender_cash'     => (float)$f['tender_cash'],
            'tender_bank'     => (float)$f['tender_bank'],
            'prepaid_applied' => (float)$f['prepaid_applied'],
        ],
        'prepayments' => [
            'count'        => (int)$pp['cnt'],
            'total_amount' => (float)$pp['total_amount'],
            'applied'      => (float)$f['prepaid_applied'],
            'remaining'    => (float)$pp['total_amount'] - (float)$f['prepaid_applied'],
            'matched'      => (int)$pp['matched'],
            'unmatched'    => (int)$pp['unmatched'],
        ],
        'in_camp' => [
            'headcount' => (int)array_sum(array_column($icRows, 'headcount')),
            'rows'      => array_map(fn($r) => [
                'site_number'    => $r['site_number'],
                'household_name' => $r['household_name'],
                'headcount'      => (int)$r['headcount'],
                'until'          => $r['departure_date'],
            ], $icRows),
        ],
    ]);
});

// ── Dashboard v2: reconciliation ──────────────────────────────────────────────
$router->get('/api/dashboard/reconciliation', function() {
    Auth::requireLogin();
    $db        = Database::connect();
    $cid       = (int)($_GET['camp_id'] ?? 0);
    $dateFrom  = $_GET['date_from'] ?? null;
    $dateTo    = $_GET['date_to']   ?? $dateFrom;

    $where  = 'camp_id=?';
    $params = [$cid];
    if ($dateFrom) {
        $where   .= ' AND DATE(payment_date) BETWEEN ? AND ?';
        $params[] = $dateFrom;
        $params[] = $dateTo;
    }

    $stmt = $db->prepare("SELECT COUNT(*) as tx_count,
        COALESCE(SUM(camp_fee + site_fee + COALESCE(other_amount,0)),0) as total_taken,
        COALESCE(SUM(camp_fee),0) as camp_fees,
        COALESCE(SUM(site_fee),0) as site_fees,
        COALESCE(SUM(tender_eftpos),0) as tender_eftpos,
        COALESCE(SUM(tender_cash),0) as tender_cash,
        COALESCE(SUM(tender_bank),0) as tender_bank,
        COALESCE(SUM(prepaid_applied),0) as prepaid_applied
        FROM payments WHERE $where");
    $stmt->execute($params);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'tx_count'       => (int)$r['tx_count'],
        'total_taken'    => (float)$r['total_taken'],
        'camp_fees'      => (float)$r['camp_fees'],
        'site_fees'      => (float)$r['site_fees'],
        'tender_eftpos'  => (float)$r['tender_eftpos'],
        'tender_cash'    => (float)$r['tender_cash'],
        'tender_bank'    => (float)$r['tender_bank'],
        'prepaid_applied'=> (float)$r['prepaid_applied'],
    ]);
});

// ── Dashboard v2: chart data ──────────────────────────────────────────────────
$router->get('/api/dashboard/chart-data', function() {
    Auth::requireLogin();
    $db  = Database::connect();
    $cid = (int)($_GET['camp_id'] ?? 0);

    $camp = $db->prepare("SELECT start_date, end_date FROM camps WHERE id=?");
    $camp->execute([$cid]);
    $campRow = $camp->fetch(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("SELECT DATE(payment_date) as d,
        COALESCE(SUM(camp_fee + site_fee + COALESCE(other_amount,0)),0) as total_taken,
        COALESCE(SUM(camp_fee),0) as camp_fees,
        COALESCE(SUM(site_fee),0) as site_fees,
        COALESCE(SUM(headcount),0) as headcount
        FROM payments WHERE camp_id=?
        GROUP BY DATE(payment_date) ORDER BY d ASC");
    $stmt->execute([$cid]);
    $byDate = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $byDate[$row['d']] = $row;

    // Generate date range
    $dates = [];
    if ($campRow && $campRow['start_date'] && $campRow['end_date']) {
        $cur = new DateTime($campRow['start_date']);
        $end = new DateTime(min($campRow['end_date'], date('Y-m-d')));
        while ($cur <= $end) { $dates[] = $cur->format('Y-m-d'); $cur->modify('+1 day'); }
    } else {
        $dates = array_keys($byDate);
    }

    $out = ['dates'=>[],'total_taken'=>[],'camp_fees'=>[],'site_fees'=>[],'headcount'=>[]];
    $rt = $rc = $rs = 0;
    foreach ($dates as $date) {
        $row = $byDate[$date] ?? ['total_taken'=>0,'camp_fees'=>0,'site_fees'=>0,'headcount'=>0];
        $rt += (float)$row['total_taken'];
        $rc += (float)$row['camp_fees'];
        $rs += (float)$row['site_fees'];
        $out['dates'][]       = $date;
        $out['total_taken'][] = round($rt, 2);
        $out['camp_fees'][]   = round($rc, 2);
        $out['site_fees'][]   = round($rs, 2);
        $out['headcount'][]   = (int)$row['headcount'];
    }

    echo json_encode($out);
});

// ── Member-households (CS-linked households) ──────────────────────────────────
$router->post('/api/member-households', $protected('access_operations', function () {
    Auth::requireLogin();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $name = trim($data['name'] ?? '');
    if ($name === '') { http_response_code(400); echo json_encode(['error' => 'Name required']); return; }
    $db = Database::connect();
    $db->prepare("INSERT INTO member_households (display_name, source_system, source_household_key) VALUES (?, 'manual', ?)")
       ->execute([$name, 'manual-' . uniqid()]);
    $id = (int)$db->lastInsertId();
    echo json_encode(['success' => true, 'id' => $id, 'name' => $name]);
}));

$router->post('/api/member-household/update', $protected('access_operations', function () use ($requireId) {
    Auth::requireLogin();
    $id   = $requireId(); if ($id === null) return;
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $name = trim($data['name'] ?? '');
    if ($name === '') { http_response_code(400); echo json_encode(['error' => 'Name required']); return; }
    $db = Database::connect();
    $db->prepare("UPDATE member_households SET display_name = ? WHERE id = ?")->execute([$name, $id]);
    echo json_encode(['success' => true]);
}));

$router->get('/api/member-households', $protected('access_operations', function () {
    $db   = Database::connect();
    $q    = trim($_GET['q'] ?? '');
    if ($q !== '') {
        $stmt = $db->prepare("
            SELECT mh.id, mh.display_name AS name, COUNT(mhm.member_id) AS member_count
            FROM member_households mh
            LEFT JOIN member_household_members mhm ON mhm.household_id = mh.id
            WHERE mh.display_name LIKE ?
            GROUP BY mh.id ORDER BY mh.display_name LIMIT 50
        ");
        $stmt->execute(['%' . $q . '%']);
    } else {
        $stmt = $db->query("
            SELECT mh.id, mh.display_name AS name, COUNT(mhm.member_id) AS member_count
            FROM member_households mh
            LEFT JOIN member_household_members mhm ON mhm.household_id = mh.id
            GROUP BY mh.id ORDER BY mh.display_name LIMIT 200
        ");
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['member_count'] = (int)$r['member_count']; }
    echo json_encode($rows);
}));

// ── Assign member to a member_household ───────────────────────────────────────
$router->post('/api/member/assign-household', $protected('access_operations', function () use ($requireId) {
    Auth::requireLogin();
    $memberId    = $requireId('member_id'); if ($memberId === null) return;
    $data        = json_decode(file_get_contents('php://input'), true) ?? [];
    $householdId = isset($data['household_id']) && $data['household_id'] !== '' ? (int)$data['household_id'] : null;
    $db          = Database::connect();

    // Remove from any existing CS household
    $db->prepare("DELETE FROM member_household_members WHERE member_id = ? AND source_system = 'manual'")->execute([(int)$memberId]);

    if ($householdId) {
        // Verify household exists
        $hh = $db->prepare("SELECT id FROM member_households WHERE id = ?");
        $hh->execute([$householdId]);
        if (!$hh->fetchColumn()) { http_response_code(404); echo json_encode(['error' => 'Household not found']); return; }

        // Add to new household
        $db->prepare("
            INSERT INTO member_household_members (household_id, member_id, role_label, is_primary, source_system)
            VALUES (?, ?, 'Member', 0, 'manual')
            ON DUPLICATE KEY UPDATE role_label = 'Member'
        ")->execute([$householdId, (int)$memberId]);
    }

    echo json_encode(['success' => true]);
}));

$router->get('/api/member/cs-household', $protected('access_operations', function () use ($requireId) {
    $memberId = $requireId('member_id');
    if ($memberId === null) return;
    require_once __DIR__ . '/src/MemberMatchingService.php';
    require_once __DIR__ . '/src/MemberHouseholdService.php';
    $service = new MemberHouseholdService(Database::connect());
    $detail  = $service->getHouseholdForMember((int)$memberId);
    echo json_encode($detail ?: (object)[]);
}));

$router->post('/api/churchsuite/fill-missing-spouses', $protected('access_operations', function () {
    Auth::requireLogin();
    require_once __DIR__ . '/src/MemberMatchingService.php';
    require_once __DIR__ . '/src/ChurchSuiteTokenStore.php';
    require_once __DIR__ . '/src/ChurchSuiteClientInterface.php';
    require_once __DIR__ . '/src/ChurchSuiteOAuthClient.php';
    require_once __DIR__ . '/src/MemberHouseholdService.php';

    $db = Database::connect();

    $stmt = $db->query("
        SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(churchsuite_payload_json, '$.spouse_id')) AS spouse_id
        FROM members
        WHERE churchsuite_person_type = 'contact'
          AND JSON_EXTRACT(churchsuite_payload_json, '$.spouse_id') IS NOT NULL
          AND JSON_UNQUOTE(JSON_EXTRACT(churchsuite_payload_json, '$.spouse_id')) != 'null'
    ");
    $spouseIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'spouse_id');

    $missing = [];
    foreach ($spouseIds as $sid) {
        $sid = trim((string)$sid);
        if ($sid === '' || $sid === 'null') continue;
        $exists = $db->prepare("SELECT COUNT(*) FROM members WHERE churchsuite_person_id = ? AND churchsuite_person_type = 'contact'");
        $exists->execute([$sid]);
        if (!(int)$exists->fetchColumn()) $missing[] = $sid;
    }

    if (empty($missing)) {
        echo json_encode(['success' => true, 'fetched' => 0, 'message' => 'No missing members found']);
        return;
    }

    $client = new ChurchSuiteOAuthClient(new ChurchSuiteTokenStore($db));
    $syncStamp = date('Y-m-d H:i:s');
    $fetched = 0; $failed = 0;

    foreach ($missing as $spouseId) {
        try {
            $record = $client->fetchContact((int)$spouseId);
            if (!$record || empty($record['id'])) { $failed++; continue; }

            $check = $db->prepare("SELECT id FROM members WHERE churchsuite_person_id = ? AND churchsuite_person_type = 'contact' LIMIT 1");
            $check->execute([(string)$record['id']]);
            if ($check->fetchColumn()) continue;

            $insert = $db->prepare("
                INSERT INTO members (first_name, last_name, email, mobile, phone,
                    fellowship, concession, site_fee_status,
                    churchsuite_person_type, churchsuite_person_id, churchsuite_sync_status,
                    churchsuite_last_synced_at, churchsuite_payload_json, digital_agreement_confirmed)
                VALUES (?, ?, ?, ?, ?, '', 'No', 'Unknown', 'contact', ?, 'ok', ?, ?, 0)
            ");
            $insert->execute([
                trim((string)($record['first_name'] ?? '')),
                trim((string)($record['last_name']  ?? '')),
                trim((string)($record['email'] ?? $record['email_address'] ?? '')),
                trim((string)($record['mobile'] ?? '')),
                trim((string)($record['telephone'] ?? $record['phone'] ?? '')),
                (string)$record['id'],
                $syncStamp,
                json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
            $fetched++;
        } catch (Exception $e) {
            error_log('fill-missing-spouses id=' . $spouseId . ' err=' . $e->getMessage());
            $failed++;
        }
    }

    if ($fetched > 0) {
        $service = new MemberHouseholdService($db);
        $service->rebuildChurchSuiteStructures([]);
    }

    echo json_encode(['success' => true, 'missing_found' => count($missing), 'fetched' => $fetched, 'failed' => $failed]);
}));

// ── ChurchSuite: search contacts ──────────────────────────────────────────────
$router->get('/api/churchsuite/search-contacts', $protected('access_operations', function () {
    Auth::requireLogin();
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { echo json_encode(['results' => []]); return; }
    require_once __DIR__ . '/src/ChurchSuiteTokenStore.php';
    require_once __DIR__ . '/src/ChurchSuiteClientInterface.php';
    require_once __DIR__ . '/src/ChurchSuiteOAuthClient.php';
    $db = Database::connect();
    $client = new ChurchSuiteOAuthClient(new ChurchSuiteTokenStore($db));
    $records = $client->searchContacts($q);
    $results = array_map(fn($r) => [
        'id'         => $r['id'],
        'first_name' => $r['first_name'] ?? '',
        'last_name'  => $r['last_name']  ?? '',
        'email'      => $r['email'] ?? $r['email_address'] ?? '',
        'mobile'     => $r['mobile'] ?? '',
        'in_db'      => false,
    ], $records);
    // flag which are already imported
    if ($results) {
        $csIds = array_column($results, 'id');
        $placeholders = implode(',', array_fill(0, count($csIds), '?'));
        $stmt = $db->prepare("SELECT churchsuite_person_id FROM members WHERE churchsuite_person_type='contact' AND churchsuite_person_id IN ($placeholders)");
        $stmt->execute(array_map('strval', $csIds));
        $inDb = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
        foreach ($results as &$r) $r['in_db'] = isset($inDb[(string)$r['id']]);
        unset($r);
    }
    echo json_encode(['results' => $results]);
}));

// ── ChurchSuite: import contact by ID ─────────────────────────────────────────
$router->post('/api/churchsuite/import-contact', $protected('access_operations', function () {
    Auth::requireLogin();
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $csId = (int)($body['cs_id'] ?? 0);
    if (!$csId) { http_response_code(400); echo json_encode(['error' => 'cs_id required']); return; }
    require_once __DIR__ . '/src/ChurchSuiteTokenStore.php';
    require_once __DIR__ . '/src/ChurchSuiteClientInterface.php';
    require_once __DIR__ . '/src/ChurchSuiteOAuthClient.php';
    require_once __DIR__ . '/src/MemberHouseholdService.php';
    require_once __DIR__ . '/src/MemberMatchingService.php';
    $db = Database::connect();
    // Check not already imported
    $exists = $db->prepare("SELECT id FROM members WHERE churchsuite_person_type='contact' AND churchsuite_person_id=? LIMIT 1");
    $exists->execute([(string)$csId]);
    if ($exists->fetchColumn()) { echo json_encode(['success' => false, 'message' => 'Already imported']); return; }
    $client = new ChurchSuiteOAuthClient(new ChurchSuiteTokenStore($db));
    $record = $client->fetchContact($csId);
    if (!$record || empty($record['id'])) { echo json_encode(['success' => false, 'message' => 'Contact not found in ChurchSuite']); return; }
    $syncStamp = date('Y-m-d H:i:s');
    $insert = $db->prepare("
        INSERT INTO members (first_name, last_name, email, mobile, phone,
            fellowship, concession, site_fee_status,
            churchsuite_person_type, churchsuite_person_id, churchsuite_sync_status,
            churchsuite_last_synced_at, churchsuite_payload_json, digital_agreement_confirmed)
        VALUES (?, ?, ?, ?, ?, '', 'No', 'Unknown', 'contact', ?, 'ok', ?, ?, 0)
    ");
    $insert->execute([
        trim((string)($record['first_name'] ?? '')),
        trim((string)($record['last_name']  ?? '')),
        trim((string)($record['email'] ?? $record['email_address'] ?? '')),
        trim((string)($record['mobile'] ?? '')),
        trim((string)($record['telephone'] ?? $record['phone'] ?? '')),
        (string)$record['id'],
        $syncStamp,
        json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    $newId = (int)$db->lastInsertId();
    $service = new MemberHouseholdService($db);
    $service->rebuildChurchSuiteStructures([]);
    echo json_encode(['success' => true, 'member_id' => $newId, 'name' => trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? ''))]);
}));

// ── Persistent household site allocations ────────────────────────────────────
// Site allocations belong to households and carry across camps. camp_id is kept
// on site_allocations only for legacy compatibility and is not used as scope.
$router->get('/api/site-allocations', $protected('access_operations', function () {
    $db = Database::connect();
    $campId = isset($_GET['camp_id']) ? (int)$_GET['camp_id'] : 0;
    if ($campId <= 0) {
        $active = $db->query("SELECT id FROM camps WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $campId = $active ? (int)$active['id'] : null;
    }

    $stmt = $db->prepare("
        SELECT s.id AS site_id, s.site_number, s.site_type, s.power, s.capacity,
               s.map_lat, s.map_lng,
               sa.id AS allocation_id, sa.household_id, sa.notes AS allocation_notes,
               sa.site_fee_expires,
               h.name AS household_name,
               COUNT(m.id) AS member_count
        FROM sites s
        LEFT JOIN (
            SELECT sa1.*
            FROM site_allocations sa1
            JOIN (
                SELECT site_id, MIN(id) AS id
                FROM site_allocations
                GROUP BY site_id
            ) picked ON picked.id = sa1.id
        ) sa ON sa.site_id = s.id
        LEFT JOIN households h ON h.id = sa.household_id
        LEFT JOIN members m ON m.household_id = h.id
        GROUP BY s.id, sa.id
        ORDER BY CAST(s.site_number AS UNSIGNED), s.site_number
    ");
    $stmt->execute();
    $allocations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allocations as &$a) {
        $a['power'] = (bool)$a['power'];
        $a['member_count'] = (int)$a['member_count'];
        $a['allocation_id'] = $a['allocation_id'] ? (int)$a['allocation_id'] : null;
        $a['household_id'] = $a['household_id'] ? (int)$a['household_id'] : null;
    }
    unset($a);

    $assigned = $db->query("SELECT DISTINCT household_id FROM site_allocations")->fetchAll(PDO::FETCH_COLUMN);
    $assignedIds = array_map('intval', $assigned);
    $unassigned = $db->query("
        SELECT h.id, h.name, COUNT(m.id) AS member_count
        FROM households h
        LEFT JOIN members m ON m.household_id = h.id
        GROUP BY h.id
        ORDER BY h.name
    ")->fetchAll(PDO::FETCH_ASSOC);
    $unassigned = array_values(array_filter($unassigned, fn($h) => !in_array((int)$h['id'], $assignedIds, true)));
    foreach ($unassigned as &$u) {
        $u['member_count'] = (int)$u['member_count'];
    }
    unset($u);

    echo json_encode(['allocations' => $allocations, 'unassigned_households' => $unassigned, 'camp_id' => $campId]);
}));

$router->post('/api/site-allocations', $protected('access_operations', function () {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $db = Database::connect();
    $siteId = (int)($data['site_id'] ?? 0);
    $householdId = (int)($data['household_id'] ?? 0);
    $campId = (int)($data['camp_id'] ?? 0);
    $notes = trim($data['notes'] ?? '');
    if ($siteId <= 0 || $householdId <= 0) {
        http_response_code(400);
        echo json_encode(['message' => 'Site and household are required']);
        return;
    }
    if ($campId <= 0) {
        $campId = (int)$db->query("SELECT id FROM camps ORDER BY id LIMIT 1")->fetchColumn();
    }

    $conflict = $db->prepare("SELECT id FROM site_allocations WHERE household_id=? AND site_id<>? LIMIT 1");
    $conflict->execute([$householdId, $siteId]);
    if ($conflict->fetchColumn()) {
        http_response_code(409);
        echo json_encode(['message' => 'Household already allocated to another site']);
        return;
    }

    $existing = $db->prepare("SELECT id FROM site_allocations WHERE site_id=? LIMIT 1");
    $existing->execute([$siteId]);
    $id = $existing->fetchColumn();
    if ($id) {
        $db->prepare("UPDATE site_allocations SET household_id=?, notes=? WHERE id=?")->execute([$householdId, $notes, (int)$id]);
        echo json_encode(['success' => true, 'id' => (int)$id]);
        return;
    }

    $stmt = $db->prepare("INSERT INTO site_allocations (camp_id, site_id, household_id, notes) VALUES (?,?,?,?)");
    $stmt->execute([$campId, $siteId, $householdId, $notes]);
    echo json_encode(['success' => true, 'id' => (int)$db->lastInsertId()]);
}));

$router->post('/api/site-allocation/update', $protected('access_operations', function () use ($requireId) {
    $id = $requireId();
    if ($id === null) return;
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $db = Database::connect();
    $householdId = (int)($data['household_id'] ?? 0);
    $notes = trim($data['notes'] ?? '');
    if ($householdId <= 0) {
        http_response_code(400);
        echo json_encode(['message' => 'Household is required']);
        return;
    }
    $current = $db->prepare("SELECT site_id FROM site_allocations WHERE id=? LIMIT 1");
    $current->execute([(int)$id]);
    $siteId = (int)$current->fetchColumn();
    if ($siteId <= 0) {
        http_response_code(404);
        echo json_encode(['message' => 'Allocation not found']);
        return;
    }
    $conflict = $db->prepare("SELECT id FROM site_allocations WHERE household_id=? AND site_id<>? LIMIT 1");
    $conflict->execute([$householdId, $siteId]);
    if ($conflict->fetchColumn()) {
        http_response_code(409);
        echo json_encode(['message' => 'Household already allocated to another site']);
        return;
    }
    $db->prepare("UPDATE site_allocations SET household_id=?, notes=? WHERE id=?")->execute([$householdId, $notes, (int)$id]);
    echo json_encode(['success' => true]);
}));

$router->get('/api/households/search', $protected('access_operations', function () {
    $q = trim($_GET['q'] ?? '');
    $db = Database::connect();
    $params = [];
    $where = '';
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where = 'WHERE (h.name LIKE ? OR s.site_number LIKE ?)';
        $params = [$like, $like];
    }
    $stmt = $db->prepare("
        SELECT h.id, h.name,
               s.site_number,
               (SELECT COUNT(*) FROM members WHERE household_id = h.id) AS member_count
        FROM households h
        LEFT JOIN site_allocations sa ON sa.household_id = h.id
        LEFT JOIN sites s ON sa.site_id = s.id
        {$where}
        ORDER BY h.name ASC
        LIMIT 12
    ");
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll());
}));

$router->get('/api/household/payment-context', $protected('access_operations', function () {
    $hid = isset($_GET['household_id']) ? (int)$_GET['household_id'] : 0;
    $campId = isset($_GET['camp_id']) ? (int)$_GET['camp_id'] : 0;
    if (!$hid || !$campId) { http_response_code(400); echo json_encode(['error' => 'household_id and camp_id required']); return; }
    $db = Database::connect();

    $h = $db->prepare("SELECT id, name, notes FROM households WHERE id=? LIMIT 1");
    $h->execute([$hid]);
    $household = $h->fetch();
    if (!$household) { http_response_code(404); echo json_encode(['error' => 'Household not found']); return; }

    $sa = $db->prepare("
        SELECT s.id AS site_id, s.site_number
        FROM site_allocations sa
        JOIN sites s ON sa.site_id = s.id
        WHERE sa.household_id = ?
        LIMIT 1
    ");
    $sa->execute([$hid]);
    $alloc = $sa->fetch();

    $mem = $db->prepare("
        SELECT id, CONCAT(first_name,' ',last_name) AS name, member_type, gender
        FROM members WHERE household_id = ? ORDER BY first_name, last_name
    ");
    $mem->execute([$hid]);
    $members = $mem->fetchAll();

    $pre = $db->prepare("
        SELECT id, name, amount, method, reference
        FROM prepayments
        WHERE household_id = ? AND camp_id = ? AND amount > 0
        ORDER BY id ASC
    ");
    $pre->execute([$hid, $campId]);
    $prepayments = $pre->fetchAll();
    $prepaymentBalance = array_sum(array_column($prepayments, 'amount'));

    $pays = $db->prepare("
        SELECT id, payment_date, camp_fee, site_fee, prepaid_applied, other_amount, total,
               tender_eftpos, tender_cash, tender_bank, notes, headcount, arrival_date, departure_date
        FROM payments
        WHERE household_id = ? AND camp_id = ?
        ORDER BY payment_date DESC, id DESC
        LIMIT 10
    ");
    $pays->execute([$hid, $campId]);
    $payments = $pays->fetchAll();
    $totalPaid = array_sum(array_column($payments, 'total'));

    echo json_encode([
        'household' => $household,
        'site_id' => $alloc ? (int)$alloc['site_id'] : null,
        'site_number' => $alloc ? $alloc['site_number'] : null,
        'members' => $members,
        'prepayments' => $prepayments,
        'prepayment_balance' => (float)$prepaymentBalance,
        'payments' => $payments,
        'total_paid' => (float)$totalPaid,
    ]);
}));

$router->get('/api/sites', $protected('access_operations', function () {
    $db = Database::connect();
    $search = trim($_GET['search'] ?? '');
    $type = trim($_GET['type'] ?? '');
    $filter = trim($_GET['filter'] ?? 'all');
    $campId = isset($_GET['camp_id']) ? (int)$_GET['camp_id'] : 0;

    $whereConds = [];
    $whereParams = [];
    if ($search !== '') {
        $whereConds[] = '(s.site_number LIKE ? OR s.notes LIKE ?)';
        $whereParams[] = '%' . $search . '%';
        $whereParams[] = '%' . $search . '%';
    }
    if ($type !== '') {
        $whereConds[] = 's.site_type = ?';
        $whereParams[] = $type;
    }
    if ($filter === 'allocated') $whereConds[] = 'sa.id IS NOT NULL';
    if ($filter === 'available') $whereConds[] = 'sa.id IS NULL';
    $wClause = $whereConds ? 'WHERE ' . implode(' AND ', $whereConds) : '';

    $stmt = $db->prepare("
        SELECT s.*,
               sa.id AS allocation_id,
               sa.household_id,
               h.name AS household_name,
               p.id AS payment_id,
               p.site_fee,
               CASE
                 WHEN p.arrival_date IS NOT NULL AND p.departure_date IS NOT NULL
                   THEN DATEDIFF(p.departure_date, p.arrival_date)
                 ELSE NULL
               END AS camp_nights
        FROM sites s
        LEFT JOIN site_allocations sa ON sa.site_id = s.id
        LEFT JOIN households h ON h.id = sa.household_id
        LEFT JOIN payments p
               ON p.household_id = sa.household_id AND p.camp_id = ?
              AND p.id = (SELECT MAX(p2.id) FROM payments p2
                          WHERE p2.household_id = sa.household_id AND p2.camp_id = ?)
        $wClause
        ORDER BY CAST(s.site_number AS UNSIGNED), s.site_number
    ");
    $stmt->execute(array_merge([$campId, $campId], $whereParams));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $allocated = 0; $available = 0;
    foreach ($rows as &$r) {
        $r['power'] = (bool)$r['power'];
        $r['allocation_id'] = $r['allocation_id'] ? (int)$r['allocation_id'] : null;
        $r['household_id'] = $r['household_id'] ? (int)$r['household_id'] : null;
        $r['payment_id'] = $r['payment_id'] ? (int)$r['payment_id'] : null;
        $r['site_fee'] = $r['site_fee'] !== null ? (float)$r['site_fee'] : null;
        $r['camp_nights'] = $r['camp_nights'] !== null ? (int)$r['camp_nights'] : null;
        if ($r['allocation_id']) {
            $allocated++;
            if ($r['payment_id'] && $r['site_fee'] > 0) $r['fee_status'] = 'paid';
            elseif ($r['payment_id']) $r['fee_status'] = 'unpaid';
            else $r['fee_status'] = 'none';
        } else {
            $available++;
            $r['fee_status'] = null;
        }
    }
    unset($r);
    echo json_encode([
        'sites' => $rows,
        'summary' => ['total' => count($rows), 'allocated' => $allocated, 'available' => $available],
        'camp_id' => $campId,
    ]);
}));

$router->get('/api/site/detail', $protected('access_operations', function () {
    $db = Database::connect();
    $siteId = isset($_GET['site_id']) ? (int)$_GET['site_id'] : 0;
    $campId = isset($_GET['camp_id']) ? (int)$_GET['camp_id'] : 0;
    if ($siteId <= 0) { http_response_code(400); echo json_encode(['error' => 'site_id required']); return; }

    $siteStmt = $db->prepare("SELECT * FROM sites WHERE id=?");
    $siteStmt->execute([$siteId]);
    $site = $siteStmt->fetch(PDO::FETCH_ASSOC);
    if (!$site) { http_response_code(404); echo json_encode(['error' => 'Not found']); return; }
    $site['power'] = (bool)$site['power'];

    $allocStmt = $db->prepare("SELECT sa.*, h.name AS household_name FROM site_allocations sa JOIN households h ON h.id=sa.household_id WHERE sa.site_id=? LIMIT 1");
    $allocStmt->execute([$siteId]);
    $alloc = $allocStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $members = [];
    if ($alloc) {
        $memStmt = $db->prepare("SELECT id, first_name, last_name, member_type, gender, mobile, email FROM members WHERE household_id=? ORDER BY FIELD(member_type,'adult','youth','child','infant'), first_name");
        $memStmt->execute([$alloc['household_id']]);
        $members = $memStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $payments = [];
    if ($alloc && $campId > 0) {
        $payStmt = $db->prepare("SELECT p.*, GROUP_CONCAT(pt.method, ':', pt.amount ORDER BY pt.id SEPARATOR '|') AS tenders_raw FROM payments p LEFT JOIN payment_tenders pt ON pt.payment_id=p.id WHERE p.household_id=? AND p.camp_id=? GROUP BY p.id ORDER BY p.payment_date DESC");
        $payStmt->execute([$alloc['household_id'], $campId]);
        foreach ($payStmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $tenders = [];
            if ($p['tenders_raw']) {
                foreach (explode('|', $p['tenders_raw']) as $t) {
                    [$method, $amount] = explode(':', $t, 2);
                    $tenders[] = ['method' => $method, 'amount' => (float)$amount];
                }
            }
            unset($p['tenders_raw']);
            $p['total'] = (float)$p['total'];
            $p['camp_fee'] = (float)$p['camp_fee'];
            $p['site_fee'] = (float)$p['site_fee'];
            $p['tenders'] = $tenders;
            $payments[] = $p;
        }
    }

    echo json_encode(['site' => $site, 'camp_id' => $campId, 'alloc' => $alloc, 'members' => $members, 'payments' => $payments]);
}));

$router->get('/api/payments', $protected('access_operations', function () {
    $db = Database::connect();
    $campId = isset($_GET['camp_id']) && $_GET['camp_id'] !== '' ? (int)$_GET['camp_id'] : null;
    $hid = isset($_GET['household_id']) && $_GET['household_id'] !== '' ? (int)$_GET['household_id'] : null;
    $search = trim($_GET['search'] ?? '');
    $conditions = [];
    $params = [];
    if ($campId) { $conditions[] = 'p.camp_id = ?'; $params[] = $campId; }
    if ($hid) { $conditions[] = 'p.household_id = ?'; $params[] = $hid; }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $conditions[] = '(h.name LIKE ? OR p.notes LIKE ?)';
        $params[] = $like; $params[] = $like;
    }
    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
    $stmt = $db->prepare("
        SELECT p.*, h.name AS household_name, s.site_number
        FROM payments p
        JOIN households h ON p.household_id = h.id
        LEFT JOIN site_allocations sa ON sa.household_id = p.household_id
        LEFT JOIN sites s ON sa.site_id = s.id
        {$where}
        ORDER BY p.payment_date DESC, p.id DESC
    ");
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll());
}));

// ═══════════════════════════════════════════════════════════════════════════
// Items 20 & 21 — Site fee expiry: display on cards + fee filter tabs
// ═══════════════════════════════════════════════════════════════════════════

// Enhanced GET /api/sites — adds site_fee_expires, fee_expiry_status, fee_filter param
$router->get('/api/sites', $protected('access_operations', function () {
    $db = Database::connect();
    $search    = trim($_GET['search']     ?? '');
    $type      = trim($_GET['type']       ?? '');
    $filter    = trim($_GET['filter']     ?? 'all');
    $feeFilter = trim($_GET['fee_filter'] ?? 'all');
    $campId    = isset($_GET['camp_id']) ? (int)$_GET['camp_id'] : 0;

    $whereConds  = [];
    $whereParams = [];

    if ($search !== '') {
        $whereConds[]  = '(s.site_number LIKE ? OR s.notes LIKE ?)';
        $whereParams[] = '%' . $search . '%';
        $whereParams[] = '%' . $search . '%';
    }
    if ($type !== '') {
        $whereConds[]  = 's.site_type = ?';
        $whereParams[] = $type;
    }
    if ($filter === 'allocated') $whereConds[] = 'sa.id IS NOT NULL';
    if ($filter === 'available') $whereConds[] = 'sa.id IS NULL';

    // Fee expiry filter (all apply to allocated sites implicitly)
    if ($feeFilter === 'current')    $whereConds[] = 'sa.site_fee_expires >= CURDATE()';
    if ($feeFilter === 'overdue')    $whereConds[] = 'sa.site_fee_expires < CURDATE() AND sa.site_fee_expires >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)';
    if ($feeFilter === 'overdue_6m') $whereConds[] = 'sa.site_fee_expires < DATE_SUB(CURDATE(), INTERVAL 6 MONTH)';
    if ($feeFilter === 'unknown')    $whereConds[] = 'sa.id IS NOT NULL AND sa.site_fee_expires IS NULL';

    $wClause = $whereConds ? 'WHERE ' . implode(' AND ', $whereConds) : '';

    $stmt = $db->prepare("
        SELECT s.*,
               sa.id AS allocation_id,
               sa.household_id,
               sa.site_fee_expires,
               h.name AS household_name,
               p.id AS payment_id,
               p.site_fee,
               CASE
                 WHEN p.arrival_date IS NOT NULL AND p.departure_date IS NOT NULL
                   THEN DATEDIFF(p.departure_date, p.arrival_date)
                 ELSE NULL
               END AS camp_nights
        FROM sites s
        LEFT JOIN site_allocations sa ON sa.site_id = s.id AND sa.camp_id = ?
        LEFT JOIN households h ON h.id = sa.household_id
        LEFT JOIN payments p
               ON p.household_id = sa.household_id AND p.camp_id = ?
              AND p.id = (SELECT MAX(p2.id) FROM payments p2
                          WHERE p2.household_id = sa.household_id AND p2.camp_id = ?)
        $wClause
        ORDER BY CAST(s.site_number AS UNSIGNED), s.site_number
    ");
    $stmt->execute(array_merge([$campId, $campId, $campId], $whereParams));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $today        = date('Y-m-d');
    $sixMonthsAgo = date('Y-m-d', strtotime('-6 months'));
    $allocated    = 0; $available = 0;
    $feeCurrent   = 0; $feeOverdue = 0; $feeOverdue6m = 0; $feeUnknown = 0;

    foreach ($rows as &$r) {
        $r['power']         = (bool)$r['power'];
        $r['allocation_id'] = $r['allocation_id'] ? (int)$r['allocation_id'] : null;
        $r['household_id']  = $r['household_id']  ? (int)$r['household_id']  : null;
        $r['payment_id']    = $r['payment_id']    ? (int)$r['payment_id']    : null;
        $r['site_fee']      = $r['site_fee']      !== null ? (float)$r['site_fee'] : null;
        $r['camp_nights']   = $r['camp_nights']   !== null ? (int)$r['camp_nights'] : null;

        if ($r['allocation_id']) {
            $allocated++;
            $exp = $r['site_fee_expires'];
            if ($exp === null) {
                $r['fee_expiry_status'] = 'unknown';
                $feeUnknown++;
            } elseif ($exp >= $today) {
                $r['fee_expiry_status'] = 'current';
                $feeCurrent++;
            } elseif ($exp >= $sixMonthsAgo) {
                $r['fee_expiry_status'] = 'overdue';
                $feeOverdue++;
            } else {
                $r['fee_expiry_status'] = 'overdue_6m';
                $feeOverdue6m++;
            }
        } else {
            $available++;
            $r['fee_expiry_status'] = null;
        }
    }
    unset($r);

    echo json_encode([
        'sites'   => $rows,
        'summary' => [
            'total'          => count($rows),
            'allocated'      => $allocated,
            'available'      => $available,
            'fee_current'    => $feeCurrent,
            'fee_overdue'    => $feeOverdue,
            'fee_overdue_6m' => $feeOverdue6m,
            'fee_unknown'    => $feeUnknown,
        ],
        'camp_id' => $campId,
    ]);
}));

// Enhanced GET /api/household/payment-context — returns site_fee_expires for the camp's allocation
$router->get('/api/household/payment-context', $protected('access_operations', function () {
    $hid    = isset($_GET['household_id']) ? (int)$_GET['household_id'] : 0;
    $campId = isset($_GET['camp_id'])      ? (int)$_GET['camp_id']      : 0;
    if (!$hid || !$campId) { http_response_code(400); echo json_encode(['error' => 'household_id and camp_id required']); return; }
    $db = Database::connect();

    $h = $db->prepare("SELECT id, name, notes FROM households WHERE id=? LIMIT 1");
    $h->execute([$hid]);
    $household = $h->fetch();
    if (!$household) { http_response_code(404); echo json_encode(['error' => 'Household not found']); return; }

    $sa = $db->prepare("
        SELECT s.id AS site_id, s.site_number, sa.site_fee_expires
        FROM site_allocations sa
        JOIN sites s ON sa.site_id = s.id
        WHERE sa.household_id = ? AND sa.camp_id = ?
        LIMIT 1
    ");
    $sa->execute([$hid, $campId]);
    $alloc = $sa->fetch() ?: null;

    $mem = $db->prepare("
        SELECT id, CONCAT(first_name,' ',last_name) AS name, member_type, gender
        FROM members WHERE household_id = ? ORDER BY first_name, last_name
    ");
    $mem->execute([$hid]);
    $members = $mem->fetchAll();

    $pre = $db->prepare("
        SELECT id, name, amount, method, reference
        FROM prepayments
        WHERE household_id = ? AND camp_id = ? AND amount > 0
        ORDER BY id ASC
    ");
    $pre->execute([$hid, $campId]);
    $prepayments = $pre->fetchAll();
    $prepaymentBalance = array_sum(array_column($prepayments, 'amount'));

    $pays = $db->prepare("
        SELECT id, payment_date, camp_fee, site_fee, prepaid_applied, other_amount, total,
               tender_eftpos, tender_cash, tender_bank, notes, headcount, arrival_date, departure_date
        FROM payments
        WHERE household_id = ? AND camp_id = ?
        ORDER BY payment_date DESC, id DESC
        LIMIT 10
    ");
    $pays->execute([$hid, $campId]);
    $payments = $pays->fetchAll();
    $totalPaid = array_sum(array_column($payments, 'total'));

    echo json_encode([
        'household'         => $household,
        'site_id'           => $alloc ? (int)$alloc['site_id']     : null,
        'site_number'       => $alloc ? $alloc['site_number']       : null,
        'site_fee_expires'  => $alloc ? $alloc['site_fee_expires']  : null,
        'members'           => $members,
        'prepayments'       => $prepayments,
        'prepayment_balance'=> (float)$prepaymentBalance,
        'payments'          => $payments,
        'total_paid'        => (float)$totalPaid,
    ]);
}));

// Enhanced POST /api/payments — extends site_fee_expires when site_fee > 0 + site_fee_months > 0
$router->post('/api/payments', $protected('access_operations', function () {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $db   = Database::connect();
    $db->beginTransaction();
    try {
        $hid            = (int)($data['household_id']   ?? 0);
        $campId         = (int)($data['camp_id']        ?? 0);
        $campFee        = (float)($data['camp_fee']     ?? 0);
        $siteFee        = (float)($data['site_fee']     ?? 0);
        $otherAmount    = (float)($data['other_amount'] ?? 0);
        $prepaidApplied = (float)($data['prepaid_applied'] ?? 0);
        $siteFeeMonths  = (int)($data['site_fee_months']   ?? 0);
        $total          = round($campFee + $siteFee + $otherAmount - $prepaidApplied, 2);
        $tenders        = is_array($data['tenders'] ?? null) ? $data['tenders'] : [];
        $tEft = 0.0; $tCash = 0.0; $tBank = 0.0;
        foreach ($tenders as $t) {
            $amt = (float)($t['amount'] ?? 0);
            switch (strtolower($t['method'] ?? '')) {
                case 'eftpos': $tEft  += $amt; break;
                case 'cash':   $tCash += $amt; break;
                case 'bank':   $tBank += $amt; break;
            }
        }
        $payDate   = !empty($data['payment_date'])  ? $data['payment_date']  : date('Y-m-d H:i:s');
        $arrDate   = !empty($data['arrival_date'])   ? $data['arrival_date']   : null;
        $depDate   = !empty($data['departure_date']) ? $data['departure_date'] : null;
        $headcount = isset($data['headcount']) && $data['headcount'] !== '' ? (int)$data['headcount'] : null;

        $stmt = $db->prepare("
            INSERT INTO payments
                (household_id, camp_id, payment_date, camp_fee, site_fee, prepaid_applied,
                 other_amount, total, headcount, notes, arrival_date, departure_date,
                 tender_eftpos, tender_cash, tender_bank)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $hid, $campId, $payDate, $campFee, $siteFee, $prepaidApplied,
            $otherAmount, $total, $headcount, trim($data['notes'] ?? ''),
            $arrDate, $depDate, $tEft, $tCash, $tBank,
        ]);
        $paymentId = (int)$db->lastInsertId();

        if ($tenders) {
            $ts = $db->prepare("INSERT INTO payment_tenders (payment_id, method, amount, reference) VALUES (?,?,?,?)");
            foreach ($tenders as $t) {
                $amt = (float)($t['amount'] ?? 0);
                if ($amt != 0) {
                    $ts->execute([$paymentId, strtolower($t['method'] ?? 'other'), $amt, trim($t['reference'] ?? '')]);
                }
            }
        }

        if ($prepaidApplied > 0 && !empty($data['prepayment_ids']) && is_array($data['prepayment_ids'])) {
            $remaining = $prepaidApplied;
            $preStmt   = $db->prepare("SELECT id, amount FROM prepayments WHERE id=? AND household_id=? AND amount>0 LIMIT 1");
            $upStmt    = $db->prepare("UPDATE prepayments SET amount=? WHERE id=?");
            $allocStmt = $db->prepare("INSERT INTO payment_prepayment_allocations (payment_id, prepayment_id, amount_applied) VALUES (?,?,?)");
            foreach ($data['prepayment_ids'] as $pid) {
                if ($remaining <= 0) break;
                $preStmt->execute([(int)$pid, $hid]);
                $pre = $preStmt->fetch();
                if (!$pre) continue;
                $apply  = min((float)$pre['amount'], $remaining);
                $newAmt = round((float)$pre['amount'] - $apply, 2);
                $upStmt->execute([$newAmt, (int)$pre['id']]);
                $allocStmt->execute([$paymentId, (int)$pre['id'], $apply]);
                $remaining = round($remaining - $apply, 2);
            }
        }

        // Extend site_fee_expires when a site fee is collected
        if ($siteFee > 0 && $siteFeeMonths > 0 && !($data['is_refund'] ?? false)) {
            $expStmt = $db->prepare("SELECT site_fee_expires FROM site_allocations WHERE household_id=? AND camp_id=? LIMIT 1");
            $expStmt->execute([$hid, $campId]);
            $row     = $expStmt->fetch(PDO::FETCH_ASSOC);
            $today   = date('Y-m-d');
            $current = $row ? $row['site_fee_expires'] : null;
            $base    = ($current && $current >= $today) ? $current : $today;
            $newExp  = date('Y-m-d', strtotime("+{$siteFeeMonths} months", strtotime($base)));
            $db->prepare("UPDATE site_allocations SET site_fee_expires=? WHERE household_id=? AND camp_id=?")
               ->execute([$newExp, $hid, $campId]);
        }

        $db->commit();
        echo json_encode(['success' => true, 'id' => $paymentId]);
    } catch (Exception $e) {
        $db->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}));

// ── site_allocations: perpetual (no camp_id) ─────────────────────────────
// Overrides earlier routes that joined on camp_id.

$router->get('/api/sites', $protected('access_operations', function () {
    $db = Database::connect();
    $search    = trim($_GET['search']     ?? '');
    $type      = trim($_GET['type']       ?? '');
    $filter    = trim($_GET['filter']     ?? 'all');
    $feeFilter = trim($_GET['fee_filter'] ?? 'all');
    $campId    = isset($_GET['camp_id']) ? (int)$_GET['camp_id'] : 0;

    if ($campId <= 0) {
        $active = $db->query("SELECT id FROM camps WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $campId = $active ? (int)$active['id'] : 0;
    }

    $whereConds  = [];
    $whereParams = [];

    if ($search !== '') {
        $whereConds[]  = '(s.site_number LIKE ? OR s.notes LIKE ?)';
        $whereParams[] = '%' . $search . '%';
        $whereParams[] = '%' . $search . '%';
    }
    if ($type !== '') {
        $whereConds[]  = 's.site_type = ?';
        $whereParams[] = $type;
    }
    if ($filter === 'allocated') $whereConds[] = 'sa.id IS NOT NULL';
    if ($filter === 'available') $whereConds[] = 'sa.id IS NULL';

    if ($feeFilter === 'current')    $whereConds[] = 'sa.site_fee_expires >= CURDATE()';
    if ($feeFilter === 'overdue')    $whereConds[] = 'sa.site_fee_expires < CURDATE() AND sa.site_fee_expires >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)';
    if ($feeFilter === 'overdue_6m') $whereConds[] = 'sa.site_fee_expires < DATE_SUB(CURDATE(), INTERVAL 6 MONTH)';
    if ($feeFilter === 'unknown')    $whereConds[] = 'sa.id IS NOT NULL AND sa.site_fee_expires IS NULL';

    $wClause = $whereConds ? 'WHERE ' . implode(' AND ', $whereConds) : '';

    $stmt = $db->prepare("
        SELECT s.*,
               sa.id AS allocation_id,
               sa.household_id,
               sa.site_fee_expires,
               h.name AS household_name,
               p.id AS payment_id,
               p.site_fee,
               CASE
                 WHEN p.arrival_date IS NOT NULL AND p.departure_date IS NOT NULL
                   THEN DATEDIFF(p.departure_date, p.arrival_date)
                 ELSE NULL
               END AS camp_nights
        FROM sites s
        LEFT JOIN site_allocations sa ON sa.site_id = s.id
        LEFT JOIN households h ON h.id = sa.household_id
        LEFT JOIN payments p
               ON p.household_id = sa.household_id AND p.camp_id = ?
              AND p.id = (SELECT MAX(p2.id) FROM payments p2
                          WHERE p2.household_id = sa.household_id AND p2.camp_id = ?)
        $wClause
        ORDER BY CAST(s.site_number AS UNSIGNED), s.site_number
    ");
    $stmt->execute(array_merge([$campId, $campId], $whereParams));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $today        = date('Y-m-d');
    $sixMonthsAgo = date('Y-m-d', strtotime('-6 months'));
    $allocated    = 0; $available = 0;
    $feeCurrent   = 0; $feeOverdue = 0; $feeOverdue6m = 0; $feeUnknown = 0;

    foreach ($rows as &$r) {
        $r['power']         = (bool)$r['power'];
        $r['allocation_id'] = $r['allocation_id'] ? (int)$r['allocation_id'] : null;
        $r['household_id']  = $r['household_id']  ? (int)$r['household_id']  : null;
        $r['payment_id']    = $r['payment_id']    ? (int)$r['payment_id']    : null;
        $r['site_fee']      = $r['site_fee'] !== null ? (float)$r['site_fee'] : null;
        $r['camp_nights']   = $r['camp_nights'] !== null ? (int)$r['camp_nights'] : null;

        if ($r['allocation_id']) {
            $allocated++;
            $exp = $r['site_fee_expires'];
            if (!$exp)                  { $r['fee_status'] = 'unknown'; $feeUnknown++; }
            elseif ($exp >= $today)     { $r['fee_status'] = 'current'; $feeCurrent++; }
            elseif ($exp >= $sixMonthsAgo) { $r['fee_status'] = 'overdue'; $feeOverdue++; }
            else                        { $r['fee_status'] = 'overdue_6m'; $feeOverdue6m++; }
        } else {
            $available++;
            $r['fee_status'] = null;
        }
    }
    unset($r);

    echo json_encode([
        'sites'   => $rows,
        'summary' => [
            'total' => count($rows), 'allocated' => $allocated, 'available' => $available,
            'fee_current' => $feeCurrent, 'fee_overdue' => $feeOverdue,
            'fee_overdue_6m' => $feeOverdue6m, 'fee_unknown' => $feeUnknown,
        ],
        'camp_id' => $campId,
    ]);
}));

$router->get('/api/site/detail', $protected('access_operations', function () {
    $db     = Database::connect();
    $siteId = isset($_GET['site_id']) ? (int)$_GET['site_id'] : 0;
    $campId = isset($_GET['camp_id']) ? (int)$_GET['camp_id'] : 0;
    if ($siteId <= 0) { http_response_code(400); echo json_encode(['error' => 'site_id required']); return; }

    $siteStmt = $db->prepare("SELECT * FROM sites WHERE id=?");
    $siteStmt->execute([$siteId]);
    $site = $siteStmt->fetch(PDO::FETCH_ASSOC);
    if (!$site) { http_response_code(404); echo json_encode(['error' => 'Not found']); return; }
    $site['power'] = (bool)$site['power'];

    $allocStmt = $db->prepare("SELECT sa.*, h.name AS household_name FROM site_allocations sa JOIN households h ON h.id=sa.household_id WHERE sa.site_id=? LIMIT 1");
    $allocStmt->execute([$siteId]);
    $alloc = $allocStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $members = [];
    if ($alloc) {
        $memStmt = $db->prepare("SELECT id, first_name, last_name, member_type, gender, mobile, email FROM members WHERE household_id=? ORDER BY FIELD(member_type,'adult','youth','child','infant'), first_name");
        $memStmt->execute([$alloc['household_id']]);
        $members = $memStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $payments = [];
    if ($alloc && $campId > 0) {
        $payStmt = $db->prepare("SELECT p.*, GROUP_CONCAT(pt.method, ':', pt.amount ORDER BY pt.id SEPARATOR '|') AS tenders_raw FROM payments p LEFT JOIN payment_tenders pt ON pt.payment_id=p.id WHERE p.household_id=? AND p.camp_id=? GROUP BY p.id ORDER BY p.payment_date DESC");
        $payStmt->execute([$alloc['household_id'], $campId]);
        foreach ($payStmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $tenders = [];
            if ($p['tenders_raw']) {
                foreach (explode('|', $p['tenders_raw']) as $t) {
                    [$method, $amount] = explode(':', $t, 2);
                    $tenders[] = ['method' => $method, 'amount' => (float)$amount];
                }
            }
            unset($p['tenders_raw']);
            $p['total'] = (float)$p['total']; $p['camp_fee'] = (float)$p['camp_fee']; $p['site_fee'] = (float)$p['site_fee'];
            $p['tenders'] = $tenders;
            $payments[] = $p;
        }
    }

    echo json_encode(['site' => $site, 'camp_id' => $campId, 'alloc' => $alloc, 'members' => $members, 'payments' => $payments]);
}));

$router->post('/api/site-allocations', $protected('access_operations', function () {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $db = Database::connect();
    $siteId      = (int)($data['site_id']      ?? 0);
    $householdId = (int)($data['household_id'] ?? 0);
    $notes       = trim($data['notes']         ?? '');
    // Conflict resolution when the household is already allocated to another site:
    //   ''         → block (409) — safety net; the UI resolves conflicts before sending
    //   'transfer' → move the household here (remove its other-site allocations first)
    //   'both'     → keep existing allocations and add this site too (households may own 2+ sites)
    $conflictMode = trim($data['conflict'] ?? '');
    if ($siteId <= 0 || $householdId <= 0) {
        http_response_code(400); echo json_encode(['message' => 'Site and household are required']); return;
    }

    $conflict = $db->prepare("SELECT id FROM site_allocations WHERE household_id=? AND site_id<>? LIMIT 1");
    $conflict->execute([$householdId, $siteId]);
    if ($conflict->fetchColumn()) {
        if ($conflictMode === 'transfer') {
            $db->prepare("DELETE FROM site_allocations WHERE household_id=? AND site_id<>?")
               ->execute([$householdId, $siteId]);
        } elseif ($conflictMode !== 'both') {
            http_response_code(409);
            echo json_encode(['message' => 'Household already allocated to another site', 'conflict' => true]);
            return;
        }
        // 'both' falls through: the household keeps its other sites and gains this one.
    }

    $existing = $db->prepare("SELECT id FROM site_allocations WHERE site_id=? LIMIT 1");
    $existing->execute([$siteId]);
    $id = $existing->fetchColumn();
    if ($id) {
        $db->prepare("UPDATE site_allocations SET household_id=?, notes=? WHERE id=?")->execute([$householdId, $notes, (int)$id]);
        echo json_encode(['success' => true, 'id' => (int)$id]); return;
    }

    $stmt = $db->prepare("INSERT INTO site_allocations (site_id, household_id, notes) VALUES (?,?,?)");
    $stmt->execute([$siteId, $householdId, $notes]);
    echo json_encode(['success' => true, 'id' => (int)$db->lastInsertId()]);
}));

$router->get('/api/household/payment-context', $protected('access_operations', function () {
    $hid    = isset($_GET['household_id']) ? (int)$_GET['household_id'] : 0;
    $campId = isset($_GET['camp_id'])      ? (int)$_GET['camp_id']      : 0;
    if (!$hid || !$campId) { http_response_code(400); echo json_encode(['error' => 'household_id and camp_id required']); return; }
    $db = Database::connect();

    $h = $db->prepare("SELECT id, name, notes FROM households WHERE id=? LIMIT 1");
    $h->execute([$hid]);
    $household = $h->fetch();
    if (!$household) { http_response_code(404); echo json_encode(['error' => 'Household not found']); return; }

    $sa = $db->prepare("
        SELECT s.id AS site_id, s.site_number, sa.site_fee_expires
        FROM site_allocations sa
        JOIN sites s ON sa.site_id = s.id
        WHERE sa.household_id = ?
        LIMIT 1
    ");
    $sa->execute([$hid]);
    $alloc = $sa->fetch() ?: null;

    $mem = $db->prepare("
        SELECT id, CONCAT(first_name,' ',last_name) AS name, member_type, gender
        FROM members WHERE household_id = ? ORDER BY first_name, last_name
    ");
    $mem->execute([$hid]);
    $members = $mem->fetchAll();

    $pre = $db->prepare("
        SELECT id, name, amount, method, reference
        FROM prepayments
        WHERE household_id = ? AND camp_id = ? AND amount > 0
        ORDER BY id ASC
    ");
    $pre->execute([$hid, $campId]);
    $prepayments = $pre->fetchAll();
    $prepaymentBalance = array_sum(array_column($prepayments, 'amount'));

    $pays = $db->prepare("
        SELECT id, payment_date, camp_fee, site_fee, prepaid_applied, other_amount, total,
               tender_eftpos, tender_cash, tender_bank, notes, headcount, arrival_date, departure_date
        FROM payments
        WHERE household_id = ? AND camp_id = ?
        ORDER BY payment_date DESC, id DESC
        LIMIT 10
    ");
    $pays->execute([$hid, $campId]);
    $payments = $pays->fetchAll();
    $totalPaid = array_sum(array_column($payments, 'total'));

    echo json_encode([
        'household'          => $household,
        'site_id'            => $alloc ? (int)$alloc['site_id']   : null,
        'site_number'        => $alloc ? $alloc['site_number']     : null,
        'site_fee_expires'   => $alloc ? $alloc['site_fee_expires']: null,
        'members'            => $members,
        'prepayments'        => $prepayments,
        'prepayment_balance' => (float)$prepaymentBalance,
        'payments'           => $payments,
        'total_paid'         => (float)$totalPaid,
    ]);
}));

$router->post('/api/payments', $protected('access_operations', function () {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $db   = Database::connect();
    $db->beginTransaction();
    try {
        $hid            = (int)($data['household_id']    ?? 0);
        $campId         = (int)($data['camp_id']         ?? 0);
        $campFee        = (float)($data['camp_fee']      ?? 0);
        $siteFee        = (float)($data['site_fee']      ?? 0);
        $otherAmount    = (float)($data['other_amount']  ?? 0);
        $prepaidApplied = (float)($data['prepaid_applied'] ?? 0);
        $siteFeeMonths  = (int)($data['site_fee_months']   ?? 0);
        $total          = round($campFee + $siteFee + $otherAmount - $prepaidApplied, 2);
        $tenders        = is_array($data['tenders'] ?? null) ? $data['tenders'] : [];
        $tEft = 0.0; $tCash = 0.0; $tBank = 0.0;
        foreach ($tenders as $t) {
            $amt = (float)($t['amount'] ?? 0);
            switch (strtolower($t['method'] ?? '')) {
                case 'eftpos': $tEft  += $amt; break;
                case 'cash':   $tCash += $amt; break;
                case 'bank':   $tBank += $amt; break;
            }
        }
        $payDate   = !empty($data['payment_date'])   ? $data['payment_date']   : date('Y-m-d H:i:s');
        $arrDate   = !empty($data['arrival_date'])    ? $data['arrival_date']   : null;
        $depDate   = !empty($data['departure_date'])  ? $data['departure_date'] : null;
        $headcount = isset($data['headcount']) && $data['headcount'] !== '' ? (int)$data['headcount'] : null;

        $stmt = $db->prepare("
            INSERT INTO payments
                (household_id, camp_id, payment_date, camp_fee, site_fee, prepaid_applied,
                 other_amount, total, headcount, notes, arrival_date, departure_date,
                 tender_eftpos, tender_cash, tender_bank)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $hid, $campId, $payDate, $campFee, $siteFee, $prepaidApplied,
            $otherAmount, $total, $headcount, trim($data['notes'] ?? ''),
            $arrDate, $depDate, $tEft, $tCash, $tBank,
        ]);
        $paymentId = (int)$db->lastInsertId();

        if ($tenders) {
            $ts = $db->prepare("INSERT INTO payment_tenders (payment_id, method, amount, reference) VALUES (?,?,?,?)");
            foreach ($tenders as $t) {
                $amt = (float)($t['amount'] ?? 0);
                if ($amt != 0) $ts->execute([$paymentId, strtolower($t['method'] ?? 'other'), $amt, trim($t['reference'] ?? '')]);
            }
        }

        if ($prepaidApplied > 0 && !empty($data['prepayment_ids']) && is_array($data['prepayment_ids'])) {
            $remaining = $prepaidApplied;
            $preStmt   = $db->prepare("SELECT id, amount FROM prepayments WHERE id=? AND household_id=? AND amount>0 LIMIT 1");
            $upStmt    = $db->prepare("UPDATE prepayments SET amount=? WHERE id=?");
            $allocStmt = $db->prepare("INSERT INTO payment_prepayment_allocations (payment_id, prepayment_id, amount_applied) VALUES (?,?,?)");
            foreach ($data['prepayment_ids'] as $pid) {
                if ($remaining <= 0) break;
                $preStmt->execute([(int)$pid, $hid]);
                $pre = $preStmt->fetch();
                if (!$pre) continue;
                $apply  = min((float)$pre['amount'], $remaining);
                $upStmt->execute([round((float)$pre['amount'] - $apply, 2), (int)$pre['id']]);
                $allocStmt->execute([$paymentId, (int)$pre['id'], $apply]);
                $remaining = round($remaining - $apply, 2);
            }
        }

        // Extend site_fee_expires (perpetual allocation — no camp_id)
        if ($siteFee > 0 && $siteFeeMonths > 0 && !($data['is_refund'] ?? false)) {
            $expStmt = $db->prepare("SELECT site_fee_expires FROM site_allocations WHERE household_id=? LIMIT 1");
            $expStmt->execute([$hid]);
            $row     = $expStmt->fetch(PDO::FETCH_ASSOC);
            $today   = date('Y-m-d');
            $current = $row ? $row['site_fee_expires'] : null;
            $base    = ($current && $current >= $today) ? $current : $today;
            $newExp  = date('Y-m-d', strtotime("+{$siteFeeMonths} months", strtotime($base)));
            $db->prepare("UPDATE site_allocations SET site_fee_expires=? WHERE household_id=?")
               ->execute([$newExp, $hid]);
        }

        $db->commit();
        echo json_encode(['success' => true, 'id' => $paymentId]);
    } catch (Exception $e) {
        $db->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}));

// ── GET /api/sites — with is_active filtering ──────────────────────────────────
$router->get('/api/sites', $protected('access_operations', function () {
    $db = Database::connect();
    $search    = trim($_GET['search']     ?? '');
    $type      = trim($_GET['type']       ?? '');
    $filter    = trim($_GET['filter']     ?? 'all');
    $feeFilter = trim($_GET['fee_filter'] ?? 'all');
    $campId    = isset($_GET['camp_id']) ? (int)$_GET['camp_id'] : 0;

    if ($campId <= 0) {
        $active = $db->query("SELECT id FROM camps WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $campId = $active ? (int)$active['id'] : 0;
    }

    $whereConds  = [];
    $whereParams = [];

    if ($filter === 'inactive') {
        $whereConds[] = 's.is_active = 0';
    } else {
        $whereConds[] = 's.is_active = 1';
        if ($filter === 'allocated') $whereConds[] = 'sa.id IS NOT NULL';
        if ($filter === 'available') $whereConds[] = 'sa.id IS NULL';
        if ($feeFilter === 'current')    $whereConds[] = 'sa.site_fee_expires >= CURDATE()';
        if ($feeFilter === 'overdue')    $whereConds[] = 'sa.site_fee_expires < CURDATE() AND sa.site_fee_expires >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)';
        if ($feeFilter === 'overdue_6m') $whereConds[] = 'sa.site_fee_expires < DATE_SUB(CURDATE(), INTERVAL 6 MONTH)';
        if ($feeFilter === 'unknown')    $whereConds[] = 'sa.id IS NOT NULL AND sa.site_fee_expires IS NULL';
    }

    if ($search !== '') {
        $whereConds[]  = '(s.site_number LIKE ? OR s.notes LIKE ?)';
        $whereParams[] = '%' . $search . '%';
        $whereParams[] = '%' . $search . '%';
    }
    if ($type !== '') {
        $whereConds[]  = 's.site_type = ?';
        $whereParams[] = $type;
    }

    $wClause = $whereConds ? 'WHERE ' . implode(' AND ', $whereConds) : '';

    $stmt = $db->prepare("
        SELECT s.*,
               sa.id AS allocation_id,
               sa.household_id,
               sa.site_fee_expires,
               h.name AS household_name,
               p.id AS payment_id,
               p.site_fee,
               CASE
                 WHEN p.arrival_date IS NOT NULL AND p.departure_date IS NOT NULL
                   THEN DATEDIFF(p.departure_date, p.arrival_date)
                 ELSE NULL
               END AS camp_nights
        FROM sites s
        LEFT JOIN site_allocations sa ON sa.site_id = s.id
        LEFT JOIN households h ON h.id = sa.household_id
        LEFT JOIN payments p
               ON p.household_id = sa.household_id AND p.camp_id = ?
              AND p.id = (SELECT MAX(p2.id) FROM payments p2
                          WHERE p2.household_id = sa.household_id AND p2.camp_id = ?)
        $wClause
        ORDER BY CAST(s.site_number AS UNSIGNED), s.site_number
    ");
    $stmt->execute(array_merge([$campId, $campId], $whereParams));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $today        = date('Y-m-d');
    $sixMonthsAgo = date('Y-m-d', strtotime('-6 months'));
    $allocated = 0; $available = 0; $inactive = 0;
    $feeCurrent = 0; $feeOverdue = 0; $feeOverdue6m = 0; $feeUnknown = 0;

    foreach ($rows as &$r) {
        $r['power']         = (bool)$r['power'];
        $r['is_active']     = (bool)$r['is_active'];
        $r['allocation_id'] = $r['allocation_id'] ? (int)$r['allocation_id'] : null;
        $r['household_id']  = $r['household_id']  ? (int)$r['household_id']  : null;
        $r['payment_id']    = $r['payment_id']    ? (int)$r['payment_id']    : null;
        $r['site_fee']      = $r['site_fee'] !== null ? (float)$r['site_fee'] : null;
        $r['camp_nights']   = $r['camp_nights'] !== null ? (int)$r['camp_nights'] : null;

        if (!$r['is_active']) {
            $inactive++;
            $r['fee_expiry_status'] = null;
        } elseif ($r['allocation_id']) {
            $allocated++;
            $exp = $r['site_fee_expires'];
            if (!$exp)                     { $r['fee_expiry_status'] = 'unknown';    $feeUnknown++; }
            elseif ($exp >= $today)        { $r['fee_expiry_status'] = 'current';    $feeCurrent++; }
            elseif ($exp >= $sixMonthsAgo) { $r['fee_expiry_status'] = 'overdue';    $feeOverdue++; }
            else                           { $r['fee_expiry_status'] = 'overdue_6m'; $feeOverdue6m++; }
        } else {
            $available++;
            $r['fee_expiry_status'] = null;
        }
    }
    unset($r);

    echo json_encode([
        'sites'   => $rows,
        'summary' => [
            'total' => count($rows), 'allocated' => $allocated,
            'available' => $available, 'inactive' => $inactive,
            'fee_current' => $feeCurrent, 'fee_overdue' => $feeOverdue,
            'fee_overdue_6m' => $feeOverdue6m, 'fee_unknown' => $feeUnknown,
        ],
        'camp_id' => $campId,
    ]);
}));

// ── POST /api/site/update — with is_active ──────────────────────────────────────
$router->post('/api/site/update', $protected('access_operations', function () use ($requireId) {
    $id   = $requireId(); if ($id === null) return;
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $db   = Database::connect();
    $db->prepare("UPDATE sites SET site_number=?, site_type=?, power=?, capacity=?, notes=?, is_active=? WHERE id=?")
       ->execute([
           trim($data['site_number'] ?? ''),
           trim($data['site_type']   ?? ''),
           !empty($data['power']) ? 1 : 0,
           max(1, (int)($data['capacity'] ?? 6)),
           trim($data['notes']       ?? ''),
           isset($data['is_active']) ? ($data['is_active'] ? 1 : 0) : 1,
           $id,
       ]);
    echo json_encode(['success' => true]);
}));

// ── GET /api/camp/summary — attendance & operational summary ─────────────────────────
$router->get('/api/camp/summary', $protected('access_operations', function () {
    $db  = Database::connect();
    $cid = (int)($_GET['camp_id'] ?? 0);

    if ($cid <= 0) {
        $camp = $db->query("SELECT * FROM camps WHERE status='active' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$camp) $camp = $db->query("SELECT * FROM camps ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    } else {
        $camp = $db->prepare("SELECT * FROM camps WHERE id=?")->execute([$cid]) ? null : null;
        $s = $db->prepare("SELECT * FROM camps WHERE id=?"); $s->execute([$cid]);
        $camp = $s->fetch(PDO::FETCH_ASSOC);
    }

    if (!$camp) { echo json_encode(['camp' => null]); return; }
    $cid = (int)$camp['id'];

    // Registrations (one row per payment, with site)
    $regStmt = $db->prepare("
        SELECT h.name AS household_name,
               s.site_number, s.site_type,
               p.headcount, p.arrival_date, p.departure_date, p.notes, p.payment_date
        FROM payments p
        LEFT JOIN households h ON h.id = p.household_id
        LEFT JOIN site_allocations sa ON sa.household_id = p.household_id
        LEFT JOIN sites s ON s.id = sa.site_id
        WHERE p.camp_id = ?
        ORDER BY (s.site_number+0) ASC, h.name ASC");
    $regStmt->execute([$cid]);
    $regs = $regStmt->fetchAll(PDO::FETCH_ASSOC);

    // Site-type breakdown
    $stStmt = $db->prepare("
        SELECT COALESCE(s.site_type,'No Site') AS site_type,
               COUNT(DISTINCT p.household_id) AS households,
               COALESCE(SUM(p.headcount),0)   AS headcount
        FROM payments p
        LEFT JOIN site_allocations sa ON sa.household_id = p.household_id
        LEFT JOIN sites s ON s.id = sa.site_id
        WHERE p.camp_id = ?
        GROUP BY s.site_type ORDER BY headcount DESC");
    $stStmt->execute([$cid]);
    $byType = $stStmt->fetchAll(PDO::FETCH_ASSOC);

    // Daily spread
    $byDay = [];
    if ($camp['start_date'] && $camp['end_date']) {
        $cur = new DateTime($camp['start_date']);
        $end = new DateTime($camp['end_date']);
        while ($cur <= $end) {
            $d = $cur->format('Y-m-d');
            $arrivals = $departures = $inCamp = 0;
            foreach ($regs as $r) {
                if (!$r['arrival_date'] || !$r['departure_date']) continue;
                if ($r['arrival_date'] === $d)   $arrivals++;
                if ($r['departure_date'] === $d)  $departures++;
                if ($r['arrival_date'] <= $d && $r['departure_date'] >= $d) $inCamp += (int)($r['headcount'] ?? 0);
            }
            $byDay[] = ['date' => $d, 'arrivals' => $arrivals, 'departures' => $departures, 'in_camp' => $inCamp];
            $cur->modify('+1 day');
        }
    }

    $totalHeadcount = (int)array_sum(array_column($regs, 'headcount'));
    $sitesUsed = count(array_filter(array_unique(array_column($regs, 'site_number')), fn($v) => $v !== null && $v !== ''));

    echo json_encode([
        'camp'  => $camp,
        'stats' => [
            'households' => count($regs),
            'headcount'  => $totalHeadcount,
            'sites_used' => $sitesUsed,
            'days'       => ($camp['start_date'] && $camp['end_date'])
                ? (int)(new DateTime($camp['start_date']))->diff(new DateTime($camp['end_date']))->days + 1
                : null,
        ],
        'by_site_type'  => array_map(fn($r) => [
            'site_type'  => $r['site_type'],
            'households' => (int)$r['households'],
            'headcount'  => (int)$r['headcount'],
        ], $byType),
        'registrations' => array_map(fn($r) => [
            'household_name' => $r['household_name'],
            'site_number'    => $r['site_number'],
            'site_type'      => $r['site_type'],
            'headcount'      => (int)($r['headcount'] ?? 0),
            'arrival_date'   => $r['arrival_date'],
            'departure_date' => $r['departure_date'],
            'notes'          => $r['notes'],
        ], $regs),
        'by_day' => $byDay,
    ]);
}));

// ── Feature Requests ─────────────────────────────────────────────────────────────────

$router->get('/api/feature-requests', $protected('access_operations', function () {
    $db = Database::connect();
    $db->exec("CREATE TABLE IF NOT EXISTS feature_requests (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        type         ENUM('feature','bug') NOT NULL DEFAULT 'feature',
        title        VARCHAR(500)  NOT NULL DEFAULT '',
        description  TEXT          NOT NULL DEFAULT '',
        submitter    VARCHAR(100)  NOT NULL DEFAULT '',
        status       ENUM('pending','completed') NOT NULL DEFAULT 'pending',
        sort_order   INT           NOT NULL DEFAULT 0,
        created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME      NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("ALTER TABLE feature_requests ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0");
    $db->exec("UPDATE feature_requests SET sort_order = id WHERE sort_order = 0");
    $pending   = $db->query("SELECT * FROM feature_requests WHERE status='pending'   ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
    $completed = $db->query("SELECT * FROM feature_requests WHERE status='completed' ORDER BY completed_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['pending' => $pending, 'completed' => $completed]);
}));

$router->post('/api/feature-requests', $protected('access_operations', function () {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $title = trim($data['title'] ?? '');
    if ($title === '') { http_response_code(400); echo json_encode(['message' => 'Title required']); return; }
    $db = Database::connect();
    $submitter = $_SESSION['username'] ?? 'unknown';
    $db->prepare("INSERT INTO feature_requests (type, title, description, submitter) VALUES (?,?,?,?)")
       ->execute([$data['type'] ?? 'feature', $title, trim($data['description'] ?? ''), $submitter]);
    $newId = (int)$db->lastInsertId();
    $db->prepare("UPDATE feature_requests SET sort_order = ? WHERE id = ?")->execute([$newId, $newId]);
    echo json_encode(['success' => true, 'id' => $newId]);
}));

$router->post('/api/feature-request/complete', $protected('access_operations', function () use ($requireId) {
    $id = $requireId(); if ($id === null) return;
    Database::connect()->prepare("UPDATE feature_requests SET status='completed', completed_at=NOW() WHERE id=?")
        ->execute([$id]);
    echo json_encode(['success' => true]);
}));

$router->post('/api/feature-request/delete', $protected('access_operations', function () use ($requireId) {
    $id = $requireId(); if ($id === null) return;
    Database::connect()->prepare("DELETE FROM feature_requests WHERE id=?")->execute([$id]);
    echo json_encode(['success' => true]);
}));

$router->post('/api/feature-request/update', $protected('access_operations', function () use ($requireId) {
    $id = $requireId(); if ($id === null) return;
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $title = trim($data['title'] ?? '');
    if ($title === '') { http_response_code(400); echo json_encode(['message' => 'Title required']); return; }
    $type = in_array($data['type'] ?? '', ['feature', 'bug']) ? $data['type'] : 'feature';
    Database::connect()->prepare("UPDATE feature_requests SET type=?, title=?, description=? WHERE id=? AND status='pending'")
        ->execute([$type, $title, trim($data['description'] ?? ''), $id]);
    echo json_encode(['success' => true]);
}));

$router->post('/api/feature-request/reorder', $protected('access_operations', function () {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $ids  = array_values(array_filter(array_map('intval', $data['ids'] ?? []), fn($id) => $id > 0));
    if (empty($ids)) { echo json_encode(['success' => true]); return; }
    $db   = Database::connect();
    $stmt = $db->prepare("UPDATE feature_requests SET sort_order=? WHERE id=?");
    foreach ($ids as $order => $id) {
        $stmt->execute([$order, $id]);
    }
    echo json_encode(['success' => true]);
}));

// ── AI Chat streaming (for code.php) ────────────────────────────────────────────────
$router->post('/api/ai/chat', function () {
    // Allow from code.php same-origin; check session manually
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['user_id'])) {
        http_response_code(401); echo json_encode(['message' => 'Unauthorized']); return;
    }

    $apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
    if ($apiKey === '') {
        http_response_code(503);
        echo json_encode(['message' => 'Anthropic API key not configured. Add ANTHROPIC_API_KEY to config.php.']);
        return;
    }

    $data    = json_decode(file_get_contents('php://input'), true) ?: [];
    $model   = defined('ANTHROPIC_MODEL') ? ANTHROPIC_MODEL : 'claude-sonnet-4-6';
    $system  = $data['system']   ?? '';
    $history = $data['messages'] ?? [];

    if (empty($history)) {
        http_response_code(400); echo json_encode(['message' => 'No messages']); return;
    }

    $payload = json_encode([
        'model'      => $model,
        'max_tokens' => 8192,
        'stream'     => true,
        'system'     => $system,
        'messages'   => $history,
    ]);

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_WRITEFUNCTION  => function ($curl, $chunk) {
            echo $chunk;
            if (ob_get_level()) ob_flush();
            flush();
            return strlen($chunk);
        },
    ]);
    curl_exec($ch);
    if (curl_errno($ch)) {
        $err = curl_error($ch);
        echo "data: " . json_encode(['type' => 'error', 'error' => ['message' => $err]]) . "\n\n";
    }
    curl_close($ch);
});

// ── GET /api/prepayments/review — unmatched prepayments + fuzzy household suggestions ──
$router->get('/api/prepayments/review', $protected('access_operations', function () {
    $campId = (int)($_GET['camp_id'] ?? 0);
    if (!$campId) { echo json_encode(['items' => []]); return; }

    $db = Database::connect();

    $stmt = $db->prepare("SELECT id, name, amount, method, reference, paid_at, notes, source
        FROM prepayments WHERE camp_id=? AND household_id IS NULL ORDER BY paid_at, name");
    $stmt->execute([$campId]);
    $unmatched = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($unmatched)) { echo json_encode(['items' => []]); return; }

    $hhStmt = $db->query("SELECT id, name FROM households ORDER BY name");
    $households = $hhStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($unmatched as &$p) {
        $pName = strtolower(trim($p['name']));
        $pWords = array_filter(explode(' ', $pName), fn($w) => strlen($w) > 2);
        $scored = [];
        foreach ($households as $h) {
            $hName = strtolower(trim($h['name']));
            similar_text($pName, $hName, $pct);
            $boost = 0;
            foreach ($pWords as $word) {
                if (str_contains($hName, $word)) $boost += 20;
            }
            $score = $pct + $boost;
            if ($score >= 35) {
                $scored[] = ['id' => (int)$h['id'], 'name' => $h['name'], 'score' => (int)round($score)];
            }
        }
        usort($scored, fn($a, $b) => $b['score'] - $a['score']);
        $p['suggestions'] = array_slice($scored, 0, 5);
        $p['amount'] = (float)$p['amount'];
    }

    echo json_encode(['items' => $unmatched]);
}));

// ── POST /api/prepayment/match — quick-match: set household_id only ───────────────────
$router->post('/api/prepayment/match', $protected('access_operations', function () use ($requireId) {
    $id = $requireId(); if ($id === null) return;
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $householdId = !empty($data['household_id']) ? (int)$data['household_id'] : null;
    Database::connect()->prepare("UPDATE prepayments SET household_id=? WHERE id=?")
        ->execute([$householdId, $id]);
    echo json_encode(['success' => true]);
}));

// ── Public waitlist submission (CORS-enabled for campo.urbantek.online) ──────
$router->options('/api/public/waitlist', function () {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = ['https://campo.urbantek.online', 'http://campo.urbantek.online', 'https://campo.nix.local', 'http://campo.nix.local'];
    if (in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Vary: Origin');
    }
    http_response_code(204);
    echo '';
});
$router->post('/api/public/waitlist', function () {
    (new SiteController())->storePublicWaitlist();
});

// ── User activation & password reset (public, no auth required) ───────────────

$router->get('/api/user/token-check', function () use ($jsonError) {
    $token = trim($_GET['token'] ?? '');
    if ($token === '') { $jsonError(400, 'Missing token.'); return; }

    $db   = Database::connect();
    $stmt = $db->prepare("SELECT ut.type, u.username FROM user_tokens ut JOIN users u ON u.id = ut.user_id WHERE ut.token = ? AND ut.used_at IS NULL AND ut.expires_at > NOW() LIMIT 1");
    $stmt->execute([$token]);
    $row  = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) { $jsonError(404, 'This link has expired or is invalid. Please ask an admin to resend the email.'); return; }

    echo json_encode(['valid' => true, 'type' => $row['type'], 'username' => $row['username']]);
});

$router->post('/api/user/activate', function () use ($jsonError) {
    $data     = json_decode(file_get_contents('php://input'), true) ?: [];
    $token    = trim((string)($data['token'] ?? ''));
    $password = (string)($data['password'] ?? '');

    if ($token === '')           { $jsonError(400, 'Missing token.'); return; }
    if (strlen($password) < 8)  { $jsonError(422, 'Password must be at least 8 characters.'); return; }

    $db   = Database::connect();
    $stmt = $db->prepare("SELECT * FROM user_tokens WHERE token = ? AND type = 'activation' AND used_at IS NULL AND expires_at > NOW() LIMIT 1");
    $stmt->execute([$token]);
    $row  = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) { $jsonError(404, 'This activation link has expired or is invalid.'); return; }

    $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
       ->execute([password_hash($password, PASSWORD_DEFAULT), (int)$row['user_id']]);
    $db->prepare("UPDATE user_tokens SET used_at = NOW() WHERE id = ?")
       ->execute([$row['id']]);

    echo json_encode(['success' => true]);
});

$router->post('/api/user/password-reset/request', function () use ($jsonError) {
    $data     = json_decode(file_get_contents('php://input'), true) ?: [];
    $username = trim((string)($data['username'] ?? ''));

    if ($username === '') { $jsonError(422, 'Please enter your username.'); return; }

    $db   = Database::connect();
    $stmt = $db->prepare("SELECT id, username, email FROM users WHERE LOWER(username) = LOWER(?) AND password_hash IS NOT NULL LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Always return success to prevent enumeration
    echo json_encode(['success' => true]);

    if (!$user || empty($user['email'])) return;
    $email = $user['email'];

    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + 3600);
    $db->prepare("DELETE FROM user_tokens WHERE user_id = ? AND type = 'password_reset' AND used_at IS NULL")
       ->execute([$user['id']]);
    $db->prepare("INSERT INTO user_tokens (user_id, token, type, expires_at) VALUES (?, ?, 'password_reset', ?)")
       ->execute([$user['id'], $token, $expires]);

    $config = CampoMailer::configFromDb($db, $_SERVER);
    if ($config['host'] === '' || $config['from_email'] === '') return;

    $resetUrl = $config['app_base_url'] . '/reset-password?token=' . $token;
    $body = "Hello {$user['username']},\n\nA password reset was requested for your Campo account.\n\nClick the link below to set a new password:\n\n$resetUrl\n\nThis link is valid for 1 hour. If you did not request a reset, you can safely ignore this email.";

    try {
        CampoMailer::sendTextWithConfig($email, 'Reset your Campo password', $body, $config);
    } catch (Throwable $e) {
        // Swallow — success was already returned to prevent enumeration
    }
});

$router->post('/api/user/password-reset/complete', function () use ($jsonError) {
    $data     = json_decode(file_get_contents('php://input'), true) ?: [];
    $token    = trim((string)($data['token'] ?? ''));
    $password = (string)($data['password'] ?? '');

    if ($token === '')          { $jsonError(400, 'Missing token.'); return; }
    if (strlen($password) < 8) { $jsonError(422, 'Password must be at least 8 characters.'); return; }

    $db   = Database::connect();
    $stmt = $db->prepare("SELECT * FROM user_tokens WHERE token = ? AND type = 'password_reset' AND used_at IS NULL AND expires_at > NOW() LIMIT 1");
    $stmt->execute([$token]);
    $row  = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) { $jsonError(404, 'This reset link has expired or is invalid.'); return; }

    $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
       ->execute([password_hash($password, PASSWORD_DEFAULT), (int)$row['user_id']]);
    $db->prepare("UPDATE user_tokens SET used_at = NOW() WHERE id = ?")
       ->execute([$row['id']]);

    echo json_encode(['success' => true]);
});

// ── Resend activation (protected) ─────────────────────────────────────────────

$router->post('/api/user/resend-activation', $protected('manage_users', function () use ($requireId, $jsonError) {
    $id = $requireId();
    if ($id === null) return;

    $db   = Database::connect();
    $stmt = $db->prepare("SELECT id, username, email, password_hash FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user)                          { $jsonError(404, 'User not found.'); return; }
    if ($user['password_hash'] !== null) { $jsonError(422, 'This user already has an active account.'); return; }
    if ($user['email'] === '')           { $jsonError(422, 'This user has no email address.'); return; }

    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + 72 * 3600);
    $db->prepare("DELETE FROM user_tokens WHERE user_id = ? AND type = 'activation' AND used_at IS NULL")
       ->execute([$user['id']]);
    $db->prepare("INSERT INTO user_tokens (user_id, token, type, expires_at) VALUES (?, ?, 'activation', ?)")
       ->execute([$user['id'], $token, $expires]);

    $config = CampoMailer::configFromDb($db, $_SERVER);
    if ($config['host'] === '' || $config['from_email'] === '') {
        $jsonError(503, 'Mail is not configured. Set up SMTP in Settings before sending activation emails.');
        return;
    }

    $activationUrl = $config['app_base_url'] . '/activate?token=' . $token;
    $body = "Hello {$user['username']},\n\nYour Campo admin account is waiting for activation.\n\nClick the link below to set your password:\n\n$activationUrl\n\nThis link is valid for 72 hours.";

    try {
        CampoMailer::sendTextWithConfig($user['email'], 'Activate your Campo account', $body, $config);
        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        $jsonError(500, 'Failed to send email: ' . $e->getMessage());
    }
}));

// ── Mail settings ──────────────────────────────────────────────────────────────

$router->get('/api/settings/mail', $protected('manage_users', function () {
    $db     = Database::connect();
    $config = CampoMailer::configFromDb($db, $_SERVER);
    $status = CampoMailer::statusFromConfig($config);

    echo json_encode([
        'host'         => $config['host'],
        'port'         => $config['port'],
        'encryption'   => $config['encryption'],
        'username'     => $config['username'],
        'has_password' => $config['password'] !== '',
        'from_name'    => $config['from_name'],
        'from_email'   => $config['from_email'],
        'app_base_url' => $config['app_base_url'],
        'configured'   => $status['configured'],
        'issues'       => $status['issues'],
    ]);
}));

$router->post('/api/settings/mail', $protected('manage_users', function () use ($jsonError) {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $db   = Database::connect();
    $db->exec("CREATE TABLE IF NOT EXISTS app_settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT)");
    $stmt = $db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?");

    $fields = ['mail_host','mail_port','mail_encryption','mail_username','mail_from_name','mail_from_email','app_base_url'];
    foreach ($fields as $f) {
        if (array_key_exists($f, $data)) {
            $v = trim((string)$data[$f]);
            $stmt->execute([$f, $v, $v]);
        }
    }
    // Only save password if a real value was submitted (not the placeholder)
    if (isset($data['mail_password']) && (string)$data['mail_password'] !== '') {
        $v = (string)$data['mail_password'];
        $stmt->execute(['mail_password', $v, $v]);
    }

    echo json_encode(['success' => true]);
}));

$router->post('/api/settings/mail/test', $protected('manage_users', function () use ($jsonError) {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $to   = trim((string)($data['to'] ?? ''));

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { $jsonError(422, 'Please enter a valid recipient email address.'); return; }

    $db     = Database::connect();
    $config = CampoMailer::configFromDb($db, $_SERVER);

    try {
        CampoMailer::sendTextWithConfig($to, 'Campo mail test', "This is a test email from Campo.\n\nIf you received this, your mail settings are working correctly.", $config);
        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        $jsonError(500, 'Send failed: ' . $e->getMessage());
    }
}));


// ── GET /api/household/payment-history — all payments across all camps ────────
$router->get('/api/household/payment-history', $protected('access_operations', function () use ($requireId) {
    $householdId = isset($_GET['household_id']) ? (int)$_GET['household_id'] : 0;
    if ($householdId <= 0) { http_response_code(400); echo json_encode(['message' => 'household_id required']); return; }

    $db = Database::connect();

    $payments = $db->prepare("
        SELECT p.id, p.payment_date, p.camp_fee, p.site_fee, p.other_amount,
               p.prepaid_applied, p.total, p.headcount, p.notes,
               p.tender_eftpos, p.tender_cash, p.tender_bank,
               p.arrival_date, p.departure_date,
               c.name AS camp_name, c.year AS camp_year
        FROM payments p
        JOIN camps c ON c.id = p.camp_id
        WHERE p.household_id = ?
        ORDER BY c.start_date DESC, p.payment_date DESC
    ");
    $payments->execute([$householdId]);
    $rows = $payments->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['camp_fee']       = (float)$r['camp_fee'];
        $r['site_fee']       = (float)$r['site_fee'];
        $r['other_amount']   = (float)$r['other_amount'];
        $r['prepaid_applied']= (float)$r['prepaid_applied'];
        $r['total']          = (float)$r['total'];
        $r['tender_eftpos']  = (float)$r['tender_eftpos'];
        $r['tender_cash']    = (float)$r['tender_cash'];
        $r['tender_bank']    = (float)$r['tender_bank'];
        $r['headcount']      = $r['headcount'] !== null ? (int)$r['headcount'] : null;
    }
    unset($r);

    echo json_encode(['payments' => $rows, 'household_id' => $householdId]);
}));

// ── GET /api/site/detail — overrides earlier version; payments span all camps ─
$router->get('/api/site/detail', $protected('access_operations', function () {
    $db     = Database::connect();
    $siteId = isset($_GET['site_id']) ? (int)$_GET['site_id'] : 0;
    if ($siteId <= 0) { http_response_code(400); echo json_encode(['error' => 'site_id required']); return; }

    $site = $db->prepare("SELECT * FROM sites WHERE id=?");
    $site->execute([$siteId]);
    $siteRow = $site->fetch(PDO::FETCH_ASSOC);
    if (!$siteRow) { http_response_code(404); echo json_encode(['error' => 'Not found']); return; }
    $siteRow['power'] = (bool)$siteRow['power'];

    $allocStmt = $db->prepare("
        SELECT sa.*, h.name AS household_name
        FROM site_allocations sa
        JOIN households h ON h.id = sa.household_id
        WHERE sa.site_id = ?
        LIMIT 1
    ");
    $allocStmt->execute([$siteId]);
    $alloc = $allocStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $members = [];
    if ($alloc) {
        $memStmt = $db->prepare("
            SELECT id, first_name, last_name, member_type, gender, mobile, email
            FROM members WHERE household_id = ?
            ORDER BY FIELD(member_type,'adult','youth','child','infant'), first_name
        ");
        $memStmt->execute([$alloc['household_id']]);
        $members = $memStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $payments = [];
    if ($alloc) {
        $payStmt = $db->prepare("
            SELECT p.id, p.payment_date, p.camp_fee, p.site_fee, p.other_amount,
                   p.prepaid_applied, p.total, p.headcount, p.notes,
                   p.tender_eftpos, p.tender_cash, p.tender_bank,
                   p.arrival_date, p.departure_date,
                   c.id AS camp_id, c.name AS camp_name
            FROM payments p
            JOIN camps c ON c.id = p.camp_id
            WHERE p.household_id = ?
            ORDER BY c.start_date DESC, p.payment_date DESC
        ");
        $payStmt->execute([$alloc['household_id']]);
        foreach ($payStmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $p['camp_fee']        = (float)$p['camp_fee'];
            $p['site_fee']        = (float)$p['site_fee'];
            $p['other_amount']    = (float)$p['other_amount'];
            $p['prepaid_applied'] = (float)$p['prepaid_applied'];
            $p['total']           = (float)$p['total'];
            $p['tender_eftpos']   = (float)$p['tender_eftpos'];
            $p['tender_cash']     = (float)$p['tender_cash'];
            $p['tender_bank']     = (float)$p['tender_bank'];
            $p['headcount']       = $p['headcount'] !== null ? (int)$p['headcount'] : null;
            $payments[] = $p;
        }
    }

    echo json_encode(['site' => $siteRow, 'alloc' => $alloc, 'members' => $members, 'payments' => $payments]);
}));

// ── GET /api/sites — v3: camp_id=0 means all camps; allocations are perpetual ─
$router->get('/api/sites', $protected('access_operations', function () {
    $db = Database::connect();
    $search    = trim($_GET['search']     ?? '');
    $type      = trim($_GET['type']       ?? '');
    $filter    = trim($_GET['filter']     ?? 'all');
    $feeFilter = trim($_GET['fee_filter'] ?? 'all');
    $campId    = isset($_GET['camp_id']) ? (int)$_GET['camp_id'] : 0;

    $whereConds  = [];
    $whereParams = [];

    if ($search !== '') {
        $like = '%' . $search . '%';
        $whereConds[]  = '(s.site_number LIKE ? OR s.notes LIKE ? OR EXISTS (SELECT 1 FROM members m WHERE m.household_id = sa.household_id AND (m.first_name LIKE ? OR m.last_name LIKE ?)))';
        $whereParams[] = $like;
        $whereParams[] = $like;
        $whereParams[] = $like;
        $whereParams[] = $like;
    }
    if ($type !== '') {
        $whereConds[]  = 's.site_type = ?';
        $whereParams[] = $type;
    }
    if ($filter === 'allocated') $whereConds[] = 'sa.id IS NOT NULL';
    if ($filter === 'available') $whereConds[] = 'sa.id IS NULL';

    if ($feeFilter === 'current')    $whereConds[] = 'sa.site_fee_expires >= CURDATE()';
    if ($feeFilter === 'overdue')    $whereConds[] = 'sa.site_fee_expires < CURDATE() AND sa.site_fee_expires >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)';
    if ($feeFilter === 'overdue_6m') $whereConds[] = 'sa.site_fee_expires < DATE_SUB(CURDATE(), INTERVAL 6 MONTH)';
    if ($feeFilter === 'unknown')    $whereConds[] = 'sa.id IS NOT NULL AND sa.site_fee_expires IS NULL';

    $wClause = $whereConds ? 'WHERE ' . implode(' AND ', $whereConds) : '';

    if ($campId > 0) {
        // Specific camp — show payment data for that camp
        $sql = "
            SELECT s.*,
                   sa.id AS allocation_id, sa.household_id, sa.site_fee_expires,
                   h.name AS household_name,
                   p.id AS payment_id, p.site_fee,
                   CASE WHEN p.arrival_date IS NOT NULL AND p.departure_date IS NOT NULL
                        THEN DATEDIFF(p.departure_date, p.arrival_date) ELSE NULL END AS camp_nights
            FROM sites s
            LEFT JOIN site_allocations sa ON sa.site_id = s.id AND sa.camp_id = ?
            LEFT JOIN households h ON h.id = sa.household_id
            LEFT JOIN payments p ON p.household_id = sa.household_id AND p.camp_id = ?
                   AND p.id = (SELECT MAX(p2.id) FROM payments p2
                               WHERE p2.household_id = sa.household_id AND p2.camp_id = ?)
            $wClause
            ORDER BY CAST(s.site_number AS UNSIGNED), s.site_number
        ";
        $params = array_merge([$campId, $campId, $campId], $whereParams);
    } else {
        // All camps — allocations are perpetual; show most recent payment overall
        $sql = "
            SELECT s.*,
                   sa.id AS allocation_id, sa.household_id, sa.site_fee_expires,
                   h.name AS household_name,
                   p.id AS payment_id, p.site_fee,
                   CASE WHEN p.arrival_date IS NOT NULL AND p.departure_date IS NOT NULL
                        THEN DATEDIFF(p.departure_date, p.arrival_date) ELSE NULL END AS camp_nights
            FROM sites s
            LEFT JOIN site_allocations sa ON sa.site_id = s.id
            LEFT JOIN households h ON h.id = sa.household_id
            LEFT JOIN payments p ON p.household_id = sa.household_id
                   AND p.id = (SELECT MAX(p2.id) FROM payments p2
                               WHERE p2.household_id = sa.household_id)
            $wClause
            ORDER BY CAST(s.site_number AS UNSIGNED), s.site_number
        ";
        $params = $whereParams;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $today        = date('Y-m-d');
    $sixMonthsAgo = date('Y-m-d', strtotime('-6 months'));
    $allocated = 0; $available = 0;
    $feeCurrent = 0; $feeOverdue = 0; $feeOverdue6m = 0; $feeUnknown = 0;

    foreach ($rows as &$r) {
        $r['power']         = (bool)$r['power'];
        $r['allocation_id'] = $r['allocation_id'] ? (int)$r['allocation_id'] : null;
        $r['household_id']  = $r['household_id']  ? (int)$r['household_id']  : null;
        $r['payment_id']    = $r['payment_id']    ? (int)$r['payment_id']    : null;
        $r['site_fee']      = $r['site_fee']      !== null ? (float)$r['site_fee'] : null;
        $r['camp_nights']   = $r['camp_nights']   !== null ? (int)$r['camp_nights'] : null;

        if ($r['allocation_id']) {
            $allocated++;
            $exp = $r['site_fee_expires'];
            if ($exp === null) {
                $r['fee_expiry_status'] = 'unknown'; $feeUnknown++;
            } elseif ($exp >= $today) {
                $r['fee_expiry_status'] = 'current'; $feeCurrent++;
            } elseif ($exp >= $sixMonthsAgo) {
                $r['fee_expiry_status'] = 'overdue'; $feeOverdue++;
            } else {
                $r['fee_expiry_status'] = 'overdue_6m'; $feeOverdue6m++;
            }
        } else {
            $available++;
            $r['fee_expiry_status'] = null;
        }
    }
    unset($r);

    echo json_encode([
        'sites'   => $rows,
        'summary' => [
            'allocated'    => $allocated,   'available'    => $available,
            'fee_current'  => $feeCurrent,  'fee_overdue'  => $feeOverdue,
            'fee_overdue_6m' => $feeOverdue6m, 'fee_unknown' => $feeUnknown,
        ],
    ]);
}));

// ── POST /api/camp/map-center — saves lat, lng, AND zoom ─────────────────────
$router->post('/api/camp/map-center', $protected('manage_system', function () use ($requireId) {
    $id   = $requireId(); if ($id === null) return;
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $lat  = isset($data['map_center_lat']) && $data['map_center_lat'] !== '' ? (float)$data['map_center_lat'] : null;
    $lng  = isset($data['map_center_lng']) && $data['map_center_lng'] !== '' ? (float)$data['map_center_lng'] : null;
    $zoom = isset($data['map_zoom'])       && $data['map_zoom']       !== '' ? (int)$data['map_zoom']         : null;
    Database::connect()->prepare("UPDATE camps SET map_center_lat=?, map_center_lng=?, map_zoom=? WHERE id=?")
        ->execute([$lat, $lng, $zoom, $id]);
    echo json_encode(['success' => true]);
}));

// ═══════════════════════════════════════════════════════════════════════════════
// SQUARE INTEGRATION
// ═══════════════════════════════════════════════════════════════════════════════

function getSquareToken(): string {
    try {
        $db  = Database::connect();
        $val = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'square_access_token'");
        $val->execute();
        $row = $val->fetchColumn();
        if ($row) return $row;
    } catch (\Exception $e) {}
    return defined('SQUARE_ACCESS_TOKEN') ? SQUARE_ACCESS_TOKEN : '';
}

function squareApi(string $method, string $path, array $data = []): array {
    $token = getSquareToken();
    if (!$token) return ['__error' => 'not_configured'];

    $url     = 'https://connect.squareup.com/v2' . $path;
    $headers = [
        "Authorization: Bearer $token",
        "Content-Type: application/json",
        "Square-Version: 2024-11-20",
    ];
    $ch = curl_init();

    if ($method === 'GET') {
        if ($data) $url .= '?' . http_build_query($data);
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    } else {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data ?: new stdClass()));
    }

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($body ?: '', true) ?? [];
    if ($code >= 400) return ['__error' => $result['errors'][0]['detail'] ?? "Square error ($code)"];
    return $result;
}

// ── GET /api/square/config — check token status ───────────────────────────────
$router->get('/api/square/config', $protected('manage_system', function () {
    $token = getSquareToken();
    if (!$token) {
        echo json_encode(['configured' => false]);
        return;
    }
    $len    = strlen($token);
    $masked = substr($token, 0, 5) . str_repeat('•', max(0, $len - 9)) . substr($token, -4);
    echo json_encode(['configured' => true, 'masked' => $masked]);
}));

// ── POST /api/square/config — save access token to app_settings ───────────────
$router->post('/api/square/config', $protected('manage_system', function () {
    $body  = json_decode(file_get_contents('php://input'), true) ?: [];
    $token = trim($body['token'] ?? '');
    if (!$token) {
        http_response_code(400); echo json_encode(['error' => 'Token is required']); return;
    }
    $db = Database::connect();
    $db->exec("CREATE TABLE IF NOT EXISTS app_settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT)");
    $db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('square_access_token',?) ON DUPLICATE KEY UPDATE setting_value=?")
       ->execute([$token, $token]);
    echo json_encode(['success' => true]);
}));

// ── POST /api/square/config/clear — remove access token ──────────────────────
$router->post('/api/square/config/clear', $protected('manage_system', function () {
    Database::connect()->prepare("DELETE FROM app_settings WHERE setting_key = 'square_access_token'")->execute();
    echo json_encode(['success' => true]);
}));

// ── GET /api/square/customers — list all Square customers merged with link status ──
$router->get('/api/square/customers', $protected('manage_system', function () {
    $token = getSquareToken();
    if (!$token) {
        echo json_encode(['configured' => false, 'customers' => []]);
        return;
    }

    $customersRes = squareApi('GET', '/customers', ['limit' => 100, 'sort_field' => 'FAMILY_NAME', 'sort_order' => 'ASC']);
    if (isset($customersRes['__error'])) {
        http_response_code(500);
        echo json_encode(['error' => $customersRes['__error']]);
        return;
    }

    // Fetch all subscriptions in one call
    $subsRes = squareApi('POST', '/subscriptions/search', ['limit' => 100]);
    $subsByCustomer = [];
    foreach (($subsRes['subscriptions'] ?? []) as $sub) {
        if (!isset($subsByCustomer[$sub['customer_id']])) {
            $subsByCustomer[$sub['customer_id']] = $sub;
        }
    }

    $db   = Database::connect();
    $rows = $db->query("SELECT id, name, square_customer_id FROM households WHERE square_customer_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
    $linkedBySquare = [];
    foreach ($rows as $r) {
        $linkedBySquare[$r['square_customer_id']] = ['id' => (int)$r['id'], 'name' => $r['name']];
    }

    $out = [];
    foreach (($customersRes['customers'] ?? []) as $c) {
        $name = trim(($c['given_name'] ?? '') . ' ' . ($c['family_name'] ?? ''));
        $sub  = $subsByCustomer[$c['id']] ?? null;
        $out[] = [
            'id'      => $c['id'],
            'name'    => $name,
            'email'   => $c['email_address'] ?? '',
            'phone'   => $c['phone_number'] ?? '',
            'created' => $c['created_at'] ?? '',
            'subscription' => $sub ? [
                'id'     => $sub['id'],
                'status' => strtolower($sub['status']),
                'amount' => isset($sub['price_override_money']['amount'])
                              ? $sub['price_override_money']['amount'] / 100
                              : null,
                'currency' => $sub['price_override_money']['currency'] ?? 'AUD',
                'charged_through' => $sub['charged_through_date'] ?? null,
            ] : null,
            'household' => $linkedBySquare[$c['id']] ?? null,
        ];
    }

    echo json_encode(['configured' => true, 'customers' => $out]);
}));

// ── POST /api/square/link — link a Square customer to a household ─────────────
$router->post('/api/square/link', $protected('manage_system', function () {
    $body        = json_decode(file_get_contents('php://input'), true) ?: [];
    $squareId    = trim($body['square_customer_id'] ?? '');
    $householdId = (int)($body['household_id'] ?? 0);
    if (!$squareId || !$householdId) {
        http_response_code(400); echo json_encode(['error' => 'Missing parameters']); return;
    }
    $db = Database::connect();
    $db->prepare("UPDATE households SET square_customer_id = NULL WHERE square_customer_id = ?")->execute([$squareId]);
    $db->prepare("UPDATE households SET square_customer_id = ? WHERE id = ?")->execute([$squareId, $householdId]);
    echo json_encode(['success' => true]);
}));

// ── POST /api/square/unlink — remove Square link from a household ─────────────
$router->post('/api/square/unlink', $protected('manage_system', function () use ($requireId) {
    $id = $requireId(); if ($id === null) return;
    Database::connect()->prepare("UPDATE households SET square_customer_id = NULL WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
}));

// ── GET /api/square/charges — completed payments for a Square customer ────────
$router->get('/api/square/charges', $protected('manage_system', function () {
    $customerId = trim($_GET['customer_id'] ?? '');
    if (!$customerId) {
        http_response_code(400); echo json_encode(['error' => 'Missing customer_id']); return;
    }

    $result = squareApi('GET', '/payments', ['customer_id' => $customerId, 'limit' => 50, 'sort_order' => 'DESC']);
    if (isset($result['__error'])) {
        http_response_code(500); echo json_encode(['error' => $result['__error']]); return;
    }

    $db = Database::connect();
    $imported = $db->query("SELECT square_payment_id FROM payments WHERE square_payment_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
    $importedSet = array_flip($imported);

    $charges = [];
    foreach (($result['payments'] ?? []) as $p) {
        if ($p['status'] !== 'COMPLETED') continue;
        $charges[] = [
            'id'       => $p['id'],
            'amount'   => ($p['amount_money']['amount'] ?? 0) / 100,
            'currency' => strtoupper($p['amount_money']['currency'] ?? 'AUD'),
            'note'     => $p['note'] ?? '',
            'created'  => $p['created_at'] ?? '',
            'imported' => isset($importedSet[$p['id']]),
        ];
    }

    echo json_encode(['charges' => $charges]);
}));

// ── POST /api/square/import-charge — record a Square payment as a CampOffice payment ──
$router->post('/api/square/import-charge', $protected('manage_system', function () {
    $body        = json_decode(file_get_contents('php://input'), true) ?: [];
    $householdId = (int)($body['household_id'] ?? 0);
    $campId      = (int)($body['camp_id'] ?? 0);
    $squareId    = trim($body['square_payment_id'] ?? '');
    $amount      = (float)($body['amount'] ?? 0);
    $note        = trim($body['note'] ?? '');
    $createdAt   = trim($body['created'] ?? '');
    $paymentDate = $createdAt ? date('Y-m-d H:i:s', strtotime($createdAt)) : date('Y-m-d H:i:s');

    if (!$householdId || !$campId || !$squareId) {
        http_response_code(400); echo json_encode(['error' => 'Missing required fields']); return;
    }

    $db    = Database::connect();
    $check = $db->prepare("SELECT id FROM payments WHERE square_payment_id = ?");
    $check->execute([$squareId]);
    if ($check->fetch()) {
        http_response_code(409); echo json_encode(['error' => 'Already imported']); return;
    }

    $notes = $note ? "Square: $note" : "Imported from Square";
    $db->prepare("INSERT INTO payments
        (household_id, camp_id, payment_date, camp_fee, site_fee, prepaid_applied, other_amount, total, notes, tender_square, square_payment_id)
        VALUES (?,?,?,0,0,0,?,?,?,?,?)")
       ->execute([$householdId, $campId, $paymentDate, $amount, $amount, $notes, $amount, $squareId]);

    echo json_encode(['success' => true, 'id' => (int)$db->lastInsertId()]);
}));

// ── GET /api/household/payment-history (override: adds tender_square) ────────
$router->get('/api/household/payment-history', $protected('access_operations', function () {
    $householdId = isset($_GET['household_id']) ? (int)$_GET['household_id'] : 0;
    if ($householdId <= 0) { http_response_code(400); echo json_encode(['message' => 'household_id required']); return; }

    $db   = Database::connect();
    $stmt = $db->prepare("
        SELECT p.id, p.payment_date, p.camp_fee, p.site_fee, p.other_amount,
               p.prepaid_applied, p.total, p.headcount, p.notes,
               p.tender_eftpos, p.tender_cash, p.tender_bank, p.tender_square,
               p.square_payment_id,
               p.arrival_date, p.departure_date,
               c.name AS camp_name, c.year AS camp_year
        FROM payments p JOIN camps c ON c.id = p.camp_id
        WHERE p.household_id = ?
        ORDER BY c.start_date DESC, p.payment_date DESC
    ");
    $stmt->execute([$householdId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        foreach (['camp_fee','site_fee','other_amount','prepaid_applied','total',
                  'tender_eftpos','tender_cash','tender_bank','tender_square'] as $f) {
            $r[$f] = (float)$r[$f];
        }
        $r['headcount'] = $r['headcount'] !== null ? (int)$r['headcount'] : null;
    }
    unset($r);
    echo json_encode(['payments' => $rows, 'household_id' => $householdId]);
}));

$router->dispatch();
