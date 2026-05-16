<?php

require_once __DIR__ . '/Database.php';

class Auth {
    private static $currentUserLoaded = false;
    private static $currentUser = null;
    private static $legacyRoleSchema = null;

    public static function login($username, $password) {
        $username = trim((string)$username);
        $password = (string)$password;
        if ($username === '' || $password === '') {
            return false;
        }

        $db = Database::connect();
        $stmt = $db->prepare("SELECT id, username, password_hash, role FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = self::normalizeRole($user['role']);

        self::resetCurrentUser();
        self::user();

        return true;
    }

    public static function logout() {
        self::resetCurrentUser();
        self::$legacyRoleSchema = null;

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function check() {
        return self::user() !== null;
    }

    public static function isAdmin() {
        return self::can('access_operations');
    }

    public static function user() {
        return self::loadCurrentUser();
    }

    public static function requireLogin() {
        if (self::check()) {
            return true;
        }

        self::sendJsonError(401, 'Unauthorized');
        return false;
    }

    public static function requireCapability($capability) {
        $user = self::user();
        if (!$user) {
            self::sendJsonError(401, 'Unauthorized');
            return false;
        }

        if (!self::can($capability, $user)) {
            self::sendJsonError(403, 'You do not have permission to access this area.');
            return false;
        }

        return true;
    }

    public static function can($capability, $user = null) {
        if (!$capability) {
            return true;
        }

        if ($user === null) {
            $user = self::user();
        }

        if (!$user) {
            return false;
        }

        return in_array($capability, $user['capabilities'] ?? [], true);
    }

    public static function roleOptions() {
        return [
            [
                'value' => 'intranet_admin',
                'label' => 'Intranet Admin',
                'description' => 'Only the intranet admin page and related moderation tools.'
            ],
            [
                'value' => 'admin',
                'label' => 'Admin',
                'description' => 'All operational Campo pages except user management and system tools.'
            ],
            [
                'value' => 'full_admin',
                'label' => 'Full Admin',
                'description' => 'Full access to Campo, including settings, users, and system tools.'
            ]
        ];
    }

    public static function validRoles() {
        return array_map(function ($role) {
            return $role['value'];
        }, self::roleOptions());
    }

    public static function roleLabel($role) {
        $normalized = self::normalizeRole($role);
        foreach (self::roleOptions() as $option) {
            if ($option['value'] === $normalized) {
                return $option['label'];
            }
        }
        return 'Unknown';
    }

    public static function normalizeRole($role) {
        $role = strtolower(trim((string)$role));
        if ($role === '') {
            return null;
        }

        if ($role === 'staff') {
            return 'admin';
        }

        if ($role === 'admin') {
            return self::isLegacyRoleSchema() ? 'full_admin' : 'admin';
        }

        if (in_array($role, self::validRoles(), true)) {
            return $role;
        }

        return null;
    }

    public static function defaultPageForRole($role) {
        $role = self::normalizeRole($role);
        if ($role === 'intranet_admin') {
            return '/intranet-admin';
        }
        return '/dashboard';
    }

    public static function allowedPagesForRole($role) {
        $role = self::normalizeRole($role);
        $pages = [
            'intranet_admin' => ['/intranet-admin'],
            'admin' => ['/dashboard', '/members', '/sites', '/payments', '/payment-records', '/camps', '/rates', '/prepayments', '/import', '/map', '/intranet-admin'],
            'full_admin' => ['/dashboard', '/members', '/sites', '/payments', '/payment-records', '/camps', '/rates', '/prepayments', '/import', '/map', '/intranet-admin', '/settings']
        ];
        return $pages[$role] ?? [];
    }

    public static function capabilitiesForRole($role) {
        $role = self::normalizeRole($role);
        $map = [
            'intranet_admin' => ['access_intranet'],
            'admin' => ['access_operations', 'access_intranet'],
            'full_admin' => ['access_operations', 'access_intranet', 'manage_users', 'manage_system']
        ];
        return $map[$role] ?? [];
    }

    public static function isLegacyRoleSchema() {
        if (self::$legacyRoleSchema !== null) {
            return self::$legacyRoleSchema;
        }

        try {
            $db = Database::connect();
            $stmt = $db->query("SHOW COLUMNS FROM `users` LIKE 'role'");
            $row = $stmt ? $stmt->fetch() : null;
            $type = strtolower((string)($row['Type'] ?? $row['type'] ?? ''));
            self::$legacyRoleSchema = $type !== '' && strpos($type, 'enum(') === 0;
        } catch (Throwable $e) {
            self::$legacyRoleSchema = false;
        }

        return self::$legacyRoleSchema;
    }

    public static function resetCurrentUser() {
        self::$currentUserLoaded = false;
        self::$currentUser = null;
    }

    private static function loadCurrentUser() {
        if (self::$currentUserLoaded) {
            return self::$currentUser;
        }

        self::$currentUserLoaded = true;
        self::$currentUser = null;

        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        if ($userId <= 0) {
            return null;
        }

        try {
            $db = Database::connect();
            $stmt = $db->prepare("SELECT id, username, role FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
        } catch (Throwable $e) {
            $row = null;
        }

        if (!$row) {
            self::logout();
            return null;
        }

        $role = self::normalizeRole($row['role']);
        if (!$role) {
            self::logout();
            return null;
        }

        $_SESSION['user_id'] = (int)$row['id'];
        $_SESSION['username'] = $row['username'];
        $_SESSION['role'] = $role;

        self::$currentUser = [
            'id' => (int)$row['id'],
            'username' => $row['username'],
            'role' => $role,
            'role_label' => self::roleLabel($role),
            'capabilities' => self::capabilitiesForRole($role),
            'allowed_pages' => self::allowedPagesForRole($role),
            'default_page' => self::defaultPageForRole($role)
        ];

        return self::$currentUser;
    }

    private static function sendJsonError($status, $message) {
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
    }
}
